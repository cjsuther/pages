<?php

/**
 * El mail que le avisa a quien compró que se le devolvió plata.
 *
 * Sale de la casilla de la plataforma, como la entrada, con Reply-To a quien
 * organiza si cargó un contacto. Dice cuánto, por dónde vuelve la plata y qué
 * pasó con las entradas: si se anuló la compra, que el QR ya no sirve; si se
 * sacaron algunas, con cuántas quedó (el QR sigue siendo el mismo).
 */
class CorreoDevoluciones
{
    /** @return array{enviado: bool, motivo: string} */
    public static function enviar($db, $devolucionId, $mailer = null)
    {
        $stmt = $db->prepare("SELECT * FROM ticket_order_devoluciones WHERE id = ? AND estado = 'aprobada'");
        $stmt->execute([(int) $devolucionId]);
        $devolucion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($devolucion === false) {
            return ['enviado' => false, 'motivo' => 'la devolución no está aprobada'];
        }

        $stmt = $db->prepare('SELECT codigo FROM ticket_orders WHERE id = ?');
        $stmt->execute([(int) $devolucion['order_id']]);
        $codigo = $stmt->fetchColumn();
        $orden = $codigo === false ? null : Entradas::orden($db, $codigo);

        if ($orden === null) {
            return ['enviado' => false, 'motivo' => 'orden inexistente'];
        }

        if (trim((string) $orden['email']) === '') {
            return ['enviado' => false, 'motivo' => 'sin email'];
        }

        $mailer = $mailer === null ? new Mailer() : $mailer;
        $mensaje = [
            'para'       => $orden['email'],
            'paraNombre' => $orden['nombre'],
            'asunto'     => 'Te devolvieron ' . self::pesos($devolucion['monto'], $orden['moneda']) . ' de tu compra para ' . $orden['evento'],
            'html'       => self::html($orden, $devolucion),
            'texto'      => self::texto($orden, $devolucion),
            'imagenes'   => [],
        ];

        $contacto = CorreoEntradas::contacto($orden);

        if ($contacto !== null) {
            $mensaje['responder'] = $contacto;
        }

        $resultado = $mailer->enviar($mensaje);

        return $resultado['ok']
            ? ['enviado' => true, 'motivo' => 'aviso de devolución enviado']
            : ['enviado' => false, 'motivo' => $resultado['error']];
    }

    /** Los párrafos del mail, iguales en HTML y en texto. */
    public static function parrafos(array $orden, array $devolucion)
    {
        $monto = self::pesos($devolucion['monto'], $orden['moneda']);
        $quien = empty($orden['pagina']) ? 'Quien organiza el evento' : $orden['pagina'];
        $cantidad = (int) $orden['cantidad'];

        $parrafos = [
            $quien . ' te devolvió ' . $monto . ' de tu compra ' . $orden['codigo'] . ' para ' . $orden['evento'] . '.',
            'La devolución se hizo por Mercado Pago y vuelve al medio con el que pagaste: a tu cuenta de Mercado Pago '
                . 'en el momento, o a tu tarjeta en el plazo que maneje tu banco (puede aparecer en el resumen siguiente).',
            'El monto es lo que recibió quien organiza por tu compra: las comisiones del servicio de cobro no se reintegran.',
        ];

        if ($orden['estado'] === 'cancelada') {
            $parrafos[] = 'Tu compra quedó anulada: el código y el QR ya no sirven para entrar.';
        } elseif ($cantidad > 0) {
            $parrafos[] = 'Tu compra sigue vigente con ' . $cantidad . ($cantidad === 1 ? ' entrada' : ' entradas')
                . ($orden['items'] ? ' (' . TiposDeEntrada::resumir($orden['items']) . ')' : '')
                . ($orden['lugares'] ? ', lugares ' . Plano::resumir($orden['lugares']) : '')
                . '. El código y el QR son los mismos de antes.';
        }

        return $parrafos;
    }

    private static function html(array $orden, array $devolucion)
    {
        $e = function ($valor) { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); };

        $cuerpo = '';

        foreach (self::parrafos($orden, $devolucion) as $parrafo) {
            $cuerpo .= '<p style="margin:0 0 16px;color:#222222;font-size:15px;line-height:1.5">' . $e($parrafo) . '</p>';
        }

        $contacto = CorreoEntradas::contacto($orden) === null
            ? ''
            : '<p style="margin:0;color:#999999;font-size:12px">Si tenés alguna duda, respondé este mail y le llega a '
              . $e($orden['pagina']) . '.</p>';

        return '<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:480px;margin:0 auto">
  <tr><td style="background:#000000;padding:24px;text-align:center;border-radius:8px 8px 0 0">
    <p style="margin:0;color:#ffffff;font-size:13px;letter-spacing:2px">DEVOLUCIÓN</p>
    <h1 style="margin:8px 0 0;color:#ffffff;font-size:24px;font-weight:800">' . $e(self::pesos($devolucion['monto'], $orden['moneda'])) . '</h1>
  </td></tr>
  <tr><td style="background:#ffffff;padding:24px;border-radius:0 0 8px 8px">
    ' . $cuerpo . $contacto . '
  </td></tr>
</table>
</body></html>';
    }

    private static function texto(array $orden, array $devolucion)
    {
        $lineas = ['DEVOLUCIÓN DE TU COMPRA PARA ' . mb_strtoupper($orden['evento']), ''];

        foreach (self::parrafos($orden, $devolucion) as $parrafo) {
            $lineas[] = $parrafo;
            $lineas[] = '';
        }

        $contacto = CorreoEntradas::contacto($orden);

        if ($contacto !== null) {
            $lineas[] = 'Dudas: ' . $contacto;
        }

        return implode("\n", $lineas);
    }

    public static function pesos($monto, $moneda = 'ARS')
    {
        return ($moneda === 'ARS' || $moneda === null ? '$' : $moneda . ' ') . number_format((float) $monto, 2, ',', '.');
    }
}
