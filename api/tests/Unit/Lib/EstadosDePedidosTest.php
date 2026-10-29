<?php

namespace Tests\Unit\Lib;

use EstadosDePedidos;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakePdo;

/**
 * Los estados de un pedido de un físico (#44): qué transiciones valen, qué
 * datos pide cada paso, el stock que se devuelve al cancelar y el historial.
 */
class EstadosDePedidosTest extends TestCase
{
    /** @var FakePdo */
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePdo();
    }

    // ========================================================= transiciones

    public function transicionesValidas()
    {
        return [
            'ingresado → con fecha'     => ['ingresado', 'con_fecha'],
            'ingresado → enviado'       => ['ingresado', 'enviado'],
            'ingresado → entregado'     => ['ingresado', 'entregado'],
            'ingresado → cancelado'     => ['ingresado', 'cancelado'],
            'con fecha → con fecha'     => ['con_fecha', 'con_fecha'],
            'con fecha → enviado'       => ['con_fecha', 'enviado'],
            'con fecha → entregado'     => ['con_fecha', 'entregado'],
            'con fecha → cancelado'     => ['con_fecha', 'cancelado'],
            'enviado → entregado'       => ['enviado', 'entregado'],
            'enviado → cancelado'       => ['enviado', 'cancelado'],
        ];
    }

    /** @dataProvider transicionesValidas */
    public function testTransicionValida($desde, $hacia)
    {
        $this->assertTrue(EstadosDePedidos::puedePasar($desde, $hacia));
    }

    public function transicionesInvalidas()
    {
        return [
            'volver a ingresado'          => ['con_fecha', 'ingresado'],
            'ingresado otra vez'          => ['ingresado', 'ingresado'],
            'enviado → con fecha'         => ['enviado', 'con_fecha'],
            'enviado → enviado'           => ['enviado', 'enviado'],
            'entregado es final'          => ['entregado', 'cancelado'],
            'entregado → enviado'         => ['entregado', 'enviado'],
            'cancelado es final'          => ['cancelado', 'ingresado'],
            'cancelado → entregado'       => ['cancelado', 'entregado'],
            'estado inventado'            => ['ingresado', 'perdido'],
            'desde un estado inventado'   => ['perdido', 'enviado'],
            'sin estado (no pagado)'      => [null, 'enviado'],
        ];
    }

    /** @dataProvider transicionesInvalidas */
    public function testTransicionInvalida($desde, $hacia)
    {
        $this->assertFalse(EstadosDePedidos::puedePasar($desde, $hacia));
    }

    public function testLosSiguientesDeCadaEstado()
    {
        $this->assertSame(['entregado', 'cancelado'], EstadosDePedidos::siguientes('enviado'));
        $this->assertSame([], EstadosDePedidos::siguientes('entregado'));
        $this->assertSame([], EstadosDePedidos::siguientes(null));
    }

    // ================================================================ datos

    public function testConFechaPideLaFecha()
    {
        list($datos, $error) = EstadosDePedidos::datos('con_fecha', ['franja' => 'tarde'], '2026-10-29');

        $this->assertNull($datos);
        $this->assertSame('Elegí la fecha de entrega', $error);
    }

    public function testConFechaConFranja()
    {
        $this->assertSame(
            [['fecha' => '2026-11-03', 'franja' => 'tarde'], null],
            EstadosDePedidos::datos('con_fecha', ['fecha' => ' 2026-11-03 ', 'franja' => 'tarde'], '2026-10-29')
        );
    }

    public function testLaFranjaEsOpcional()
    {
        $this->assertSame(
            [['fecha' => '2026-11-03', 'franja' => null], null],
            EstadosDePedidos::datos('con_fecha', ['fecha' => '2026-11-03', 'franja' => ''], '2026-10-29')
        );
    }

    public function fechasInvalidas()
    {
        return [
            'formato'        => ['03/11/2026', 'no es válida'],
            'no existe'      => ['2026-02-30', 'no es válida'],
            'un array'       => [['2026-11-03'], 'Elegí la fecha'],
            'ya pasó'        => ['2026-10-27', 'ya pasó'],
            'muy adelante'   => ['2027-12-01', 'próximo año'],
        ];
    }

    /** @dataProvider fechasInvalidas */
    public function testFechaInvalida($fecha, $mensaje)
    {
        list($datos, $error) = EstadosDePedidos::datos('con_fecha', ['fecha' => $fecha], '2026-10-29');

        $this->assertNull($datos);
        $this->assertStringContainsString($mensaje, $error);
    }

    /** El servidor no está en la hora de Argentina: ayer todavía vale. */
    public function testAyerTodaviaVale()
    {
        list($datos) = EstadosDePedidos::datos('con_fecha', ['fecha' => '2026-10-28'], '2026-10-29');

        $this->assertSame('2026-10-28', $datos['fecha']);
    }

    public function testUnaFranjaQueNoEsDeLaListaNoVale()
    {
        list(, $error) = EstadosDePedidos::datos('con_fecha', ['fecha' => '2026-11-03', 'franja' => 'madrugada'], '2026-10-29');

        $this->assertStringContainsString('mañana, tarde o noche', $error);
    }

    public function testEnviadoConEmpresaYSeguimientoOpcionales()
    {
        $this->assertSame(
            [['empresa' => 'Correo Argentino', 'seguimiento' => 'https://seguimiento.test/CA1'], null],
            EstadosDePedidos::datos('enviado', ['empresa' => ' Correo Argentino ', 'seguimiento' => 'https://seguimiento.test/CA1'])
        );
        $this->assertSame([['empresa' => null, 'seguimiento' => null], null], EstadosDePedidos::datos('enviado', []));
    }

    public function testElSeguimientoTieneTope()
    {
        list(, $error) = EstadosDePedidos::datos('enviado', ['seguimiento' => str_repeat('x', 256)]);

        $this->assertStringContainsString('255', $error);
    }

    public function testCancelarPideElMotivo()
    {
        list($datos, $error) = EstadosDePedidos::datos('cancelado', ['motivo' => '   ']);

        $this->assertNull($datos);
        $this->assertStringContainsString('por qué se cancela', $error);
        $this->assertSame([['motivo' => 'Sin stock'], null], EstadosDePedidos::datos('cancelado', ['motivo' => 'Sin stock']));
    }

    public function testElMotivoTieneTope()
    {
        list(, $error) = EstadosDePedidos::datos('cancelado', ['motivo' => str_repeat('á', 256)]);

        $this->assertStringContainsString('255', $error);
    }

    public function testEntregadoNoPideNada()
    {
        $this->assertSame([[], null], EstadosDePedidos::datos('entregado', ['motivo' => 'ignorado']));
    }

    // ============================================================== cambiar

    private function hayOrden(array $extra = [])
    {
        $this->db->onSelect('FROM product_orders WHERE id = ? FOR UPDATE', [array_merge([
            'id' => 7, 'product_id' => 40, 'cantidad' => 2, 'estado' => 'pagada', 'estado_pedido' => 'ingresado',
            'sin_stock' => 0, 'entrega_fecha' => null, 'entrega_franja' => null,
        ], $extra)]);
    }

    public function testCambiarGuardaElEstadoSusDatosYElHistorialEnUnaTransaccion()
    {
        $this->hayOrden();

        $r = EstadosDePedidos::cambiar($this->db, 7, 'con_fecha', ['fecha' => '2026-11-03', 'franja' => 'tarde'], 3);

        $this->assertSame(['ok' => true, 'desde' => 'ingresado', 'stock_devuelto' => false], $r);
        $this->assertSame(
            ['con_fecha', '2026-11-03', 'tarde', 7, 'ingresado'],
            $this->db->paramsFor('SET estado_pedido = ?, entrega_fecha = ?, entrega_franja = ?')
        );
        $this->assertSame(
            [7, 'con_fecha', '{"fecha":"2026-11-03","franja":"tarde"}', 3],
            $this->db->paramsFor('INSERT INTO product_order_historial')
        );
        $this->assertTrue($this->db->committed);
        $this->assertSame(0, $this->db->transactionDepth);
    }

    /** El UPDATE exige el estado leído: si otro lo cambió en el medio, no pisa nada. */
    public function testElUpdateExigeElEstadoDelQueSale()
    {
        $this->hayOrden(['estado_pedido' => 'con_fecha']);

        EstadosDePedidos::cambiar($this->db, 7, 'enviado', ['empresa' => 'Moto', 'seguimiento' => null], 3);

        $this->assertTrue($this->db->ran('WHERE id = ? AND estado_pedido = ?'));
        $this->assertSame(['enviado', 'Moto', null, 7, 'con_fecha'], $this->db->paramsFor('envio_empresa = ?'));
    }

    public function testCancelarGuardaElMotivo()
    {
        $this->hayOrden();
        $this->db->onSelect('SELECT option_id FROM product_order_options', []);

        EstadosDePedidos::cambiar($this->db, 7, 'cancelado', ['motivo' => 'No llegó la tela'], 3);

        $this->assertSame(['cancelado', 'No llegó la tela', 7, 'ingresado'], $this->db->paramsFor('cancelado_motivo = ?'));
    }

    public function testUnaTransicionInvalidaNoEscribeNada()
    {
        $this->hayOrden(['estado_pedido' => 'enviado']);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'con_fecha', ['fecha' => '2026-11-03', 'franja' => null], 3);

        $this->assertFalse($r['ok']);
        $this->assertSame(409, $r['status']);
        $this->assertSame('Un pedido enviado no puede pasar a con fecha de entrega', $r['error']);
        $this->assertFalse($this->db->ran('UPDATE product_orders'));
        $this->assertFalse($this->db->ran('INSERT'));
        $this->assertTrue($this->db->rolledBack);
        $this->assertSame(0, $this->db->transactionDepth);
    }

    public function testEntregadoEsFinal()
    {
        $this->hayOrden(['estado_pedido' => 'entregado']);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'cancelado', ['motivo' => 'x'], 3);

        $this->assertSame('El pedido ya está entregado: no se puede cambiar', $r['error']);
    }

    public function testUnPedidoSinPagarNoSeGestiona()
    {
        $this->hayOrden(['estado' => 'pendiente', 'estado_pedido' => null]);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'entregado', [], 3);

        $this->assertSame(409, $r['status']);
        $this->assertStringContainsString('no está pagado', $r['error']);
        $this->assertFalse($this->db->ran('UPDATE product_orders'));
    }

    public function testUnPedidoQueNoExiste()
    {
        $r = EstadosDePedidos::cambiar($this->db, 7, 'entregado', [], 3);

        $this->assertSame(404, $r['status']);
    }

    /** Corregir la fecha a la misma no manda otro mail para nada. */
    public function testCorregirALaMismaFechaNoCambiaNada()
    {
        $this->hayOrden(['estado_pedido' => 'con_fecha', 'entrega_fecha' => '2026-11-03', 'entrega_franja' => 'tarde']);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'con_fecha', ['fecha' => '2026-11-03', 'franja' => 'tarde'], 3);

        $this->assertSame(409, $r['status']);
        $this->assertFalse($this->db->ran('UPDATE product_orders'));
    }

    public function testCorregirLaFechaSiSePuede()
    {
        $this->hayOrden(['estado_pedido' => 'con_fecha', 'entrega_fecha' => '2026-11-03', 'entrega_franja' => 'tarde']);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'con_fecha', ['fecha' => '2026-11-03', 'franja' => 'manana'], 3);

        $this->assertTrue($r['ok']);
        $this->assertSame('con_fecha', $r['desde']);
    }

    public function testSiFallaLaBaseSeDeshaceTodo()
    {
        $this->hayOrden();
        $this->db->failOn('INSERT INTO product_order_historial');

        try {
            EstadosDePedidos::cambiar($this->db, 7, 'entregado', [], 3);
            $this->fail('Tenía que lanzar la excepción de la base');
        } catch (\PDOException $e) {
            $this->assertTrue($this->db->rolledBack);
            $this->assertFalse($this->db->committed);
            $this->assertSame(0, $this->db->transactionDepth);
        }
    }

    // ================================================================ stock

    public function testCancelarDevuelveElStockDeLaCombinacion()
    {
        $this->hayOrden();
        $this->db->onSelect('SELECT option_id FROM product_order_options', [['option_id' => 21], ['option_id' => 12]]);
        $this->db->onWrite('UPDATE product_variants SET stock = stock + ?', 1);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'cancelado', ['motivo' => 'Se arrepintió'], 3);

        $this->assertTrue($r['stock_devuelto']);
        $this->assertSame([2, 40, '12-21'], $this->db->paramsFor('UPDATE product_variants SET stock = stock + ?'));
        // Dentro de la transacción, antes del commit.
        $this->assertTrue($this->db->committed);
    }

    public function testCancelarDevuelveElStockDelProductoSinCaracteristicas()
    {
        $this->hayOrden(['cantidad' => 3]);
        $this->db->onSelect('SELECT option_id FROM product_order_options', []);
        $this->db->onWrite('UPDATE digital_products SET stock = stock + ?', 1);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'cancelado', ['motivo' => 'Se arrepintió'], 3);

        $this->assertTrue($r['stock_devuelto']);
        $this->assertSame([3, 40], $this->db->paramsFor('UPDATE digital_products SET stock = stock + ?'));
        $this->assertStringContainsString('stock IS NOT NULL', $this->db->callsFor('SET stock = stock + ?')[0]['sql']);
    }

    /** Un pedido marcado sin stock nunca lo descontó: no hay nada que devolver. */
    public function testUnPedidoSinStockNoDevuelveNada()
    {
        $this->hayOrden(['sin_stock' => 1]);

        $r = EstadosDePedidos::cambiar($this->db, 7, 'cancelado', ['motivo' => 'No había'], 3);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['stock_devuelto']);
        $this->assertFalse($this->db->ran('stock = stock +'));
    }

    public function testLosOtrosEstadosNoTocanElStock()
    {
        $this->hayOrden();

        EstadosDePedidos::cambiar($this->db, 7, 'entregado', [], 3);

        $this->assertFalse($this->db->ran('stock = stock'));
        $this->assertSame([7, 'entregado', null, 3], $this->db->paramsFor('INSERT INTO product_order_historial'));
    }

    // ============================================================ ingresar

    public function testIngresarAnotaAlSistema()
    {
        $this->db->onWrite("SET estado_pedido = 'ingresado'", 1);

        EstadosDePedidos::ingresar($this->db, 7);

        $this->assertSame([7, 'ingresado', null, null], $this->db->paramsFor('INSERT INTO product_order_historial'));
    }

    /** Un aviso repetido no vuelve a anotar: el UPDATE sólo toca un pedido sin estado. */
    public function testIngresarDosVecesNoDuplicaElHistorial()
    {
        EstadosDePedidos::ingresar($this->db, 7);

        $this->assertTrue($this->db->ran('AND estado_pedido IS NULL'));
        $this->assertFalse($this->db->ran('INSERT INTO product_order_historial'));
    }

    // ============================================================ historial

    public function testElHistorialDiceQuienYLosDatos()
    {
        $this->db->onSelect('FROM product_order_historial h', [
            ['id' => 1, 'estado' => 'ingresado', 'datos' => null, 'creado_en' => '2026-10-30 10:00:00', 'user_id' => null, 'quien' => null],
            ['id' => 2, 'estado' => 'cancelado', 'datos' => '{"motivo":"Sin tela"}', 'creado_en' => '2026-10-31 10:00:00',
             'user_id' => 3, 'quien' => 'vende@test.local'],
        ]);

        $historial = EstadosDePedidos::historial($this->db, 7);

        $this->assertSame([7], $this->db->paramsFor('FROM product_order_historial h'));
        $this->assertSame([
            ['estado' => 'ingresado', 'etiqueta' => 'Ingresado', 'datos' => [], 'creado_en' => '2026-10-30 10:00:00', 'quien' => 'Sistema'],
            ['estado' => 'cancelado', 'etiqueta' => 'Cancelado', 'datos' => ['motivo' => 'Sin tela'],
             'creado_en' => '2026-10-31 10:00:00', 'quien' => 'vende@test.local'],
        ], $historial);
    }

    public function testLaLineaDeTiempoDeUnPedidoIngresado()
    {
        $linea = EstadosDePedidos::lineaDeTiempo('ingresado', [['estado' => 'ingresado', 'creado_en' => '2026-10-30 10:00:00']]);

        $this->assertSame(['ingresado', 'con_fecha', 'enviado', 'entregado'], array_column($linea, 'estado'));
        $this->assertSame(['2026-10-30 10:00:00', null, null, null], array_column($linea, 'fecha'));
    }

    /** Un salto deja sin fecha el paso salteado. */
    public function testUnSaltoDejaElPasoSinFecha()
    {
        $linea = EstadosDePedidos::lineaDeTiempo('entregado', [
            ['estado' => 'ingresado', 'creado_en' => '2026-10-30 10:00:00'],
            ['estado' => 'entregado', 'creado_en' => '2026-10-31 10:00:00'],
        ]);

        $this->assertSame(['2026-10-30 10:00:00', null, null, '2026-10-31 10:00:00'], array_column($linea, 'fecha'));
    }
}
