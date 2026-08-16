<?php

namespace Tests\Unit\Lib;

use PushSender;
use PHPUnit\Framework\TestCase;

class PushSenderTest extends TestCase
{
    /**
     * El error más caro de la guía (§2.1): FCM acepta cualquier `sub` y APNs
     * responde 403 sin explicar por qué. El síntoma es que Android funciona,
     * se sale a producción y ningún iPhone recibe nada.
     *
     * @dataProvider subjectsValidos
     */
    public function testAceptaSubjectsValidos($subject)
    {
        $this->assertTrue(PushSender::subjectValido($subject));
    }

    public function subjectsValidos()
    {
        return [
            'https' => ['https://rezon.ar'],
            'https con ruta' => ['https://rezon.ar/push'],
            'https con subdominio' => ['https://api.rezon.ar'],
            'mailto con dominio real' => ['mailto:soporte@rezon.ar'],
        ];
    }

    /**
     * @dataProvider subjectsInvalidos
     */
    public function testRechazaSubjectsInvalidos($subject)
    {
        $this->assertFalse(PushSender::subjectValido($subject));
    }

    public function subjectsInvalidos()
    {
        return [
            'vacío' => [''],
            'null' => [null],
            'sin esquema' => ['rezon.ar'],
            'sólo un nombre' => ['miapp'],
            'http sin TLS' => ['http://rezon.ar'],
            'mailto sin dominio' => ['mailto:push'],
            'mailto local' => ['mailto:push@miapp.local'],
            'mailto localhost' => ['mailto:admin@localhost'],
            'dominio de ejemplo' => ['mailto:tu-email@ejemplo.com'],
            'example.com' => ['mailto:dev@example.com'],
            'dominio .test' => ['mailto:dev@rezon.test'],
        ];
    }

    public function testDisponibleExigeLibreriaYGmp()
    {
        // En el entorno de tests ambas están; el valor debe ser booleano y
        // coherente con lo que hay instalado.
        $esperado = class_exists('Minishlink\WebPush\WebPush') && extension_loaded('gmp');

        $this->assertSame($esperado, PushSender::disponible());
    }
}
