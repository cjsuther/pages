<?php

/**
 * HTML de un editor simple, limpio para mostrarlo en una página pública.
 *
 * El editor sólo ofrece negrita, cursiva, listas y links, pero lo que llega al
 * servidor lo puede mandar cualquiera: se deja pasar una lista cerrada de
 * etiquetas, sin atributos salvo el href de los links, y todo lo demás se
 * saca conservando el texto. Un <script> o un onclick no sobreviven.
 */
class HtmlSimple
{
    /** Etiquetas que se conservan, y en qué se convierten. */
    const ETIQUETAS = [
        'p' => 'p', 'div' => 'p', 'br' => 'br',
        'strong' => 'strong', 'b' => 'strong',
        'em' => 'em', 'i' => 'em', 'u' => 'u',
        'ul' => 'ul', 'ol' => 'ol', 'li' => 'li',
        'a' => 'a',
    ];

    /** Etiquetas que se borran con todo su contenido: no es texto para el público. */
    const DESCARTAR = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'head', 'title', 'noscript', 'svg', 'math'];

    const LARGO_MAXIMO = 20000;

    /** HTML limpio, o null si no queda texto. */
    public static function limpiar($html)
    {
        $html = trim((string) $html);

        if ($html === '') {
            return null;
        }

        $html = mb_substr($html, 0, self::LARGO_MAXIMO);

        $origen = new DOMDocument('1.0', 'UTF-8');
        $previo = libxml_use_internal_errors(true);
        // El prefijo xml le dice a libxml que es UTF-8; sin eso lee Latin-1.
        $origen->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        $raiz = $origen->getElementsByTagName('div')->item(0);
        $destino = new DOMDocument('1.0', 'UTF-8');
        $contenedor = $destino->createElement('div');
        $destino->appendChild($contenedor);

        if ($raiz !== null) {
            self::copiarHijos($raiz, $contenedor, $destino);
        }

        $salida = '';
        foreach ($contenedor->childNodes as $nodo) {
            $salida .= $destino->saveHTML($nodo);
        }

        $salida = trim($salida);

        // Sin texto visible (párrafos vacíos que deja el editor) es no tener descripción.
        if (trim(html_entity_decode(strip_tags($salida), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0") === '') {
            return null;
        }

        return $salida;
    }

    private static function copiarHijos(DOMNode $desde, DOMNode $hacia, DOMDocument $doc)
    {
        foreach ($desde->childNodes as $hijo) {
            if ($hijo instanceof DOMText) {
                $hacia->appendChild($doc->createTextNode($hijo->nodeValue));
                continue;
            }

            if (!$hijo instanceof DOMElement) {
                continue; // comentarios, instrucciones de proceso
            }

            $nombre = strtolower($hijo->nodeName);

            if (in_array($nombre, self::DESCARTAR, true)) {
                continue;
            }

            if (!isset(self::ETIQUETAS[$nombre])) {
                // Una etiqueta que no está permitida se saca, pero su texto queda.
                self::copiarHijos($hijo, $hacia, $doc);
                continue;
            }

            $nuevo = $doc->createElement(self::ETIQUETAS[$nombre]);

            if ($nombre === 'a') {
                $href = self::hrefSeguro($hijo->getAttribute('href'));

                if ($href === null) {
                    self::copiarHijos($hijo, $hacia, $doc);
                    continue;
                }

                $nuevo->setAttribute('href', $href);
                $nuevo->setAttribute('target', '_blank');
                $nuevo->setAttribute('rel', 'noopener noreferrer nofollow');
            }

            $hacia->appendChild($nuevo);

            if ($nombre !== 'br') {
                self::copiarHijos($hijo, $nuevo, $doc);
            }
        }
    }

    /** Sólo links web o de mail; un javascript: o un data: se descartan. */
    private static function hrefSeguro($href)
    {
        $href = trim((string) $href);

        if ($href === '') {
            return null;
        }

        // Sin esquema se asume web: es lo que escribe la gente ("instagram.com/...").
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            $href = 'https://' . ltrim($href, '/');
        }

        if (!preg_match('#^(https?://|mailto:)#i', $href)) {
            return null;
        }

        return filter_var($href, FILTER_SANITIZE_URL);
    }
}
