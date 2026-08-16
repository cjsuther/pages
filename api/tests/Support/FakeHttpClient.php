<?php

namespace Tests\Support;

use HttpClient;

/**
 * Doble de HttpClient: devuelve respuestas preparadas y registra las llamadas,
 * para testear los callbacks OAuth sin salir a la red.
 */
class FakeHttpClient extends HttpClient
{
    /** @var array Respuestas encoladas por URL (fragmento) */
    private $respuestas = [];

    /** @var array Log de llamadas: ['method', 'url', 'fields'|'headers'] */
    public $llamadas = [];

    /** Encola la respuesta para la próxima llamada cuya URL contenga $fragmento. */
    public function responde($fragmento, $status, $body)
    {
        $this->respuestas[] = [
            'fragmento' => $fragmento,
            'status' => $status,
            'body' => is_string($body) ? $body : json_encode($body),
            'consumida' => false,
        ];
        return $this;
    }

    public function post($url, array $fields)
    {
        $this->llamadas[] = ['method' => 'POST', 'url' => $url, 'fields' => $fields];
        return $this->respuestaPara($url);
    }

    public function postJson($url, array $body, array $headers = [])
    {
        $this->llamadas[] = ['method' => 'POST', 'url' => $url, 'json' => $body, 'headers' => $headers];
        return $this->respuestaPara($url);
    }

    public function get($url, array $headers = [])
    {
        $this->llamadas[] = ['method' => 'GET', 'url' => $url, 'headers' => $headers];
        return $this->respuestaPara($url);
    }

    /** Cuerpo JSON con el que se llamó a la primera petición que matchea $fragmento. */
    public function jsonDe($fragmento)
    {
        foreach ($this->llamadas as $llamada) {
            if (strpos($llamada['url'], $fragmento) !== false && isset($llamada['json'])) {
                return $llamada['json'];
            }
        }
        return null;
    }

    /** Campos con los que se llamó a la primera petición que matchea $fragmento. */
    public function camposDe($fragmento)
    {
        foreach ($this->llamadas as $llamada) {
            if (strpos($llamada['url'], $fragmento) !== false) {
                return isset($llamada['fields']) ? $llamada['fields'] : [];
            }
        }
        return null;
    }

    public function cabecerasDe($fragmento)
    {
        foreach ($this->llamadas as $llamada) {
            if (strpos($llamada['url'], $fragmento) !== false) {
                return isset($llamada['headers']) ? $llamada['headers'] : [];
            }
        }
        return null;
    }

    public function llamoA($fragmento)
    {
        return $this->camposDe($fragmento) !== null || $this->cabecerasDe($fragmento) !== null;
    }

    private function respuestaPara($url)
    {
        foreach ($this->respuestas as $i => $respuesta) {
            if ($respuesta['consumida']) {
                continue;
            }
            if (strpos($url, $respuesta['fragmento']) !== false) {
                $this->respuestas[$i]['consumida'] = true;
                return ['status' => $respuesta['status'], 'body' => $respuesta['body']];
            }
        }

        // Sin respuesta preparada: se modela una llamada fallida.
        return ['status' => 500, 'body' => ''];
    }
}
