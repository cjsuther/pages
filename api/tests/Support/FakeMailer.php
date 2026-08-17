<?php

namespace Tests\Support;

use Mailer;

/**
 * Doble de Mailer: registra lo que se mandó y permite simular fallos, para
 * testear el envío de entradas sin salir a un servidor SMTP.
 */
class FakeMailer extends Mailer
{
    /** @var array Mensajes que se intentaron mandar */
    public $enviados = [];

    /** @var string|null Motivo con el que falla, o null si todo sale bien */
    private $falla = null;

    public function fallarCon($motivo)
    {
        $this->falla = $motivo;
        return $this;
    }

    public function enviar(array $mensaje)
    {
        $this->enviados[] = $mensaje;

        return $this->falla === null
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => $this->falla];
    }

    /** El último mensaje mandado a una dirección. */
    public function mensajePara($email)
    {
        foreach (array_reverse($this->enviados) as $mensaje) {
            if ($mensaje['para'] === $email) {
                return $mensaje;
            }
        }

        return null;
    }
}
