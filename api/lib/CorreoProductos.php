<?php

/**
 * Manda el link de descarga de un producto digital.
 *
 * Es el hermano de CorreoEntradas: sale cuando la orden queda pagada —en el
 * aviso de Mercado Pago, o en el acto si el producto es gratis— y el envío
 * nunca voltea la compra. Si el correo falla, la orden sigue pagada, se anota
 * el motivo y el cron lo reintenta.
 *
 * El link lleva el token después del #: es personal, y el mail lo dice.
 */
class CorreoProductos
{
    const MAX_INTENTOS = CorreoEntradas::MAX_INTENTOS;

    /**
     * Cada cuánto se puede reenviar el mail de un producto gratis.
     *
     * El reenvío lo dispara cualquiera que escriba un email en el formulario:
     * sin un mínimo entre envíos, el formulario serviría para llenarle la
     * casilla a otra persona.
     */
    const MINUTOS_ENTRE_REENVIOS = 2;

    /**
     * Manda el link de una orden pagada.
     *
     * Con $reenviar se manda aunque ya haya salido: es el pedido repetido de
     * un producto gratis, que recibe otra vez el link que ya tiene.
     *
     * @return array{enviado: bool, motivo: string}
     */
    public static function enviar($db, $codigo, $mailer = null, $reenviar = false)
    {
        return self::mandar($db, $codigo, $mailer, $reenviar, false);
    }

    /**
     * El botón "Reenviar link" del panel de ventas.
     *
     * A diferencia del reenvío público, no mira MAX_INTENTOS: es justo para
     * cuando el cron se cansó de reintentar y quien compró nunca recibió el
     * link. Lo puede apretar sólo quien administra la página, con sesión. El
     * mínimo entre reenvíos sigue valiendo si el mail ya había salido.
     *
     * @return array{enviado: bool, motivo: string}
     */
    public static function reenviarPorElOrganizador($db, $codigo, $mailer = null)
    {
        return self::mandar($db, $codigo, $mailer, true, true);
    }

    private static function mandar($db, $codigo, $mailer, $reenviar, $porElOrganizador)
    {
        $orden = OrdenesDeProductos::orden($db, $codigo);

        if ($orden === null) {
            return ['enviado' => false, 'motivo' => 'orden inexistente'];
        }

        if ($orden['estado'] !== 'pagada') {
            return ['enviado' => false, 'motivo' => 'la orden no está pagada'];
        }

        if (!empty($orden['mail_enviado_en'])) {
            if (!$reenviar) {
                return ['enviado' => false, 'motivo' => 'ya se había enviado'];
            }

            if (strtotime($orden['mail_enviado_en']) > time() - self::MINUTOS_ENTRE_REENVIOS * 60) {
                return ['enviado' => false, 'motivo' => 'se envió hace instantes'];
            }
        } elseif (!$porElOrganizador && (int) $orden['mail_intentos'] >= self::MAX_INTENTOS) {
            return ['enviado' => false, 'motivo' => 'se agotaron los reintentos'];
        }

        $token = OrdenesDeProductos::token($orden);

        if ($token === null) {
            return ['enviado' => false, 'motivo' => 'no se pudo leer el link de descarga'];
        }

        $url = OrdenesDeProductos::urlDeDescarga($token);
        $mailer = $mailer === null ? new Mailer() : $mailer;

        $mensaje = [
            'para'       => $orden['email'],
            'paraNombre' => $orden['nombre'],
            'asunto'     => 'Tu descarga: ' . $orden['producto'],
            'html'       => self::html($orden, $url),
            'texto'      => self::texto($orden, $url),
        ];

        // Igual que en las entradas: el Reply-To hace que "responder" le llegue
        // a quien vende, sólo si cargó una casilla válida.
        $contacto = CorreoEntradas::contacto($orden);

        if ($contacto !== null) {
            $mensaje['responder'] = $contacto;
        }

        $resultado = $mailer->enviar($mensaje);

        self::anotar($db, $orden['id'], $resultado);

        return $resultado['ok']
            ? ['enviado' => true, 'motivo' => 'link enviado']
            : ['enviado' => false, 'motivo' => $resultado['error']];
    }

    /** Órdenes pagadas a las que todavía no les salió el mail. */
    public static function pendientes($db, $limite = 50)
    {
        $stmt = $db->prepare("
            SELECT codigo
            FROM product_orders
            WHERE estado = 'pagada'
              AND mail_enviado_en IS NULL
              AND mail_intentos < ?
            ORDER BY id
            LIMIT " . (int) $limite);
        $stmt->execute([self::MAX_INTENTOS]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'codigo');
    }

    /** Reintenta todo lo pendiente. Lo llama el cron de process-queue.php. */
    public static function enviarPendientes($db, $mailer = null)
    {
        $resumen = ['enviados' => 0, 'fallidos' => 0];

        foreach (self::pendientes($db) as $codigo) {
            $r = self::enviar($db, $codigo, $mailer);
            $resumen[$r['enviado'] ? 'enviados' : 'fallidos']++;
        }

        return $resumen;
    }

    // ---------------------------------------------------------------- armado

    private static function anotar($db, $ordenId, array $resultado)
    {
        if ($resultado['ok']) {
            $stmt = $db->prepare('
                UPDATE product_orders
                SET mail_enviado_en = NOW(), mail_intentos = mail_intentos + 1, mail_error = NULL
                WHERE id = ?');
            $stmt->execute([(int) $ordenId]);

            return;
        }

        $stmt = $db->prepare('
            UPDATE product_orders
            SET mail_intentos = mail_intentos + 1, mail_error = ?
            WHERE id = ?');
        $stmt->execute([$resultado['error'], (int) $ordenId]);
    }

    private static function html(array $orden, $url)
    {
        $e = function ($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); };

        $imagen = isset($orden['producto_imagen']) ? trim((string) $orden['producto_imagen']) : '';

        // Sólo una URL absoluta: una ruta relativa no resuelve en un cliente de
        // correo y se vería una imagen rota arriba del botón.
        $bloqueImagen = preg_match('#^https?://#i', $imagen) === 1
            ? '<tr><td style="line-height:0;border-radius:8px 8px 0 0;overflow:hidden"><img src="' . $e($imagen) . '" alt=""
                    width="480" style="display:block;width:100%;max-width:480px;height:auto;border:0"></td></tr>'
            : '';

        $bloqueArchivo = empty($orden['archivo_nombre'])
            ? ''
            : '<p style="margin:0 0 8px;color:#444444;font-size:15px">📎 ' . $e($orden['archivo_nombre']) . '</p>';

        $bloqueContacto = CorreoEntradas::contacto($orden) === null
            ? ''
            : ' Si tenés alguna duda, respondé este mail y le llega a ' . $e($orden['pagina']) . '.';

        return '<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:480px;margin:0 auto">
  ' . $bloqueImagen . '
  <tr><td style="background:#000000;padding:24px;text-align:center' . ($bloqueImagen === '' ? ';border-radius:8px 8px 0 0' : '') . '">
    <p style="margin:0;color:#ffffff;font-size:13px;letter-spacing:2px">TU DESCARGA</p>
    <h1 style="margin:8px 0 0;color:#ffffff;font-size:24px;font-weight:800">' . $e($orden['producto']) . '</h1>
  </td></tr>

  <tr><td style="background:#ffffff;padding:24px;text-align:center">
    <div style="text-align:left">
      <p style="margin:0 0 8px;color:#444444;font-size:15px">Hola ' . $e($orden['nombre']) . ', ya podés bajar tu compra.</p>
      ' . $bloqueArchivo . '
    </div>

    <a href="' . $e($url) . '"
       style="display:inline-block;margin-top:24px;padding:14px 28px;background:#000000;color:#ffffff;
              text-decoration:none;font-weight:700;border-radius:4px">DESCARGAR</a>
  </td></tr>

  <tr><td style="background:#ffffff;padding:0 24px 24px;border-radius:0 0 8px 8px">
    <p style="margin:0;color:#999999;font-size:12px;line-height:1.6;border-top:1px solid #eeeeee;padding-top:16px">
      Este link es personal: cualquiera que lo tenga puede descargar el archivo, así que no lo compartas.
      Podés usarlo todas las veces que quieras.' . $bloqueContacto . '
    </p>
  </td></tr>
</table>
</body></html>';
    }

    private static function texto(array $orden, $url)
    {
        $lineas = [
            'TU DESCARGA: ' . mb_strtoupper($orden['producto']),
            '',
            'Hola ' . $orden['nombre'] . ', ya podés bajar tu compra.',
        ];

        if (!empty($orden['archivo_nombre'])) {
            $lineas[] = 'Archivo: ' . $orden['archivo_nombre'];
        }

        $lineas[] = '';
        $lineas[] = 'Descargar: ' . $url;
        $lineas[] = '';
        $lineas[] = 'Este link es personal: cualquiera que lo tenga puede descargar el archivo, así que no lo compartas.';
        $lineas[] = 'Podés usarlo todas las veces que quieras.';

        $contacto = CorreoEntradas::contacto($orden);

        if ($contacto !== null) {
            $lineas[] = 'Dudas: ' . $contacto;
        }

        return implode("\n", $lineas);
    }
}
