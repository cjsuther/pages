<?php

/**
 * Cliente HTTP mínimo sobre cURL.
 *
 * Existe para que los handlers reciban las llamadas salientes por parámetro y
 * los tests puedan sustituirlas por un doble, en vez de hablar con Google o
 * Apple de verdad.
 */
class HttpClient
{
    /**
     * Segundos máximos de una llamada entera y de la conexión.
     *
     * Sin tope, una API que no contesta deja colgado el pedido del usuario (y
     * el proceso de PHP) hasta que lo corte el servidor web, y no se sabe qué
     * pasó. Con tope, la llamada vuelve con status 0: quien llama lo trata
     * como "no sé si llegó". Para un reembolso eso importa: ver Devoluciones.
     */
    const TIMEOUT = 30;
    const TIMEOUT_CONEXION = 10;

    /**
     * @return array{status: int, body: string}
     */
    public function post($url, array $fields)
    {
        $ch = curl_init($url);
        self::conTopes($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => (int) $status, 'body' => $body === false ? '' : $body];
    }

    /**
     * POST con cuerpo JSON. Mercado Pago no acepta formularios.
     *
     * @param array|object $body Un objeto vacío (new stdClass()) viaja como {}.
     * @return array{status: int, body: string}
     */
    public function postJson($url, $body, array $headers = [])
    {
        $ch = curl_init($url);
        self::conTopes($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, self::json($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json'], $headers));

        $respuesta = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => (int) $status, 'body' => $respuesta === false ? '' : $respuesta];
    }

    /**
     * @return array{status: int, body: string}
     */
    public function get($url, array $headers = [])
    {
        $ch = curl_init($url);
        self::conTopes($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => (int) $status, 'body' => $body === false ? '' : $body];
    }

    /**
     * El cuerpo JSON tal cual viaja.
     *
     * json_encode([]) da "[]", que no es un objeto: para mandar {} hay que
     * pasar un objeto vacío. Los arrays no vacíos con claves salen como objeto.
     */
    public static function json($body)
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    private static function conTopes($ch)
    {
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_CONEXION);
    }
}
