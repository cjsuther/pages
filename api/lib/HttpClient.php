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
     * @return array{status: int, body: string}
     */
    public function post($url, array $fields)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => (int) $status, 'body' => $body === false ? '' : $body];
    }

    /**
     * @return array{status: int, body: string}
     */
    public function get($url, array $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => (int) $status, 'body' => $body === false ? '' : $body];
    }
}
