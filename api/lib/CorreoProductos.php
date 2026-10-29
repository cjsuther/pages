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
 *
 * Un pedido de un físico (#43) no lleva link de descarga: sale "Recibimos tu
 * pedido" a quien compró y "Pedido nuevo" a quien vende (mandarPedido).
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

        if (ProductosFisicos::esFisico($orden)) {
            return self::mandarPedido($db, $orden, $mailer, $reenviar, $porElOrganizador);
        }

        $motivo = self::motivoParaNoMandar($orden, $reenviar, $porElOrganizador);

        if ($motivo !== null) {
            return ['enviado' => false, 'motivo' => $motivo];
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

    /** Por qué no sale el mail a quien compró, o null si sale. */
    private static function motivoParaNoMandar(array $orden, $reenviar, $porElOrganizador)
    {
        if (!empty($orden['mail_enviado_en'])) {
            if (!$reenviar) {
                return 'ya se había enviado';
            }

            if (strtotime($orden['mail_enviado_en']) > time() - self::MINUTOS_ENTRE_REENVIOS * 60) {
                return 'se envió hace instantes';
            }
        } elseif (!$porElOrganizador && (int) $orden['mail_intentos'] >= self::MAX_INTENTOS) {
            return 'se agotaron los reintentos';
        }

        return null;
    }

    // ------------------------------------------------------- pedidos físicos

    /**
     * Los dos mails de un pedido de un físico (#43), por la cuenta de la
     * plataforma como los digitales:
     *
     * - a quien compró, "Recibimos tu pedido", con las reglas del link de un
     *   digital (una vez, reenvíos con un mínimo, tope de reintentos);
     * - a quien vende, "Pedido nuevo", una sola vez, al email de contacto de
     *   la página o, si no tiene, al de la dueña. Se anota aparte
     *   (aviso_vendedor_en): uno puede salir y el otro no, y el cron reintenta
     *   el que falta.
     *
     * Ninguno lleva token: no hay nada que descargar.
     */
    private static function mandarPedido($db, array $orden, $mailer, $reenviar, $porElOrganizador)
    {
        $mailer = $mailer === null ? new Mailer() : $mailer;
        $envio = PedidosFisicos::envioDe($db, $orden['id']);
        $motivo = self::motivoParaNoMandar($orden, $reenviar, $porElOrganizador);

        if (empty($orden['aviso_vendedor_en']) && (int) $orden['mail_intentos'] < self::MAX_INTENTOS) {
            $aviso = self::avisarAlVendedor($db, $orden, $envio, $mailer);

            // Si al comprador no le sale nada, el intento fallido cuenta igual:
            // así el cron no reintenta para siempre.
            if ($aviso !== null && !$aviso['ok'] && $motivo !== null) {
                self::anotar($db, $orden['id'], $aviso);
            }
        }

        if ($motivo !== null) {
            return ['enviado' => false, 'motivo' => $motivo];
        }

        $mensaje = [
            'para'       => $orden['email'],
            'paraNombre' => $orden['nombre'],
            'asunto'     => 'Recibimos tu pedido: ' . $orden['producto'],
            'html'       => self::htmlDelPedido($orden, $envio),
            'texto'      => self::textoDelPedido($orden, $envio),
        ];

        $contacto = CorreoEntradas::contacto($orden);

        if ($contacto !== null) {
            $mensaje['responder'] = $contacto;
        }

        $resultado = $mailer->enviar($mensaje);

        self::anotar($db, $orden['id'], $resultado);

        return $resultado['ok']
            ? ['enviado' => true, 'motivo' => 'pedido enviado']
            : ['enviado' => false, 'motivo' => $resultado['error']];
    }

    /**
     * El "Pedido nuevo" a quien vende, con lo que necesita para despachar: el
     * detalle, la dirección completa, los horarios y cómo contactar a quien
     * compró. "Responder" le escribe a quien compró.
     *
     * @return array|null el resultado del Mailer, o null si no hay adónde mandarlo
     */
    private static function avisarAlVendedor($db, array $orden, $envio, $mailer)
    {
        $para = self::emailDelVendedor($orden);

        if ($para === null) {
            return null;
        }

        $resultado = $mailer->enviar([
            'para'      => $para,
            'asunto'    => 'Pedido nuevo: ' . $orden['producto'] . ' (' . $orden['codigo'] . ')',
            'html'      => self::htmlDelAviso($orden, $envio),
            'texto'     => self::textoDelAviso($orden, $envio),
            'responder' => $orden['email'],
        ]);

        if ($resultado['ok']) {
            $stmt = $db->prepare('UPDATE product_orders SET aviso_vendedor_en = NOW() WHERE id = ?');
            $stmt->execute([(int) $orden['id']]);
        }

        return $resultado;
    }

    /** El email de contacto de la página o, si no hay, el de su dueña. */
    public static function emailDelVendedor(array $orden)
    {
        $contacto = CorreoEntradas::contacto($orden);

        if ($contacto !== null) {
            return $contacto;
        }

        $duena = isset($orden['duena_email']) ? trim((string) $orden['duena_email']) : '';

        return filter_var($duena, FILTER_VALIDATE_EMAIL) ? $duena : null;
    }

    /**
     * Órdenes pagadas a las que todavía no les salió el mail: el de quien
     * compró o, en un físico, el aviso a quien vende.
     */
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
        $codigos = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'codigo');

        $stmt = $db->prepare("
            SELECT o.codigo
            FROM digital_products dp
            INNER JOIN product_orders o ON o.product_id = dp.id
            WHERE dp.tipo = 'fisico'
              AND o.estado = 'pagada'
              AND o.mail_enviado_en IS NOT NULL
              AND o.aviso_vendedor_en IS NULL
              AND o.mail_intentos < ?
            ORDER BY o.id
            LIMIT " . (int) $limite);
        $stmt->execute([self::MAX_INTENTOS]);

        return array_merge($codigos, array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'codigo'));
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

    // ------------------------------------------------- armado de un pedido

    /** "$ 2.500" o "$ 2.500,50". */
    public static function pesos($monto)
    {
        $monto = round((float) $monto, 2);
        $decimales = floor($monto) == $monto ? 0 : 2;

        return '$ ' . number_format($monto, $decimales, ',', '.');
    }

    /** El link para seguir el pedido. El código no es una credencial: no muestra nada privado. */
    public static function urlDelPedido($codigo)
    {
        return rtrim(FRONTEND_URL, '/') . '/compra/' . $codigo;
    }

    /**
     * El detalle de un pedido como renglones [etiqueta, valor]: producto,
     * opciones, cantidad por precio, envío y total.
     */
    private static function renglonesDelPedido(array $orden)
    {
        $cantidad = (int) $orden['cantidad'];
        $renglones = [['Producto', $orden['producto']]];

        if (!empty($orden['opciones_texto'])) {
            $renglones[] = ['Opciones', $orden['opciones_texto']];
        }

        $renglones[] = ['Cantidad', $cantidad . ' × ' . self::pesos($orden['precio_unitario'])];
        $renglones[] = ['Envío', (float) $orden['costo_envio'] > 0 ? self::pesos($orden['costo_envio']) : 'Incluido'];
        $renglones[] = ['Total', (float) $orden['total'] > 0 ? self::pesos($orden['total']) : 'Gratis'];

        return $renglones;
    }

    /** La dirección completa y los horarios, como renglones. */
    private static function renglonesDeEntrega($envio)
    {
        if ($envio === null) {
            return [];
        }

        $renglones = [['Dirección', PedidosFisicos::describirDireccion($envio)]];

        if (!empty($envio['indicaciones'])) {
            $renglones[] = ['Indicaciones', $envio['indicaciones']];
        }

        $renglones[] = ['Días', PedidosFisicos::describirDias($envio['dias'])];
        $renglones[] = ['Franja', PedidosFisicos::describirFranjas($envio['franjas'])];

        if (!empty($envio['comentario'])) {
            $renglones[] = ['Horario', $envio['comentario']];
        }

        return $renglones;
    }

    private static function tablaHtml(array $renglones)
    {
        $e = function ($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); };
        $filas = '';

        foreach ($renglones as $r) {
            $filas .= '<tr><td style="padding:4px 12px 4px 0;color:#999999;font-size:13px;vertical-align:top;white-space:nowrap">'
                . $e($r[0]) . '</td><td style="padding:4px 0;color:#222222;font-size:15px">' . $e($r[1]) . '</td></tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="text-align:left">'
            . $filas . '</table>';
    }

    private static function textoDeRenglones(array $renglones)
    {
        return array_map(function ($r) { return $r[0] . ': ' . $r[1]; }, $renglones);
    }

    /** El marco de los dos mails de un pedido, el mismo de la descarga. */
    private static function marcoHtml($encabezado, $titulo, $cuerpo)
    {
        $e = function ($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); };

        return '<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:480px;margin:0 auto">
  <tr><td style="background:#000000;padding:24px;text-align:center;border-radius:8px 8px 0 0">
    <p style="margin:0;color:#ffffff;font-size:13px;letter-spacing:2px">' . $e($encabezado) . '</p>
    <h1 style="margin:8px 0 0;color:#ffffff;font-size:24px;font-weight:800">' . $e($titulo) . '</h1>
  </td></tr>
  <tr><td style="background:#ffffff;padding:24px;border-radius:0 0 8px 8px">' . $cuerpo . '</td></tr>
</table>
</body></html>';
    }

    private static function htmlDelPedido(array $orden, $envio)
    {
        $e = function ($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); };
        $url = self::urlDelPedido($orden['codigo']);
        $entrega = self::renglonesDeEntrega($envio);

        $bloqueContacto = CorreoEntradas::contacto($orden) === null
            ? ''
            : ' Si tenés alguna duda, respondé este mail y le llega a ' . $e($orden['pagina']) . '.';

        $cuerpo = '<p style="margin:0 0 16px;color:#444444;font-size:15px">Hola ' . $e($orden['nombre'])
            . ', ya está tu pedido. ' . $e($orden['pagina']) . ' se va a poner en contacto para coordinar la entrega.</p>'
            . self::tablaHtml(self::renglonesDelPedido($orden))
            . (empty($entrega) ? '' : '<p style="margin:20px 0 8px;color:#000000;font-size:13px;font-weight:700;letter-spacing:1px">ENTREGA</p>'
                . self::tablaHtml($entrega))
            . '<p style="text-align:center;margin:24px 0 0"><a href="' . $e($url) . '"
       style="display:inline-block;padding:14px 28px;background:#000000;color:#ffffff;
              text-decoration:none;font-weight:700;border-radius:4px">SEGUIR MI PEDIDO</a></p>'
            . '<p style="margin:24px 0 0;color:#999999;font-size:12px;line-height:1.6;border-top:1px solid #eeeeee;padding-top:16px">'
            . 'Código de pedido: ' . $e($orden['codigo']) . '.' . $bloqueContacto . '</p>';

        return self::marcoHtml('RECIBIMOS TU PEDIDO', $orden['producto'], $cuerpo);
    }

    private static function textoDelPedido(array $orden, $envio)
    {
        $lineas = array_merge(
            [
                'RECIBIMOS TU PEDIDO: ' . mb_strtoupper($orden['producto']),
                '',
                'Hola ' . $orden['nombre'] . ', ya está tu pedido. ' . $orden['pagina']
                    . ' se va a poner en contacto para coordinar la entrega.',
                '',
            ],
            self::textoDeRenglones(self::renglonesDelPedido($orden)),
            [''],
            self::textoDeRenglones(self::renglonesDeEntrega($envio)),
            ['', 'Seguí tu pedido: ' . self::urlDelPedido($orden['codigo']), 'Código de pedido: ' . $orden['codigo']]
        );

        $contacto = CorreoEntradas::contacto($orden);

        if ($contacto !== null) {
            $lineas[] = 'Dudas: ' . $contacto;
        }

        return implode("\n", $lineas);
    }

    /** Quién compró: lo que hace falta para coordinar la entrega. */
    private static function renglonesDelComprador(array $orden)
    {
        return [
            ['Nombre', $orden['nombre']],
            ['Teléfono', (string) $orden['telefono']],
            ['Email', $orden['email']],
        ];
    }

    private static function avisoSinStock(array $orden)
    {
        return empty($orden['sin_stock'])
            ? null
            : 'Atención: cuando se acreditó el pago ya no quedaba stock de lo pedido. El pago está acreditado; '
              . 'coordiná con quien compró cómo seguir.';
    }

    private static function htmlDelAviso(array $orden, $envio)
    {
        $e = function ($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); };
        $sinStock = self::avisoSinStock($orden);
        $titulo = function ($texto) {
            return '<p style="margin:20px 0 8px;color:#000000;font-size:13px;font-weight:700;letter-spacing:1px">' . $texto . '</p>';
        };

        $cuerpo = ($sinStock === null ? '' : '<p style="margin:0 0 16px;padding:12px;background:#fef2f2;color:#991b1b;font-size:14px">'
                . $e($sinStock) . '</p>')
            . '<p style="margin:0 0 16px;color:#444444;font-size:15px">Entró un pedido en ' . $e($orden['pagina'])
            . '. Si respondés este mail, le llega a quien compró.</p>'
            . self::tablaHtml(self::renglonesDelPedido($orden))
            . $titulo('ENTREGA') . self::tablaHtml(self::renglonesDeEntrega($envio))
            . $titulo('QUIÉN COMPRÓ') . self::tablaHtml(self::renglonesDelComprador($orden))
            . '<p style="margin:24px 0 0;color:#999999;font-size:12px;border-top:1px solid #eeeeee;padding-top:16px">'
            . 'Código de pedido: ' . $e($orden['codigo']) . '.</p>';

        return self::marcoHtml('PEDIDO NUEVO', $orden['producto'], $cuerpo);
    }

    private static function textoDelAviso(array $orden, $envio)
    {
        $sinStock = self::avisoSinStock($orden);

        return implode("\n", array_merge(
            ['PEDIDO NUEVO: ' . mb_strtoupper($orden['producto']), ''],
            $sinStock === null ? [] : [$sinStock, ''],
            ['Entró un pedido en ' . $orden['pagina'] . '. Si respondés este mail, le llega a quien compró.', ''],
            self::textoDeRenglones(self::renglonesDelPedido($orden)),
            ['', 'ENTREGA'],
            self::textoDeRenglones(self::renglonesDeEntrega($envio)),
            ['', 'QUIÉN COMPRÓ'],
            self::textoDeRenglones(self::renglonesDelComprador($orden)),
            ['', 'Código de pedido: ' . $orden['codigo']]
        ));
    }
}
