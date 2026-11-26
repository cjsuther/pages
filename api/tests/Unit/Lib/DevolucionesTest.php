<?php

namespace Tests\Unit\Lib;

use Devoluciones;
use PHPUnit\Framework\TestCase;

/**
 * Las cuentas y los motivos de las devoluciones, sin base ni red.
 */
class DevolucionesTest extends TestCase
{
    protected function tearDown(): void
    {
        Devoluciones::forzar(null);
        parent::tearDown();
    }

    // --------------------------------------------------------- interruptor

    /** Sin DEVOLUCIONES_HABILITADAS en la config, está apagado. */
    public function testSinLaConstanteEstanApagadas()
    {
        $this->assertFalse(defined('DEVOLUCIONES_HABILITADAS'));
        $this->assertFalse(Devoluciones::habilitadas());
    }

    public function testLosTestsPuedenPrenderlas()
    {
        Devoluciones::forzar(true);
        $this->assertTrue(Devoluciones::habilitadas());

        Devoluciones::forzar(null);
        $this->assertFalse(Devoluciones::habilitadas());
    }

    // ------------------------------------------------------------- cuentas

    public function testLoDevolvibleEsElNetoMenosLoDevuelto()
    {
        $this->assertSame(9368.81, Devoluciones::devolvible(9368.81, 0));
        $this->assertSame(4684.40, Devoluciones::devolvible(9368.81, 4684.41));
        $this->assertSame(0.0, Devoluciones::devolvible(9368.81, 9368.81));
    }

    public function testLoDevolvibleNuncaEsNegativo()
    {
        $this->assertSame(0.0, Devoluciones::devolvible(100, 150));
    }

    private function estado($cantidad, array $items = [])
    {
        return ['cantidad' => $cantidad, 'lugares' => [], 'items' => $items];
    }

    private function item($tipo, $precio, $cantidad, $personas = 1)
    {
        return ['tipo' => $tipo, 'nombre' => $tipo, 'precio' => $precio, 'personas' => $personas, 'cantidad' => $cantidad];
    }

    /** Ejemplo del análisis: 2 × $10.000 con MP 4,39 % + IVA y Rezonar 1 %. */
    public function testSinTiposCadaEntradaValeLoMismo()
    {
        $this->assertSame(9368.81, Devoluciones::parteDe(18737.62, $this->estado(2), $this->estado(1)));
    }

    /** Hacia abajo al centavo: el resto queda para la última. */
    public function testLaParteSeRedondeaHaciaAbajo()
    {
        $this->assertSame(3333.33, Devoluciones::parteDe(10000, $this->estado(3), $this->estado(2)));
    }

    public function testConTiposEsProporcionalALoQueValen()
    {
        $antes = $this->estado(2, [$this->item('general', 8000, 1), $this->item('jubilados', 5000, 1)]);
        $despues = $this->estado(1, [$this->item('general', 8000, 1)]);

        // 12000 × 5000 / 13000 = 4615,384...
        $this->assertSame(4615.38, Devoluciones::parteDe(12000, $antes, $despues));
    }

    public function testUnaPromoValePorUnidadNoPorPersona()
    {
        $antes = $this->estado(6, [$this->item('promo', 9000, 4, 2), $this->item('general', 6000, 2)]);
        $despues = $this->estado(4, [$this->item('promo', 9000, 2, 2), $this->item('general', 6000, 2)]);

        // Valía 2 × 9000 + 2 × 6000 = 30000; se saca una promo (9000).
        $this->assertSame(3000.0, Devoluciones::parteDe(10000, $antes, $despues));
    }

    public function testEntradasSinPrecioCuentanPorCantidad()
    {
        $antes = $this->estado(2, [$this->item('cortesia', 0, 2)]);
        $despues = $this->estado(1, [$this->item('cortesia', 0, 1)]);

        $this->assertSame(500.0, Devoluciones::parteDe(1000, $antes, $despues));
    }

    // ------------------------------------------------------------- motivos

    /** @dataProvider motivosConocidos */
    public function testLosCodigosConocidosDeMercadoPagoSeExplicanEnCastellano($status, $codigo, $mensaje, $esperado)
    {
        $this->assertStringContainsString($esperado, Devoluciones::motivoDeMercadoPago($status, $codigo, $mensaje));
    }

    public function motivosConocidos()
    {
        return [
            'parcial no soportado' => [400, '3024', 'Partial refund unsupported', 'no permite devoluciones parciales'],
            'cargo parcial'        => [400, '4295', 'Partial-charge-refund-is-not-allowed', 'no permite devoluciones parciales'],
            'muy viejo'            => [400, '15016', 'Payment-too-old-to-be-refunded', 'más de 180 días'],
            'muy viejo (2024)'     => [400, '2024', 'Payment too old', 'más de 180 días'],
            'estado inválido'      => [400, '2063', 'The action requested is not valid', 'no está en un estado'],
            'monto negativo'       => [400, '4040', 'amount attribute must be positive', 'el monto'],
            'monto no numérico'    => [400, '4041', 'amount attribute must be numeric', 'el monto'],
            'token inválido'       => [401, '401', 'Invalid token', 'credencial'],
            'no autorizado'        => [401, 'unauthorized', 'invalid_token', 'credencial'],
            'saldo insuficiente'   => [400, 'bad_request', 'Insufficient balance to refund', 'saldo suficiente'],
            'pago inexistente'     => [404, '2000', 'Payment not found', 'no encuentra el pago'],
            'ya devuelto'          => [400, '4296', 'Charge already refunded', 'ya fue devuelto'],
        ];
    }

    public function testUnCodigoDesconocidoUsaElGenericoConElCodigo()
    {
        $this->assertSame(
            'Mercado Pago rechazó la devolución (código 9999).',
            Devoluciones::motivoDeMercadoPago(400, '9999', 'algo raro')
        );
    }

    public function testTodosLosBloqueosTienenMotivo()
    {
        foreach (['apagadas', 'invitado', 'sin_costo', 'usadas', 'nada', 'vieja', 'sin_credenciales'] as $clave) {
            $this->assertArrayHasKey($clave, Devoluciones::MOTIVOS);
        }
    }
}
