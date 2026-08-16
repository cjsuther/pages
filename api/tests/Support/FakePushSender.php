<?php

namespace Tests\Support;

use PushSender;

/**
 * Doble de PushSender: registra lo que se encoló y devuelve el resultado que
 * el test declare, sin salir a la red ni firmar claves VAPID.
 */
class FakePushSender extends PushSender
{
    /** @var array Lista de ['suscripcion' => array, 'payload' => array] */
    public $encolados = [];

    /** @var array Resultados que devolverá enviar(), por endpoint */
    private $resultados = [];

    /** @var bool */
    private $exitoPorDefecto = true;

    public function __construct($exitoPorDefecto = true)
    {
        $this->exitoPorDefecto = $exitoPorDefecto;
    }

    /** Declara el resultado del envío a un endpoint. */
    public function resultado($endpoint, $exito, $expirada = false, $motivo = null)
    {
        $this->resultados[$endpoint] = [
            'endpoint' => $endpoint,
            'exito'    => $exito,
            'expirada' => $expirada,
            'motivo'   => $motivo,
        ];
        return $this;
    }

    public function encolar(array $suscripcion, array $payload)
    {
        $this->encolados[] = ['suscripcion' => $suscripcion, 'payload' => $payload];
    }

    public function enviar()
    {
        $salida = [];

        foreach ($this->encolados as $item) {
            $endpoint = $item['suscripcion']['endpoint'];

            $salida[] = isset($this->resultados[$endpoint])
                ? $this->resultados[$endpoint]
                : [
                    'endpoint' => $endpoint,
                    'exito'    => $this->exitoPorDefecto,
                    'expirada' => false,
                    'motivo'   => $this->exitoPorDefecto ? null : 'fallo simulado',
                ];
        }

        return $salida;
    }

    /** Payload con el que se encoló el envío a un endpoint. */
    public function payloadDe($endpoint)
    {
        foreach ($this->encolados as $item) {
            if ($item['suscripcion']['endpoint'] === $endpoint) {
                return $item['payload'];
            }
        }
        return null;
    }
}
