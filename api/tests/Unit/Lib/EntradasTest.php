<?php

namespace Tests\Unit\Lib;

use Entradas;
use Tests\Support\HandlerTestCase;

class EntradasTest extends HandlerTestCase
{
    private function hayEvento(array $overrides = [])
    {
        $config = array_merge([
            'id' => 1,
            'link_id' => 100,
            'activo' => 1,
            'capacidad' => 50,
            'precio' => '1500.00',
            'moneda' => 'ARS',
            'max_por_compra' => 10,
        ], $overrides);

        $this->db->onSelect('FROM event_ticketing WHERE link_id', [$config]);

        return $config;
    }

    private function hayOcupadas($cantidad)
    {
        $this->db->onSelect('COALESCE(SUM(cantidad), 0)', [[$cantidad]]);
    }

    private function comprador(array $overrides = [])
    {
        return array_merge([
            'nombre' => 'Ana Gómez',
            'email' => 'ana@example.com',
            'telefono' => '+54 11 2233-4455',
            'cantidad' => 2,
        ], $overrides);
    }

    // ------------------------------------------------------------ ocupadas

    /**
     * Una reserva vencida no puede seguir reteniendo cupo: si contara, un
     * carrito abandonado dejaría entradas sin vender para siempre.
     */
    public function testLoOcupadoIgnoraLasReservasVencidas()
    {
        $this->hayOcupadas(0);

        Entradas::ocupadas($this->db, 100);

        $sql = $this->db->callsFor('COALESCE(SUM(cantidad), 0)')[0]['sql'];

        $this->assertStringContainsString("estado = 'pagada'", $sql);
        $this->assertStringContainsString('reserva_vence_en > NOW()', $sql);
    }

    public function testLoOcupadoSumaPagadasYReservasVigentes()
    {
        $this->hayOcupadas(12);

        $this->assertSame(12, Entradas::ocupadas($this->db, 100));
    }

    // ------------------------------------------------------- disponibilidad

    public function testSinConfiguracionNoHayVenta()
    {
        $this->assertNull(Entradas::disponibilidad($this->db, 100));
    }

    public function testDesactivadoNoHayVenta()
    {
        $this->hayEvento(['activo' => 0]);

        $this->assertNull(Entradas::disponibilidad($this->db, 100));
    }

    public function testLoDisponibleEsLaCapacidadMenosLoOcupado()
    {
        $this->hayEvento(['capacidad' => 50]);
        $this->hayOcupadas(18);

        $d = Entradas::disponibilidad($this->db, 100);

        $this->assertSame(32, $d['disponibles']);
        $this->assertFalse($d['agotado']);
    }

    public function testSeMarcaAgotadoAlLlegarALaCapacidad()
    {
        $this->hayEvento(['capacidad' => 50]);
        $this->hayOcupadas(50);

        $d = Entradas::disponibilidad($this->db, 100);

        $this->assertSame(0, $d['disponibles']);
        $this->assertTrue($d['agotado']);
    }

    /** Si algo se sobrevendió, mostrar disponibles negativos sería peor. */
    public function testLoDisponibleNuncaEsNegativo()
    {
        $this->hayEvento(['capacidad' => 50]);
        $this->hayOcupadas(55);

        $this->assertSame(0, Entradas::disponibilidad($this->db, 100)['disponibles']);
    }

    public function testPrecioCeroEsReservaSinCobro()
    {
        $this->hayEvento(['precio' => '0.00']);
        $this->hayOcupadas(0);

        $this->assertTrue(Entradas::disponibilidad($this->db, 100)['es_gratis']);
    }

    public function testConPrecioNoEsGratis()
    {
        $this->hayEvento(['precio' => '1500.00']);
        $this->hayOcupadas(0);

        $this->assertFalse(Entradas::disponibilidad($this->db, 100)['es_gratis']);
    }

    /** Ofrecer "hasta 10" cuando quedan 3 lleva a un error al confirmar. */
    public function testElMaximoPorCompraSeRecortaALoQueQueda()
    {
        $this->hayEvento(['capacidad' => 50, 'max_por_compra' => 10]);
        $this->hayOcupadas(47);

        $this->assertSame(3, Entradas::disponibilidad($this->db, 100)['max_por_compra']);
    }

    // ------------------------------------------------------------ crearOrden

    public function testNoSePuedeComprarSinNombre()
    {
        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['nombre' => '  ']));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nombre', $r['error']);
        $this->assertNoWrites();
    }

    public function testNoSePuedeComprarConEmailInvalido()
    {
        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['email' => 'no-es-un-email']));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('email', $r['error']);
    }

    public function testNoSePuedeComprarSinTelefonoUtilizable()
    {
        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['telefono' => 'abc']));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('teléfono', $r['error']);
    }

    /**
     * El teléfono se pide para poder avisar de un cambio de fecha, así que se
     * acepta como lo escriba la gente en vez de imponer un formato.
     *
     * @dataProvider telefonosDeVerdad
     */
    public function testElTelefonoSeAceptaEnCualquierFormato($telefono)
    {
        $this->hayEvento();
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);

        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['telefono' => $telefono]));

        $this->assertTrue($r['ok'], "debería aceptar $telefono");
    }

    public function telefonosDeVerdad()
    {
        return [
            'con prefijo internacional' => ['+54 9 11 2233-4455'],
            'con característica'        => ['(011) 4444-5555'],
            'sólo dígitos'              => ['1122334455'],
            'con puntos'                => ['11.2233.4455'],
        ];
    }

    public function testNoSePuedePedirCeroEntradas()
    {
        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['cantidad' => 0]));

        $this->assertFalse($r['ok']);
    }

    public function testNoSePuedeComprarSiElEventoNoVendeEntradas()
    {
        $r = Entradas::crearOrden($this->db, 100, $this->comprador());

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no vende entradas', $r['error']);
    }

    public function testNoSePuedeSuperarElMaximoPorCompra()
    {
        $this->hayEvento(['max_por_compra' => 4]);

        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['cantidad' => 5]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('máximo por compra es 4', $r['error']);
    }

    public function testNoSePuedeComprarMasDeLoQueQueda()
    {
        $this->hayEvento(['capacidad' => 50]);
        $this->hayOcupadas(48);

        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['cantidad' => 3]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Sólo quedan 2', $r['error']);
    }

    public function testAgotadoLoDiceAsiYNoConUnNumero()
    {
        $this->hayEvento(['capacidad' => 50]);
        $this->hayOcupadas(50);

        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['cantidad' => 1]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('agotaron', $r['error']);
    }

    /**
     * El bloqueo de la fila del evento es lo que impide vender dos veces la
     * misma última entrada: sin él, dos compras simultáneas leen ambas
     * "queda 1" y las dos pasan.
     */
    public function testElCupoSeTomaConLaFilaDelEventoBloqueada()
    {
        $this->hayEvento();
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);

        Entradas::crearOrden($this->db, 100, $this->comprador());

        $sql = $this->db->callsFor('FROM event_ticketing WHERE link_id')[0]['sql'];

        $this->assertStringContainsString('FOR UPDATE', $sql);
        $this->assertTrue($this->db->committed, 'la compra tiene que confirmar la transacción');
    }

    public function testUnaCompraRechazadaNoDejaLaTransaccionAbierta()
    {
        $this->hayEvento(['capacidad' => 10]);
        $this->hayOcupadas(10);

        Entradas::crearOrden($this->db, 100, $this->comprador(['cantidad' => 1]));

        $this->assertTrue($this->db->rolledBack);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testLaOrdenNaceReservadaConVencimiento()
    {
        $this->hayEvento();
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);

        $r = Entradas::crearOrden($this->db, 100, $this->comprador());
        $params = $this->db->paramsFor('INSERT INTO ticket_orders');

        $this->assertSame('reservada', $r['orden']['estado']);
        $this->assertSame('reservada', $params[9]);
        $this->assertNotNull($params[10], 'tiene que tener fecha de vencimiento');
    }

    /** Sin cobro no hay pago que esperar: dejarla vencer perdería la reserva. */
    public function testUnaReservaSinPrecioQuedaConfirmadaEnElActo()
    {
        $this->hayEvento(['precio' => '0.00']);
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);

        $r = Entradas::crearOrden($this->db, 100, $this->comprador());
        $params = $this->db->paramsFor('INSERT INTO ticket_orders');

        $this->assertTrue($r['orden']['es_gratis']);
        $this->assertSame('pagada', $r['orden']['estado']);
        $this->assertSame('pagada', $params[9]);
        $this->assertNull($params[10], 'no tiene que vencer');
    }

    public function testElTotalEsPrecioPorCantidad()
    {
        $this->hayEvento(['precio' => '1500.00']);
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);

        $r = Entradas::crearOrden($this->db, 100, $this->comprador(['cantidad' => 3]));

        $this->assertSame(4500.0, $r['orden']['total']);
    }

    /**
     * El precio se copia a la orden: si el dueño lo sube después, lo ya
     * comprado no puede cambiar de monto retroactivamente.
     */
    public function testElPrecioQuedaCongeladoEnLaOrden()
    {
        $this->hayEvento(['precio' => '1500.00']);
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);

        Entradas::crearOrden($this->db, 100, $this->comprador());
        $params = $this->db->paramsFor('INSERT INTO ticket_orders');

        $this->assertSame(1500.0, $params[6]);
    }

    public function testElCodigoDeLaOrdenTieneElLargoDeLaColumna()
    {
        $this->hayEvento();
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);

        $codigo = Entradas::crearOrden($this->db, 100, $this->comprador())['orden']['codigo'];

        $this->assertSame(12, strlen($codigo), 'la columna es CHAR(12)');
    }

    /** El código va en una URL pública: adivinar órdenes ajenas no puede ser viable. */
    public function testCadaOrdenRecibeUnCodigoDistinto()
    {
        $codigos = [];

        for ($i = 0; $i < 5; $i++) {
            $this->hayEvento();
            $this->hayOcupadas(0);
            $this->db->onWrite('INSERT INTO ticket_orders', 1);

            $codigos[] = Entradas::crearOrden($this->db, 100, $this->comprador())['orden']['codigo'];
        }

        $this->assertCount(5, array_unique($codigos));
    }

    // --------------------------------------------------------- guardarConfig

    public function testNoSeAceptaCapacidadCero()
    {
        $r = Entradas::guardarConfig($this->db, 100, ['capacidad' => 0, 'precio' => 100]);

        $this->assertFalse($r['ok']);
    }

    public function testNoSeAceptaPrecioNegativo()
    {
        $this->hayOcupadas(0);

        $r = Entradas::guardarConfig($this->db, 100, ['capacidad' => 10, 'precio' => -5]);

        $this->assertFalse($r['ok']);
    }

    /** Bajar la capacidad por debajo de lo vendido deja el evento sobrevendido. */
    public function testNoSePuedeBajarLaCapacidadPorDebajoDeLoVendido()
    {
        $this->hayOcupadas(30);

        $r = Entradas::guardarConfig($this->db, 100, ['capacidad' => 20, 'precio' => 100]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('30 entradas tomadas', $r['error']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO event_ticketing'));
    }

    public function testSePuedeBajarLaCapacidadHastaLoVendido()
    {
        $this->hayOcupadas(30);
        $this->db->onWrite('INSERT INTO event_ticketing', 1);

        $this->assertTrue(Entradas::guardarConfig($this->db, 100, ['capacidad' => 30, 'precio' => 100])['ok']);
    }

    public function testGuardarDosVecesActualizaEnLugarDeDuplicar()
    {
        $this->hayOcupadas(0);
        $this->db->onWrite('INSERT INTO event_ticketing', 1);

        Entradas::guardarConfig($this->db, 100, ['capacidad' => 10, 'precio' => 100]);

        $sql = $this->db->callsFor('INSERT INTO event_ticketing')[0]['sql'];

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    // --------------------------------------------------------- acreditarPago

    private function hayOrden(array $overrides = [])
    {
        $this->db->onSelect('FROM ticket_orders WHERE codigo', [array_merge([
            'id' => 1,
            'codigo' => 'ABC123DEF456',
            'link_id' => 100,
            'cantidad' => 2,
            'total' => '3000.00',
            'estado' => 'reservada',
        ], $overrides)]);
    }

    public function testUnPagoAprobadoAcreditaLaOrden()
    {
        $this->hayOrden();
        $this->db->onWrite("UPDATE ticket_orders", 1);

        $r = Entradas::acreditarPago($this->db, 'ABC123DEF456', '99', 'approved');

        $this->assertTrue($r['acreditada']);
    }

    /**
     * Mercado Pago reintenta los avisos y no garantiza mandarlos una sola vez:
     * reprocesar el mismo pago no puede acreditar dos veces.
     */
    public function testReprocesarElMismoPagoNoAcreditaDeNuevo()
    {
        $this->hayOrden(['estado' => 'pagada']);

        $r = Entradas::acreditarPago($this->db, 'ABC123DEF456', '99', 'approved');

        $this->assertFalse($r['acreditada']);
        $this->assertSame('ya estaba pagada', $r['motivo']);
        $this->assertNoWrites();
    }

    public function testLaAcreditacionCondicionaElEstadoEnElUpdate()
    {
        $this->hayOrden();
        $this->db->onWrite('UPDATE ticket_orders', 1);

        Entradas::acreditarPago($this->db, 'ABC123DEF456', '99', 'approved');

        $sql = $this->db->callsFor('UPDATE ticket_orders')[0]['sql'];

        // Sin la condición, dos avisos simultáneos podrían pisarse.
        $this->assertStringContainsString("estado IN ('reservada', 'vencida')", $sql);
    }

    /**
     * Si la reserva venció mientras la persona pagaba, se acredita igual:
     * dejarla afuera por unos segundos de demora es peor que pasarse por una.
     */
    public function testUnaReservaVencidaQuePagaSeAcreditaIgual()
    {
        $this->hayOrden(['estado' => 'vencida']);
        $this->db->onWrite('UPDATE ticket_orders', 1);

        $this->assertTrue(Entradas::acreditarPago($this->db, 'ABC123DEF456', '99', 'approved')['acreditada']);
    }

    public function testUnPagoRechazadoLiberaElCupo()
    {
        $this->hayOrden();
        $this->db->onWrite('UPDATE ticket_orders', 1);

        $r = Entradas::acreditarPago($this->db, 'ABC123DEF456', '99', 'rejected');

        $this->assertFalse($r['acreditada']);
        $this->assertStringContainsString("estado = 'rechazada'", $this->db->callsFor('UPDATE ticket_orders')[0]['sql']);
    }

    /** Un pago en proceso todavía puede aprobarse: la reserva sigue viva. */
    public function testUnPagoEnCursoNoTocaLaOrden()
    {
        $this->hayOrden();

        $r = Entradas::acreditarPago($this->db, 'ABC123DEF456', '99', 'in_process');

        $this->assertFalse($r['acreditada']);
        $this->assertNoWrites();
    }

    public function testUnAvisoDeUnaOrdenInexistenteNoRompe()
    {
        $r = Entradas::acreditarPago($this->db, 'NO-EXISTE', '99', 'approved');

        $this->assertFalse($r['acreditada']);
        $this->assertSame('orden inexistente', $r['motivo']);
    }

    // -------------------------------------------------------- ventasDelEvento

    private function hayVentas(array $ordenes)
    {
        $this->db->onSelect('FROM ticket_orders WHERE link_id', $ordenes);
    }

    private function venta(array $overrides = [])
    {
        return array_merge([
            'id' => 1, 'codigo' => 'ABC123DEF456', 'nombre' => 'Ana Gómez',
            'email' => 'ana@example.com', 'telefono' => '1122334455',
            'cantidad' => 2, 'precio_unitario' => '1500.00', 'total' => '3000.00',
            'comision' => '300.00', 'comision_porcentaje' => '10.00',
            'moneda' => 'ARS', 'estado' => 'pagada', 'reserva_vence_en' => null,
            'mp_payment_id' => '99', 'pagada_en' => '2026-08-16 20:00:00',
            'created_at' => '2026-08-16 19:58:00', 'vencida' => 0,
        ], $overrides);
    }

    public function testElResumenCuentaSoloLasPagadas()
    {
        $this->hayVentas([
            $this->venta(['cantidad' => 2, 'total' => '3000.00', 'estado' => 'pagada']),
            $this->venta(['cantidad' => 5, 'total' => '7500.00', 'estado' => 'reservada']),
            $this->venta(['cantidad' => 9, 'total' => '13500.00', 'estado' => 'rechazada']),
        ]);

        $r = Entradas::ventasDelEvento($this->db, 100);

        $this->assertSame(2, $r['resumen']['vendidas']);
        $this->assertSame(3000.0, $r['resumen']['recaudado']);
    }

    public function testElResumenMuestraAparteLoReservado()
    {
        $this->hayVentas([
            $this->venta(['cantidad' => 2, 'estado' => 'pagada']),
            $this->venta(['cantidad' => 5, 'estado' => 'reservada']),
        ]);

        $this->assertSame(5, Entradas::ventasDelEvento($this->db, 100)['resumen']['reservadas']);
    }

    /**
     * En la base siguen figurando 'reservada' porque nadie las marca: mostrarlas
     * como vigentes daría una idea equivocada de cuánto se vendió.
     */
    public function testUnaReservaVencidaSeMuestraComoVencida()
    {
        $this->hayVentas([$this->venta(['estado' => 'reservada', 'vencida' => 1, 'cantidad' => 4])]);

        $r = Entradas::ventasDelEvento($this->db, 100);

        $this->assertSame('vencida', $r['ordenes'][0]['estado']);
        $this->assertSame(0, $r['resumen']['reservadas'], 'no puede contar como cupo tomado');
    }
}
