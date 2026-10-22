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

    /** @var array La cuenta SMTP de cada mensaje, en el mismo orden (null = la de la plataforma) */
    public $cuentas = [];

    /** @var string|null Motivo con el que falla, o null si todo sale bien */
    private $falla = null;

    /** @var string|null Clasificación del fallo, como la devuelve Mailer::motivo() */
    private $motivo = null;

    /** @var array email => [error, motivo]: fallos sólo para esas direcciones */
    private $fallosPara = [];

    public function fallarCon($error, $motivo = null)
    {
        $this->falla = $error;
        $this->motivo = $motivo;
        return $this;
    }

    /** Falla sólo con esta dirección; las demás salen bien. */
    public function fallarPara($email, $error, $motivo = null)
    {
        $this->fallosPara[$email] = [$error, $motivo];
        return $this;
    }

    public function enviar(array $mensaje, ?array $cuenta = null)
    {
        $this->enviados[] = $mensaje;
        $this->cuentas[] = $cuenta;

        list($falla, $motivo) = isset($this->fallosPara[$mensaje['para']])
            ? $this->fallosPara[$mensaje['para']]
            : [$this->falla, $this->motivo];

        if ($falla === null) {
            return ['ok' => true, 'error' => null];
        }

        return $motivo === null
            ? ['ok' => false, 'error' => $falla]
            : ['ok' => false, 'error' => $falla, 'motivo' => $motivo];
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
