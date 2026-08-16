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

    // ------------------------------------------------------ claveUtilizable

    /** Una P-256 sin comprimir: 65 bytes que arrancan con 0x04, en base64url. */
    private function claveValida($primerByte = "\x04", $largo = 64)
    {
        return rtrim(strtr(base64_encode($primerByte . str_repeat("\x2a", $largo)), '+/', '-_'), '=');
    }

    public function testAceptaUnaClaveConLaFormaCorrecta()
    {
        $this->assertTrue(PushSender::claveUtilizable($this->claveValida()));
    }

    /**
     * Es exactamente lo que rompió en producción: la librería valida el primer
     * byte recién al cifrar, y como cifra toda la tanda junta se llevaba puesta
     * la corrida entera del cron.
     */
    public function testRechazaUnaClaveQueNoArrancaConCeroCuatro()
    {
        $this->assertFalse(PushSender::claveUtilizable($this->claveValida("\x03")));
    }

    public function testRechazaUnaClaveDemasiadoCorta()
    {
        $this->assertFalse(PushSender::claveUtilizable($this->claveValida("\x04", 30)));
    }

    public function testRechazaUnaClaveDemasiadoLarga()
    {
        $this->assertFalse(PushSender::claveUtilizable($this->claveValida("\x04", 90)));
    }

    public function testRechazaTextoQueNoEsBase64()
    {
        $this->assertFalse(PushSender::claveUtilizable('esto no es una clave!!'));
    }

    public function testRechazaLoVacioYLoNulo()
    {
        $this->assertFalse(PushSender::claveUtilizable(''));
        $this->assertFalse(PushSender::claveUtilizable(null));
    }

    /** Los navegadores mandan base64url sin relleno; con "=" también sirve. */
    public function testAceptaBase64UrlConYSinRelleno()
    {
        $sinRelleno = $this->claveValida();

        $this->assertTrue(PushSender::claveUtilizable($sinRelleno));
        $this->assertTrue(PushSender::claveUtilizable($sinRelleno . '='));
    }

    /** La clave real que manda Chrome usa - y _ en lugar de + y /. */
    public function testAceptaElAlfabetoUrlSafe()
    {
        $bytes = "\x04" . str_repeat("\xfb\xff", 32);
        $urlSafe = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        $this->assertStringContainsString('_', $urlSafe, 'la clave de prueba debe ejercitar el alfabeto');
        $this->assertTrue(PushSender::claveUtilizable($urlSafe));
    }

}
