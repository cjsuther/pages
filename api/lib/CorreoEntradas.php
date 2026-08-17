<?php

/**
 * Arma y manda la entrada al comprador.
 *
 * Se manda cuando la orden queda pagada: en el aviso de Mercado Pago, o en el
 * acto si la reserva era sin costo.
 *
 * El envío no puede voltear la compra. Si el correo falla, la orden sigue
 * pagada y se anota el motivo: la entrada existe igual y se puede reintentar,
 * mientras que perder el cobro por un problema de SMTP no tendría arreglo.
 */
class CorreoEntradas
{
    /** Reintentos antes de dejar de insistir con una dirección. */
    const MAX_INTENTOS = 3;

    /**
     * Manda la entrada de una orden.
     *
     * @return array{enviado: bool, motivo: string}
     */
    public static function enviar($db, $codigo, $mailer = null)
    {
        $orden = Entradas::orden($db, $codigo);

        if ($orden === null) {
            return ['enviado' => false, 'motivo' => 'orden inexistente'];
        }

        if ($orden['estado'] !== 'pagada') {
            return ['enviado' => false, 'motivo' => 'la orden no está pagada'];
        }

        // Mandar dos veces la misma entrada hace dudar de cuál vale.
        if (!empty($orden['mail_enviado_en'])) {
            return ['enviado' => false, 'motivo' => 'ya se había enviado'];
        }

        if ((int) $orden['mail_intentos'] >= self::MAX_INTENTOS) {
            return ['enviado' => false, 'motivo' => 'se agotaron los reintentos'];
        }

        $mailer = $mailer === null ? new Mailer() : $mailer;

        $qr = CodigoQR::png(CodigoQR::urlDeLaOrden($orden['codigo']));

        $mensaje = [
            'para'       => $orden['email'],
            'paraNombre' => $orden['nombre'],
            'asunto'     => 'Tu entrada para ' . $orden['evento'],
            'html'       => self::html($orden, $qr !== null),
            'texto'      => self::texto($orden),
            'imagenes'   => $qr === null ? [] : [['cid' => 'qr', 'contenido' => $qr, 'tipo' => 'image/png']],
        ];

        // El mail sale de la casilla de la plataforma, porque es la que el SPF
        // del dominio autoriza. El Reply-To es lo que hace que "responder"
        // llegue a quien organiza y no a nosotros, que no sabemos nada del
        // evento. Sin contacto cargado no se pone ninguno: un Reply-To a una
        // casilla que nadie lee es peor que no ofrecer responder.
        $contacto = self::contacto($orden);

        if ($contacto !== null) {
            $mensaje['responder'] = $contacto;
        }

        $resultado = $mailer->enviar($mensaje);

        self::anotar($db, $orden['id'], $resultado);

        return $resultado['ok']
            ? ['enviado' => true, 'motivo' => 'entrada enviada']
            : ['enviado' => false, 'motivo' => $resultado['error']];
    }

    /**
     * Órdenes pagadas a las que todavía no les salió la entrada.
     *
     * El envío se intenta en el momento del pago, pero si el SMTP estaba caído
     * la persona se quedaría sin su entrada para siempre. Esto lo levanta el
     * cron y lo reintenta.
     */
    public static function pendientes($db, $limite = 50)
    {
        $stmt = $db->prepare("
            SELECT codigo
            FROM ticket_orders
            WHERE estado = 'pagada'
              AND mail_enviado_en IS NULL
              AND mail_intentos < ?
            ORDER BY id
            LIMIT " . (int) $limite);
        $stmt->execute([self::MAX_INTENTOS]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'codigo');
    }

    /** Reintenta todo lo pendiente. Lo llama el cron. */
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
                UPDATE ticket_orders
                SET mail_enviado_en = NOW(), mail_intentos = mail_intentos + 1, mail_error = NULL
                WHERE id = ?');
            $stmt->execute([(int) $ordenId]);

            return;
        }

        $stmt = $db->prepare('
            UPDATE ticket_orders
            SET mail_intentos = mail_intentos + 1, mail_error = ?
            WHERE id = ?');
        $stmt->execute([$resultado['error'], (int) $ordenId]);
    }

    /** Fecha del evento en castellano, o null si el evento no tiene fecha. */
    public static function fechaLegible($fecha, $hora = null)
    {
        if (empty($fecha) || $fecha === '0000-00-00') {
            return null;
        }

        $dias = ['Sunday' => 'domingo', 'Monday' => 'lunes', 'Tuesday' => 'martes',
                 'Wednesday' => 'miércoles', 'Thursday' => 'jueves', 'Friday' => 'viernes',
                 'Saturday' => 'sábado'];
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $momento = strtotime($fecha);
        $texto = $dias[date('l', $momento)] . ' ' . date('j', $momento)
               . ' de ' . $meses[(int) date('n', $momento)];

        return $hora ? $texto . ' a las ' . substr($hora, 0, 5) : $texto;
    }

    private static function html(array $orden, $conQr)
    {
        $e = function ($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); };

        $fecha = self::fechaLegible($orden['event_date'], $orden['event_time']);
        $url = CodigoQR::urlDeLaOrden($orden['codigo']);
        $cantidad = (int) $orden['cantidad'];

        $bloqueQr = $conQr
            ? '<img src="cid:qr" alt="Código QR de tu entrada" width="200" height="200"
                    style="display:block;margin:0 auto 16px;border:8px solid #ffffff;border-radius:4px">'
            : '';

        $bloqueFecha = $fecha
            ? '<p style="margin:0 0 8px;color:#444444;font-size:15px">📅 ' . $e($fecha) . '</p>'
            : '';

        $bloqueLugar = empty($orden['event_address'])
            ? ''
            : '<p style="margin:0 0 8px;color:#444444;font-size:15px">📍 ' . $e($orden['event_address']) . '</p>';

        // Sólo se invita a responder cuando hay a quién: el mail sale de una
        // casilla de la plataforma, así que sin Reply-To la respuesta no llega
        // a quien organiza. Prometerlo igual sería dejar a alguien esperando
        // una contestación que nadie va a leer.
        $bloqueContacto = self::contacto($orden) === null
            ? ''
            : ' Si tenés alguna duda, respondé este mail y le llega a ' . $e($orden['pagina']) . '.';

        // El afiche arriba de todo: es lo que hace reconocer el mail de un
        // vistazo entre veinte. Va por URL y no adjunto —el QR sí va adjunto,
        // porque tiene que verse aunque el cliente bloquee las imágenes— así
        // que si no carga, la entrada sigue completa igual.
        $bloqueImagen = self::imagenValida($orden)
            ? '<img src="' . $e($orden['evento_imagen']) . '" alt=""
                    width="480" style="display:block;width:100%;max-width:480px;height:auto;border:0">'
            : '';

        // Estilos en línea y tabla de una columna: es lo único que renderiza
        // igual en Gmail, Outlook y el cliente de iOS.
        return '<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:480px;margin:0 auto">
  ' . ($bloqueImagen === '' ? '' : '<tr><td style="line-height:0;border-radius:8px 8px 0 0;overflow:hidden">'
        . $bloqueImagen . '</td></tr>') . '

  <tr><td style="background:#000000;padding:24px;text-align:center' . ($bloqueImagen === '' ? ';border-radius:8px 8px 0 0' : '') . '">
    <p style="margin:0;color:#ffffff;font-size:13px;letter-spacing:2px">TU ENTRADA</p>
    <h1 style="margin:8px 0 0;color:#ffffff;font-size:24px;font-weight:800">' . $e($orden['evento']) . '</h1>
  </td></tr>

  <tr><td style="background:#ffffff;padding:24px;text-align:center">
    ' . $bloqueQr . '
    <p style="margin:0 0 4px;color:#888888;font-size:12px;letter-spacing:1px">CÓDIGO</p>
    <p style="margin:0 0 24px;color:#000000;font-size:26px;font-weight:800;letter-spacing:3px;font-family:monospace">'
      . $e($orden['codigo']) . '</p>

    <div style="text-align:left;border-top:1px solid #eeeeee;padding-top:20px">
      ' . $bloqueFecha . $bloqueLugar . '
      <p style="margin:0 0 8px;color:#444444;font-size:15px">🎟 '
        . $cantidad . ($cantidad === 1 ? ' entrada' : ' entradas') . ' a nombre de ' . $e($orden['nombre']) . '</p>
    </div>

    <a href="' . $e($url) . '"
       style="display:inline-block;margin-top:24px;padding:14px 28px;background:#000000;color:#ffffff;
              text-decoration:none;font-weight:700;border-radius:4px">VER MI ENTRADA</a>
  </td></tr>

  <tr><td style="background:#ffffff;padding:0 24px 24px;border-radius:0 0 8px 8px">
    <p style="margin:0;color:#999999;font-size:12px;line-height:1.6;border-top:1px solid #eeeeee;padding-top:16px">
      Mostrá este código en la entrada.' . $bloqueContacto . '
    </p>
  </td></tr>
</table>
</body></html>';
    }

    /**
     * Casilla del organizador, si cargó una válida.
     *
     * Se valida acá y no sólo al guardarla: una dirección rota en el Reply-To
     * puede hacer que el servidor rechace el mensaje entero, y perder la
     * entrada por un contacto mal tipeado sería desproporcionado.
     */
    public static function contacto(array $orden)
    {
        $email = isset($orden['email_contacto']) ? trim((string) $orden['email_contacto']) : '';

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * La imagen del evento sirve para el mail sólo si es una URL absoluta.
     *
     * Los eventos cargados a mano pueden tener una ruta relativa, que en el
     * navegador resuelve contra el sitio y en un cliente de correo no resuelve
     * contra nada: se vería el ícono de imagen rota arriba de la entrada.
     */
    public static function imagenValida(array $orden)
    {
        $url = isset($orden['evento_imagen']) ? trim((string) $orden['evento_imagen']) : '';

        return $url !== '' && preg_match('#^https?://#i', $url) === 1;
    }

    private static function texto(array $orden)
    {
        $fecha = self::fechaLegible($orden['event_date'], $orden['event_time']);
        $cantidad = (int) $orden['cantidad'];

        $lineas = [
            'TU ENTRADA PARA ' . mb_strtoupper($orden['evento']),
            '',
            'Código: ' . $orden['codigo'],
            '',
        ];

        if ($fecha) {
            $lineas[] = 'Cuándo: ' . $fecha;
        }

        if (!empty($orden['event_address'])) {
            $lineas[] = 'Dónde: ' . $orden['event_address'];
        }

        $lineas[] = $cantidad . ($cantidad === 1 ? ' entrada' : ' entradas') . ' a nombre de ' . $orden['nombre'];
        $lineas[] = '';
        $lineas[] = 'Ver tu entrada: ' . CodigoQR::urlDeLaOrden($orden['codigo']);
        $lineas[] = '';
        $lineas[] = 'Mostrá este código en la entrada.';

        $contacto = self::contacto($orden);

        if ($contacto !== null) {
            $lineas[] = 'Dudas: ' . $contacto;
        }

        return implode("\n", $lineas);
    }
}
