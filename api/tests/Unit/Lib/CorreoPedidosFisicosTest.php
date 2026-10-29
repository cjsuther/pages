<?php

namespace Tests\Unit\Lib;

use CorreoProductos;
use Cripto;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMailer;
use Tests\Support\FakePdo;

/**
 * Los mails de un pedido de un físico (#43): "Recibimos tu pedido" a quien
 * compró y "Pedido nuevo" a quien vende. Sin link de descarga.
 */
class CorreoPedidosFisicosTest extends TestCase
{
    const CODIGO = 'AAAABBBBCCCC';

    /** @var FakePdo */
    private $db;

    /** @var FakeMailer */
    private $mailer;

    protected function setUp(): void
    {
        $this->db = new FakePdo();
        $this->mailer = new FakeMailer();
    }

    private function hayPedido(array $overrides = [])
    {
        $this->db->onSelect('FROM product_orders o', [array_merge([
            'id' => 7, 'codigo' => self::CODIGO, 'estado' => 'pagada', 'tipo' => 'fisico',
            'nombre' => 'Ana Gómez', 'email' => 'ana@example.com', 'telefono' => '11 5555-1234',
            'token_cifrado' => Cripto::cifrar(str_repeat('9f', 32)),
            'mail_enviado_en' => null, 'mail_intentos' => 0, 'aviso_vendedor_en' => null,
            'producto' => 'Remera', 'opciones_texto' => 'Talle M · Color Negro',
            'cantidad' => 2, 'precio_unitario' => '2500.00', 'costo_envio' => '500.00', 'total' => '5500.00',
            'sin_stock' => 0,
            'producto_imagen' => null, 'archivo_nombre' => null,
            'pagina' => 'La Sala', 'email_contacto' => 'sala@example.com', 'duena_email' => 'duena@example.com',
        ], $overrides)]);
        $this->db->onSelect('FROM product_order_envios WHERE order_id = ?', [[
            'order_id' => 7, 'calle' => 'Av. Corrientes 1234', 'piso_depto' => '3° B', 'localidad' => 'CABA',
            'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => 'C1043AAZ', 'indicaciones' => 'Timbre B',
            'dias' => 'lunes,sabado', 'franjas' => 'tarde,noche', 'comentario_horario' => 'Después de las 18',
        ]]);
    }

    // ------------------------------------------------------------- comprador

    public function testAlCompradorLeLlegaRecibimosTuPedidoConElDetalle()
    {
        $this->hayPedido();

        $r = CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertTrue($r['enviado']);
        $mensaje = $this->mailer->mensajePara('ana@example.com');
        $this->assertSame('Recibimos tu pedido: Remera', $mensaje['asunto']);

        foreach ([$mensaje['html'], $mensaje['texto']] as $cuerpo) {
            $this->assertStringContainsString('Talle M · Color Negro', $cuerpo);
            $this->assertStringContainsString('2 × $ 2.500', $cuerpo);
            $this->assertStringContainsString('$ 500', $cuerpo);
            $this->assertStringContainsString('$ 5.500', $cuerpo);
            $this->assertStringContainsString('Av. Corrientes 1234, 3° B', $cuerpo);
            $this->assertStringContainsString('C1043AAZ', $cuerpo);
            $this->assertStringContainsString('Timbre B', $cuerpo);
            $this->assertStringContainsString('Lunes y Sábado', $cuerpo);
            $this->assertStringContainsString('Tarde y Noche', $cuerpo);
            $this->assertStringContainsString('Después de las 18', $cuerpo);
            $this->assertStringContainsString('https://frontend.test/compra/' . self::CODIGO, $cuerpo);
            $this->assertStringNotContainsString('/descarga', $cuerpo);
        }

        $this->assertSame('sala@example.com', $mensaje['responder']);
        $this->assertTrue($this->db->ran('SET mail_enviado_en = NOW()'));
    }

    public function testElDetalleSeEscapa()
    {
        $this->hayPedido(['producto' => '<b>Remera</b>']);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertStringContainsString('&lt;b&gt;Remera&lt;/b&gt;', $this->mailer->mensajePara('ana@example.com')['html']);
    }

    public function testElEnvioIncluidoYUnPedidoGratis()
    {
        $this->hayPedido(['costo_envio' => '0.00', 'precio_unitario' => '0.00', 'total' => '0.00', 'cantidad' => 1]);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);
        $texto = $this->mailer->mensajePara('ana@example.com')['texto'];

        $this->assertStringContainsString('Envío: Incluido', $texto);
        $this->assertStringContainsString('Total: Gratis', $texto);
    }

    // -------------------------------------------------------------- vendedor

    public function testAlVendedorLeLlegaPedidoNuevoConLaDireccionYElContacto()
    {
        $this->hayPedido();

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $aviso = $this->mailer->mensajePara('sala@example.com');
        $this->assertSame('Pedido nuevo: Remera (' . self::CODIGO . ')', $aviso['asunto']);
        $this->assertSame('ana@example.com', $aviso['responder']);

        foreach ([$aviso['html'], $aviso['texto']] as $cuerpo) {
            $this->assertStringContainsString('Talle M · Color Negro', $cuerpo);
            $this->assertStringContainsString('Av. Corrientes 1234, 3° B', $cuerpo);
            $this->assertStringContainsString('11 5555-1234', $cuerpo);
            $this->assertStringContainsString('Ana Gómez', $cuerpo);
            $this->assertStringNotContainsString('no quedaba stock', $cuerpo);
        }

        $this->assertSame([7], $this->db->paramsFor('SET aviso_vendedor_en = NOW()'));
    }

    public function testSinEmailDeContactoVaAlDeLaDuena()
    {
        $this->hayPedido(['email_contacto' => null]);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertNotNull($this->mailer->mensajePara('duena@example.com'));
        $this->assertArrayNotHasKey('responder', $this->mailer->mensajePara('ana@example.com'));
    }

    public function testElAvisoSaleUnaSolaVez()
    {
        $this->hayPedido(['aviso_vendedor_en' => '2026-10-29 10:00:00', 'mail_enviado_en' => '2026-10-29 10:00:00']);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertSame([], $this->mailer->enviados);
    }

    public function testSiFaltabaElAvisoSeMandaAunqueElDelCompradorYaHayaSalido()
    {
        $this->hayPedido(['mail_enviado_en' => '2026-10-29 10:00:00', 'mail_intentos' => 1]);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertCount(1, $this->mailer->enviados);
        $this->assertNotNull($this->mailer->mensajePara('sala@example.com'));
    }

    public function testElAvisoDiceSiNoQuedabaStock()
    {
        $this->hayPedido(['sin_stock' => 1]);

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertStringContainsString('no quedaba stock', $this->mailer->mensajePara('sala@example.com')['texto']);
        $this->assertStringNotContainsString('stock', $this->mailer->mensajePara('ana@example.com')['texto']);
    }

    public function testUnAvisoQueFallaNoSeAnotaYElDelCompradorSaleIgual()
    {
        $this->hayPedido();
        $this->mailer->fallarPara('sala@example.com', 'SMTP caído');

        $r = CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertTrue($r['enviado']);
        $this->assertFalse($this->db->ran('SET aviso_vendedor_en = NOW()'));
    }

    public function testUnAvisoQueFallaSoloCuentaComoIntento()
    {
        $this->hayPedido(['mail_enviado_en' => '2026-10-29 10:00:00', 'mail_intentos' => 1]);
        $this->mailer->fallarPara('sala@example.com', 'SMTP caído');

        CorreoProductos::enviar($this->db, self::CODIGO, $this->mailer);

        $this->assertSame(['SMTP caído', 7], $this->db->paramsFor('SET mail_intentos = mail_intentos + 1, mail_error = ?'));
    }

    public function testElCronReintentaLosAvisosPendientes()
    {
        $this->db->onSelect('FROM product_orders WHERE estado', []);
        $this->db->onSelect("WHERE dp.tipo = 'fisico' AND o.estado = 'pagada'", [['codigo' => 'DDDDEEEEFFFF']]);

        $this->assertSame(['DDDDEEEEFFFF'], CorreoProductos::pendientes($this->db));
    }

    public function testLosPesos()
    {
        $this->assertSame('$ 2.500', CorreoProductos::pesos('2500.00'));
        $this->assertSame('$ 1.234,50', CorreoProductos::pesos(1234.5));
    }
}
