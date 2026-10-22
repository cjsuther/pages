<?php

namespace Tests\Unit\Lib;

use CuentaSmtp;
use Mailer;
use PHPUnit\Framework\TestCase;

class CuentaSmtpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CuentaSmtp::olvidar();
        CuentaSmtp::usarResolver(function ($host) {
            return ['142.250.0.108'];
        });
    }

    protected function tearDown(): void
    {
        CuentaSmtp::olvidar();
        parent::tearDown();
    }

    private function entrada(array $cambios = [])
    {
        return array_merge([
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'seguridad' => 'tls',
            'usuario' => 'sala@gmail.com',
            'contrasena' => 'abcd efgh ijkl mnop',
            'remitente_email' => 'sala@gmail.com',
            'remitente_nombre' => 'La Sala',
            'responder_a' => '',
        ], $cambios);
    }

    // ----------------------------------------------------------- cifrado

    public function testLoCifradoSeRecuperaIgual()
    {
        $this->assertSame('abcd efgh ijkl mnop', CuentaSmtp::descifrar(CuentaSmtp::cifrar('abcd efgh ijkl mnop')));
    }

    public function testLoCifradoNoContieneLaContrasena()
    {
        $cifrado = CuentaSmtp::cifrar('mi-contrasena-secreta');

        $this->assertStringNotContainsString('mi-contrasena-secreta', $cifrado);
        $this->assertStringNotContainsString(base64_encode('mi-contrasena-secreta'), $cifrado);
        $this->assertStringStartsWith('v1:', $cifrado);
    }

    public function testCifrarDosVecesDaResultadosDistintos()
    {
        $this->assertNotSame(CuentaSmtp::cifrar('igual'), CuentaSmtp::cifrar('igual'));
    }

    public function testUnTextoManipuladoNoSeDescifra()
    {
        $cifrado = CuentaSmtp::cifrar('mi-contrasena-secreta');

        $this->assertNull(CuentaSmtp::descifrar(substr($cifrado, 0, -6) . 'AAAAAA'));
    }

    public function testConOtraClaveNoSeDescifra()
    {
        $cifrado = CuentaSmtp::cifrar('mi-contrasena-secreta');
        CuentaSmtp::usarClave(str_repeat('otra-clave-', 4));

        $this->assertNull(CuentaSmtp::descifrar($cifrado));
    }

    public function testBasuraNoRompe()
    {
        $this->assertNull(CuentaSmtp::descifrar(''));
        $this->assertNull(CuentaSmtp::descifrar('no es nada'));
        $this->assertNull(CuentaSmtp::descifrar('v1:' . base64_encode('corto')));
    }

    public function testDisponibleConLaClaveDeLosTests()
    {
        $this->assertTrue(CuentaSmtp::disponible());
    }

    /** Sin clave no se guarda nada: ni siquiera en claro "por ahora". */
    public function testSinClaveNoEstaDisponibleYNoCifra()
    {
        CuentaSmtp::usarClave(null);

        $this->assertFalse(CuentaSmtp::disponible());
        $this->expectException(\RuntimeException::class);
        CuentaSmtp::cifrar('x');
    }

    /** La del ejemplo está publicada: tenerla es como no tener ninguna. */
    public function testLaClaveDelEjemploNoSirve()
    {
        CuentaSmtp::usarClave('CAMBIA_ESTO_POR_UNA_CLAVE_LARGA_Y_ALEATORIA');

        $this->assertFalse(CuentaSmtp::disponible());
    }

    public function testUnaClaveCortaNoSirve()
    {
        CuentaSmtp::usarClave('corta');

        $this->assertFalse(CuentaSmtp::disponible());
    }

    // -------------------------------------------------------- validación

    public function testUnaCuentaCompletaPasaNormalizada()
    {
        $r = CuentaSmtp::validar($this->entrada(['host' => '  SMTP.Gmail.com ', 'port' => '587']));

        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame('smtp.gmail.com', $r['datos']['host']);
        $this->assertSame(587, $r['datos']['port']);
        $this->assertSame('abcd efgh ijkl mnop', $r['datos']['contrasena']);
        $this->assertNull($r['datos']['responder_a']);
    }

    public function camposInvalidos()
    {
        return [
            'sin servidor'         => [['host' => ''], 'servidor'],
            'servidor con esquema' => [['host' => 'smtp://smtp.gmail.com'], 'servidor'],
            'servidor con puerto'  => [['host' => 'smtp.gmail.com:587'], 'servidor'],
            'servidor sin dominio' => [['host' => 'correo'], 'servidor'],
            'puerto cero'          => [['port' => 0], 'entre 1 y 65535'],
            'puerto enorme'        => [['port' => 70000], 'entre 1 y 65535'],
            'puerto no numérico'   => [['port' => 'abc'], 'entre 1 y 65535'],
            'puerto no de correo'  => [['port' => 3306], '25, 465, 587 o 2525'],
            'seguridad inventada'  => [['seguridad' => 'starttls'], 'seguridad'],
            'sin usuario'          => [['usuario' => ''], 'usuario'],
            'remitente inválido'   => [['remitente_email' => 'no-es-un-email'], 'remitente'],
            'sin remitente'        => [['remitente_email' => ''], 'remitente'],
            'responder inválido'   => [['responder_a' => 'tampoco'], 'Responder a'],
            'sin contraseña nueva' => [['contrasena' => ''], 'contraseña'],
        ];
    }

    /** @dataProvider camposInvalidos */
    public function testRechazaCamposInvalidos(array $cambios, $fragmento)
    {
        $r = CuentaSmtp::validar($this->entrada($cambios));

        $this->assertArrayHasKey('error', $r);
        $this->assertStringContainsString($fragmento, $r['error']);
    }

    public function testLosPuertosDeCorreoPasan()
    {
        foreach ([25, 465, 587, 2525] as $puerto) {
            $this->assertArrayHasKey('datos', CuentaSmtp::validar($this->entrada(['port' => $puerto])), "puerto $puerto");
        }
    }

    public function testLaContrasenaVaciaConservaLaAnterior()
    {
        $r = CuentaSmtp::validar($this->entrada(['contrasena' => '']), ['contrasena_cifrada' => 'v1:algo']);

        $this->assertArrayHasKey('datos', $r);
        $this->assertNull($r['datos']['contrasena']);
    }

    public function testElNombreDelRemitenteNoLlevaSaltosDeLinea()
    {
        $r = CuentaSmtp::validar($this->entrada(['remitente_nombre' => "La Sala\r\nBcc: todos@x.com"]));

        $this->assertStringNotContainsString("\n", $r['datos']['remitente_nombre']);
    }

    public function testUnArrayEnLugarDeTextoNoRompe()
    {
        $r = CuentaSmtp::validar($this->entrada(['host' => ['smtp.gmail.com']]));

        $this->assertArrayHasKey('error', $r);
    }

    // -------------------------------------------------------------- SSRF

    public function hostsInternos()
    {
        return [
            'localhost'            => ['localhost', null],
            'sub.localhost'        => ['smtp.localhost', null],
            'loopback literal'     => ['127.0.0.1', null],
            'privada literal'      => ['192.168.1.10', null],
            'privada 10'           => ['10.0.0.5', null],
            'privada 172'          => ['172.16.3.4', null],
            'link-local literal'   => ['169.254.169.254', null],
            'nombre a loopback'    => ['smtp.trampa.com', ['127.0.0.1']],
            'nombre a privada'     => ['smtp.trampa.com', ['10.1.2.3']],
            'nombre a link-local'  => ['smtp.trampa.com', ['169.254.169.254']],
            'una de varias'        => ['smtp.trampa.com', ['142.250.0.108', '192.168.0.1']],
            'cero'                 => ['0.0.0.0', null],
        ];
    }

    /** @dataProvider hostsInternos */
    public function testRechazaServidoresDeLaRedInterna($host, $ips)
    {
        if ($ips !== null) {
            CuentaSmtp::usarResolver(function () use ($ips) {
                return $ips;
            });
        }

        $r = CuentaSmtp::revisarHost($host);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('red interna', $r['error']);
    }

    public function testUnServidorPublicoPasaYDevuelveLaIpAUsar()
    {
        $r = CuentaSmtp::revisarHost('smtp.gmail.com');

        $this->assertTrue($r['ok']);
        $this->assertSame('142.250.0.108', $r['ip']);
    }

    public function testUnNombreQueNoResuelveSeExplica()
    {
        CuentaSmtp::usarResolver(function () {
            return false;
        });

        $r = CuentaSmtp::revisarHost('smtp.noexiste.com');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No encontramos el servidor smtp.noexiste.com', $r['error']);
    }

    public function testParaMostrarNuncaLlevaLaContrasena()
    {
        $vista = CuentaSmtp::paraMostrar([
            'host' => 'smtp.gmail.com', 'port' => '587', 'seguridad' => 'tls', 'usuario' => 'sala@gmail.com',
            'contrasena_cifrada' => 'v1:xxx', 'remitente_email' => 'sala@gmail.com', 'remitente_nombre' => null,
            'responder_a' => null, 'verificado_en' => null,
        ]);

        $this->assertTrue($vista['tiene_contrasena']);
        $this->assertSame(587, $vista['port']);
        $this->assertArrayNotHasKey('contrasena_cifrada', $vista);
        $this->assertArrayNotHasKey('contrasena', $vista);
    }

    // ------------------------------------------------- errores de Mailer

    public function motivos()
    {
        return [
            'autenticación' => ['SMTP Error: Could not authenticate.', [], 'autenticacion'],
            'gmail 535' => ['SMTP Error: Could not authenticate.', ['SERVER -> CLIENT: 535-5.7.8 Username and Password not accepted'], 'autenticacion'],
            'no conecta' => ['SMTP Error: Could not connect to SMTP host. Failed to connect to server', [], 'conexion'],
            'certificado' => [
                'SMTP Error: Could not connect to SMTP host. Failed to connect to server',
                ['Connection failed. Error #2: stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed'],
                'certificado',
            ],
            'otro' => ['SMTP Error: data not accepted.', [], 'otro'],
        ];
    }

    /** @dataProvider motivos */
    public function testElMailerClasificaElError($error, array $diagnostico, $esperado)
    {
        $this->assertSame($esperado, Mailer::motivo($error, $diagnostico));
    }

    public function testCadaMotivoSeExplicaEnCastellano()
    {
        $this->assertStringContainsString('contraseña de aplicación', CuentaSmtp::explicar('autenticacion', 'x', 'smtp.gmail.com', 587));
        $this->assertStringContainsString('smtp.gmail.com por el puerto 587', CuentaSmtp::explicar('conexion', 'x', 'smtp.gmail.com', 587));
        $this->assertStringContainsString('certificado', CuentaSmtp::explicar('certificado', 'x', 'smtp.gmail.com', 587));
        $this->assertStringContainsString('data not accepted', CuentaSmtp::explicar('otro', 'data not accepted', 'smtp.gmail.com', 587));
    }
}
