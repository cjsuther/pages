<?php

/**
 * El mail que una página les manda a sus clientes (#37): las plantillas, el
 * reemplazo del nombre y el armado del HTML.
 *
 * El cuerpo es texto con saltos de línea. Sale como HTML simple —párrafos y
 * links clickeables— y también como texto plano. Todo se escapa antes de
 * convertir los links y los saltos: el nombre de un cliente lo escribió el
 * cliente, y un "<script>" ahí tiene que llegar como texto.
 */
class MensajeACliente
{
    const VARIABLES = ['{nombre}', '{primer_nombre}'];

    const LARGO_NOMBRE = 120;
    const LARGO_ASUNTO = 255;
    const LARGO_CUERPO = 20000;
    const LARGO_REEMPLAZO = 100;

    /** Sólo http y https: un "javascript:" nunca se vuelve link. */
    const URL = '~(https?://[^\s<>"\']+)~i';

    // -------------------------------------------------------- validación

    /**
     * @param  array $entrada nombre, asunto, cuerpo, reemplazo_nombre
     * @param  bool  $conNombre El nombre interno es obligatorio en las plantillas, no en un envío
     * @return array ['error' => string] o ['datos' => array]
     */
    public static function validar($entrada, $conNombre = true)
    {
        if (!is_array($entrada)) {
            return ['error' => 'Falta el mensaje'];
        }

        $nombre = self::unaLinea(self::texto($entrada, 'nombre'));
        $asunto = self::unaLinea(self::texto($entrada, 'asunto'));
        $cuerpo = trim(str_replace(["\r\n", "\r"], "\n", self::texto($entrada, 'cuerpo')));
        $reemplazo = self::unaLinea(self::texto($entrada, 'reemplazo_nombre'));

        if ($conNombre && $nombre === '') {
            return ['error' => 'Ponele un nombre al mensaje, para encontrarlo después'];
        }

        if (mb_strlen($nombre) > self::LARGO_NOMBRE) {
            return ['error' => 'El nombre del mensaje es demasiado largo'];
        }

        if ($asunto === '') {
            return ['error' => 'Falta el asunto'];
        }

        if (mb_strlen($asunto) > self::LARGO_ASUNTO) {
            return ['error' => 'El asunto es demasiado largo'];
        }

        if ($cuerpo === '') {
            return ['error' => 'Falta el texto del mensaje'];
        }

        if (mb_strlen($cuerpo) > self::LARGO_CUERPO) {
            return ['error' => 'El mensaje es demasiado largo'];
        }

        if (mb_strlen($reemplazo) > self::LARGO_REEMPLAZO) {
            return ['error' => 'El texto para quien no tiene nombre es demasiado largo'];
        }

        return ['datos' => [
            'nombre' => $nombre,
            'asunto' => $asunto,
            'cuerpo' => $cuerpo,
            'reemplazo_nombre' => $reemplazo,
        ]];
    }

    // --------------------------------------------------------- variables

    /**
     * Reemplaza {nombre} y {primer_nombre}.
     *
     * Sin nombre va $reemplazo. Si eso también está vacío, se limpia el
     * espacio que queda colgando: "Hola {primer_nombre}," queda "Hola," y no
     * "Hola ,".
     */
    public static function reemplazar($texto, $nombre, $reemplazo = '')
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) $nombre));

        if ($nombre !== '') {
            $partes = explode(' ', $nombre);
            return str_replace(self::VARIABLES, [$nombre, $partes[0]], (string) $texto);
        }

        $reemplazo = trim((string) $reemplazo);

        if ($reemplazo !== '') {
            return str_replace(self::VARIABLES, [$reemplazo, $reemplazo], (string) $texto);
        }

        $variable = '\{(?:nombre|primer_nombre)\}';
        $texto = (string) $texto;
        // Antes de un signo o del final de la línea, se va con el espacio previo.
        $texto = preg_replace('/[ \t]*' . $variable . '(?=[ \t]*(?:[,.;:!?)]|$))/mu', '', $texto);
        // Entre dos palabras, se va con uno de los dos espacios.
        $texto = preg_replace('/[ \t]+' . $variable . '(?=[ \t])/u', '', $texto);
        // Al principio de una línea, con el espacio que le sigue.
        $texto = preg_replace('/^' . $variable . '[ \t]*/mu', '', $texto);

        return str_replace(self::VARIABLES, '', $texto);
    }

    // ------------------------------------------------------------- armado

    /**
     * El mail para un destinatario.
     *
     * @param array  $mensaje   asunto, cuerpo, reemplazo_nombre (los del envío)
     * @param array  $cliente   email, nombre
     * @param string $pagina    El nombre de la página, para el pie
     * @param string $urlBaja   La página de baja
     * @return array{asunto: string, html: string, texto: string}
     */
    public static function armar(array $mensaje, array $cliente, $pagina, $urlBaja)
    {
        $nombre = isset($cliente['nombre']) ? $cliente['nombre'] : '';
        $reemplazo = isset($mensaje['reemplazo_nombre']) ? $mensaje['reemplazo_nombre'] : '';

        $asunto = self::unaLinea(self::reemplazar($mensaje['asunto'], $nombre, $reemplazo));
        $cuerpo = self::reemplazar($mensaje['cuerpo'], $nombre, $reemplazo);
        $pie = 'No quiero recibir más mails de ' . self::unaLinea($pagina);

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222">'
            . self::html($cuerpo)
            . '<hr style="border:none;border-top:1px solid #ddd;margin:24px 0 12px">'
            . '<p style="font-size:12px;color:#777;margin:0"><a href="' . self::escapar($urlBaja) . '" style="color:#777">'
            . self::escapar($pie) . '</a></p>'
            . '</div>';

        $texto = trim($cuerpo) . "\n\n--\n" . $pie . ': ' . $urlBaja;

        return ['asunto' => $asunto, 'html' => $html, 'texto' => $texto];
    }

    /** Texto con saltos de línea → párrafos, <br> y links. Todo escapado. */
    public static function html($texto)
    {
        $texto = trim(str_replace(["\r\n", "\r"], "\n", (string) $texto));

        if ($texto === '') {
            return '';
        }

        $html = '';

        foreach (preg_split('/\n[ \t]*\n+/', $texto) as $parrafo) {
            $lineas = array_map([self::class, 'linea'], explode("\n", trim($parrafo)));
            $html .= '<p style="margin:0 0 1em">' . implode("<br>\n", $lineas) . '</p>';
        }

        return $html;
    }

    // ----------------------------------------------------------- interno

    private static function linea($linea)
    {
        $partes = preg_split(self::URL, $linea, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html = '';

        foreach ($partes as $i => $parte) {
            if ($i % 2 === 0) {
                $html .= self::escapar($parte);
                continue;
            }

            // El punto o el paréntesis que cierran la oración no son del link.
            preg_match('/^(.*?)([.,;:!?)\]]*)$/s', $parte, $m);
            $html .= '<a href="' . self::escapar($m[1]) . '">' . self::escapar($m[1]) . '</a>' . self::escapar($m[2]);
        }

        return $html;
    }

    private static function escapar($texto)
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    private static function unaLinea($texto)
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', (string) $texto));
    }

    private static function texto(array $entrada, $clave)
    {
        return isset($entrada[$clave]) && is_scalar($entrada[$clave]) ? (string) $entrada[$clave] : '';
    }
}
