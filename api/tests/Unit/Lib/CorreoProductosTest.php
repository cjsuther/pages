<?php

namespace Tests\Unit\Lib;

use CorreoProductos;
use Cripto;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMailer;
use Tests\Support\FakePdo;

class CorreoProductosTest extends TestCase
{
    const CODIGO = 'AAAABBBBCCCC';

    /** @var FakePdo */
    private $db;

    /** @var string */
    private $token;

    protected function setUp(): void
    {
        $this->db = new FakePdo();
        $this->token = str_repeat('9f', 32);
    }

    private function hayOrden(array $overrides = [])
    {
        $this->db->onSelect('FROM product_orders o', [array_merge([
            'id' => 7, 'codigo' => self::CODIGO, 'estado' => 'pagada',
            'nombre' => 'Ana Gómez', 'email' => 'ana@example.com',
            'token_cifrado' => Cripto::cifrar($this->token),
            'mail_enviado_en' => null, 'mail_intentos' => 0,
            'producto' => 'Guía de viaje', 'producto_imagen' => 'https://rezonar.test/api/uploads/tapa.jpg',
            'archivo_nombre' => 'guia.pdf', 'pagina' => 'La Sala', 'email_contacto' => 'sala@example.com',
        ], $overrides)]);
    }

    public function testElMailLlevaElNombreDelProductoYElLinkConElToken()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();

        $r = CorreoProductos::enviar($this->db, self::CODIGO, $mailer);

        $this->assertTrue($r['enviado']);
        $mensaje = $mailer->mensajePara('ana@example.com');
        $this->assertStringContainsString('Guía de viaje', $mensaje['asunto']);
        $this->assertStringContainsString('href="https://frontend.test/descarga#' . $this->token . '"', $mensaje['html']);
        $this->assertStringContainsString('DESCARGAR', $mensaje['html']);
        $this->assertStringContainsString('https://frontend.test/descarga#' . $this->token, $mensaje['texto']);
        $this->assertSame('sala@example.com', $mensaje['responder']);
    }

    public function testElMailAclaraQueElLinkEsPersonal()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();

        CorreoProductos::enviar($this->db, self::CODIGO, $mailer);

        $this->assertStringContainsString('Este link es personal', $mailer->enviados[0]['html']);
        $this->assertStringContainsString('Este link es personal', $mailer->enviados[0]['texto']);
    }

    public function testElNombreDelProductoSeEscapa()
    {
        $this->hayOrden(['producto' => '<script>alert(1)</script>']);
        $mailer = new FakeMailer();

        CorreoProductos::enviar($this->db, self::CODIGO, $mailer);

        $this->assertStringNotContainsString('<script>', $mailer->enviados[0]['html']);
    }

    public function testSeAnotaElEnvio()
    {
        $this->hayOrden();

        CorreoProductos::enviar($this->db, self::CODIGO, new FakeMailer());

        $this->assertTrue($this->db->ran('SET mail_enviado_en = NOW(), mail_intentos = mail_intentos + 1'));
    }

    public function testUnFalloSeAnotaConSuMotivo()
    {
        $this->hayOrden();

        $r = CorreoProductos::enviar($this->db, self::CODIGO, (new FakeMailer())->fallarCon('SMTP caído'));

        $this->assertFalse($r['enviado']);
        $this->assertSame(['SMTP caído', 7], $this->db->paramsFor('mail_error = ?'));
    }

    public function testUnaOrdenSinPagarNoManda()
    {
        $this->hayOrden(['estado' => 'pendiente']);
        $mailer = new FakeMailer();

        CorreoProductos::enviar($this->db, self::CODIGO, $mailer);

        $this->assertSame([], $mailer->enviados);
    }

    public function testNoSeMandaDosVecesSinPedirlo()
    {
        $this->hayOrden(['mail_enviado_en' => '2020-01-01 00:00:00']);
        $mailer = new FakeMailer();

        $r = CorreoProductos::enviar($this->db, self::CODIGO, $mailer);

        $this->assertSame('ya se había enviado', $r['motivo']);
        $this->assertSame([], $mailer->enviados);
    }

    public function testElReenvioMandaAunqueYaHayaSalido()
    {
        $this->hayOrden(['mail_enviado_en' => '2020-01-01 00:00:00']);
        $mailer = new FakeMailer();

        $r = CorreoProductos::enviar($this->db, self::CODIGO, $mailer, true);

        $this->assertTrue($r['enviado']);
        $this->assertCount(1, $mailer->enviados);
    }

    /** El formulario no puede servir para llenarle la casilla a alguien. */
    public function testElReenvioTieneUnMinimoEntreEnvios()
    {
        $this->hayOrden(['mail_enviado_en' => date('Y-m-d H:i:s')]);
        $mailer = new FakeMailer();

        $r = CorreoProductos::enviar($this->db, self::CODIGO, $mailer, true);

        $this->assertFalse($r['enviado']);
        $this->assertSame([], $mailer->enviados);
    }

    public function testDespuesDeLosReintentosNoInsiste()
    {
        $this->hayOrden(['mail_intentos' => 3]);
        $mailer = new FakeMailer();

        $r = CorreoProductos::enviar($this->db, self::CODIGO, $mailer);

        $this->assertSame('se agotaron los reintentos', $r['motivo']);
        $this->assertSame([], $mailer->enviados);
    }

    public function testElCronReintentaLasPendientes()
    {
        $this->db->onSelect('FROM product_orders WHERE estado', [['codigo' => self::CODIGO]]);
        $this->hayOrden();
        $mailer = new FakeMailer();

        $r = CorreoProductos::enviarPendientes($this->db, $mailer);

        $this->assertSame(['enviados' => 1, 'fallidos' => 0], $r);
        $this->assertSame([3], $this->db->paramsFor('mail_intentos < ?'));
    }
}
