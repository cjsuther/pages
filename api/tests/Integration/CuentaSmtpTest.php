<?php

namespace Tests\Integration;

use CuentaSmtp;
use CuentaSmtpHandler;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * La cuenta SMTP de una página contra una MariaDB de verdad (#36): que la
 * contraseña vaya y vuelva cifrada por la base, y el límite de pruebas con
 * el reloj del motor.
 */
class CuentaSmtpTest extends IntegracionTestCase
{
    private $duena;
    private $pageId;

    protected function setUp(): void
    {
        parent::setUp();
        CuentaSmtp::olvidar();
        CuentaSmtp::usarResolver(function () {
            return ['142.250.0.108'];
        });

        $this->duena = $this->usuario('duena@lasala.com', 'Dueña');
        $this->pageId = $this->pagina($this->duena, 'la-sala', 'La Sala');
    }

    protected function tearDown(): void
    {
        CuentaSmtp::olvidar();
        parent::tearDown();
    }

    private function guardar(array $cambios = [])
    {
        $res = CuentaSmtpHandler::index($this->db, $this->put(array_merge([
            'page_id' => $this->pageId,
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'seguridad' => 'ssl',
            'usuario' => 'lasala@gmail.com',
            'contrasena' => 'abcd efgh ijkl mnop',
            'remitente_email' => 'lasala@gmail.com',
            'remitente_nombre' => '',
            'responder_a' => '',
        ], $cambios), $this->user($this->duena, 'duena@lasala.com')));
        $this->assertStatus(200, $res);

        return $res;
    }

    private function guardada()
    {
        return $this->valor('SELECT contrasena_cifrada FROM page_smtp WHERE page_id = ?', [$this->pageId]);
    }

    public function testLaContrasenaVaCifradaALaBaseYSeRecupera()
    {
        $res = $this->guardar();

        $cifrada = $this->guardada();
        $this->assertStringNotContainsString('abcd', $cifrada);
        $this->assertSame('abcd efgh ijkl mnop', CuentaSmtp::descifrar($cifrada));

        $this->assertTrue($res->body['cuenta']['tiene_contrasena']);
        $this->assertSame(465, $res->body['cuenta']['port']);
        $this->assertNull($res->body['cuenta']['remitente_nombre']);
        $this->assertStringNotContainsString('abcd', json_encode($res->body));
    }

    public function testEditarSinContrasenaConservaLaGuardada()
    {
        $this->guardar();
        $antes = $this->guardada();

        $this->guardar(['contrasena' => '', 'remitente_nombre' => 'Boletería de La Sala']);

        $this->assertSame($antes, $this->guardada());
        $this->assertSame('Boletería de La Sala', $this->valor('SELECT remitente_nombre FROM page_smtp WHERE page_id = ?', [$this->pageId]));
        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM page_smtp'));
    }

    public function testLaPruebaUsaLaContrasenaDescifradaYMarcaVerificada()
    {
        $this->guardar();
        $mailer = new FakeMailer();

        $res = CuentaSmtpHandler::prueba(
            $this->db,
            $this->post(['page_id' => $this->pageId], $this->user($this->duena, 'duena@lasala.com')),
            $mailer
        );

        $this->assertStatus(200, $res);
        $this->assertSame('abcd efgh ijkl mnop', $mailer->cuentas[0]['contrasena']);
        $this->assertSame('La Sala', $mailer->cuentas[0]['remitente_nombre']);
        $this->assertNotNull($this->valor('SELECT verificado_en FROM page_smtp WHERE page_id = ?', [$this->pageId]));
        $this->assertSame(1, (int) $this->valor('SELECT ok FROM page_smtp_pruebas WHERE page_id = ?', [$this->pageId]));

        // Guardar de nuevo borra la verificación: era de otra configuración.
        $this->guardar(['contrasena' => '']);
        $this->assertNull($this->valor('SELECT verificado_en FROM page_smtp WHERE page_id = ?', [$this->pageId]));
    }

    public function testLaSextaPruebaDeLaHoraSeRechaza()
    {
        $this->guardar();
        $mailer = new FakeMailer();
        $pedir = function () use ($mailer) {
            return CuentaSmtpHandler::prueba(
                $this->db,
                $this->post(['page_id' => $this->pageId], $this->user($this->duena, 'duena@lasala.com')),
                $mailer
            );
        };

        for ($i = 0; $i < 5; $i++) {
            $this->assertStatus(200, $pedir());
        }

        $this->assertStatus(429, $pedir());
        $this->assertCount(5, $mailer->enviados);

        // Las de hace más de una hora ya no cuentan.
        $this->db->exec('UPDATE page_smtp_pruebas SET creado_en = NOW() - INTERVAL 2 HOUR');
        $this->assertStatus(200, $pedir());
    }

    public function testBorrarLaPaginaBorraLaCuenta()
    {
        $this->guardar();

        $this->db->prepare('DELETE FROM pages WHERE id = ?')->execute([$this->pageId]);

        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM page_smtp'));
    }

    public function testQuitarLaCuenta()
    {
        $this->guardar();

        $res = CuentaSmtpHandler::index($this->db, $this->delete(['page_id' => $this->pageId], $this->user($this->duena)));

        $this->assertStatus(200, $res);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM page_smtp'));
    }
}
