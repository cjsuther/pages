<?php

namespace Tests\Unit\Lib;

use Tests\Support\HandlerTestCase;
use VentasCompartidas;

class VentasCompartidasTest extends HandlerTestCase
{
    private function hayEvento(array $overrides = [])
    {
        $this->db->onSelect('FROM event_ticketing WHERE link_id', [array_merge([
            'id' => 1, 'link_id' => 100, 'activo' => 1, 'capacidad' => 120,
            'precio' => '15000.00', 'moneda' => 'ARS', 'max_por_compra' => 10, 'plano' => null,
        ], $overrides)]);
    }

    private function hayTotales(array $overrides = [])
    {
        $this->db->onSelect('AS vendidas', [array_merge([
            'vendidas' => 84, 'recaudado' => '1260000.00', 'ingresadas' => 0,
            'reservadas' => 4, 'compras' => 40,
        ], $overrides)]);
    }

    public function testUnEventoSinVentaConfiguradaNoRompe()
    {
        $r = VentasCompartidas::estado($this->db, 100);

        $this->assertNull($r['venta']);
        $this->assertSame([], $r['ocupados']);
    }

    public function testCuentaLoVendidoYLoQueQueda()
    {
        $this->hayEvento();
        $this->hayTotales();

        $venta = VentasCompartidas::estado($this->db, 100)['venta'];

        $this->assertSame(84, $venta['vendidas']);
        $this->assertSame(4, $venta['reservadas']);
        $this->assertSame(32, $venta['disponibles']);
        $this->assertSame(1260000.0, $venta['recaudado']);
    }

    /** Si contaran las reservas vencidas, el link mostraría un show más vendido. */
    public function testLasReservasVencidasNoCuentan()
    {
        $this->hayEvento();
        $this->hayTotales();

        VentasCompartidas::estado($this->db, 100);

        $this->assertStringContainsString('reserva_vence_en > NOW()', $this->db->callsFor('AS vendidas')[0]['sql']);
    }

    /**
     * Quien recibe el link no administra la página: los datos de contacto de
     * los compradores no pueden salir de acá.
     */
    public function testNoSePidenDatosDeLosCompradores()
    {
        $this->hayEvento();
        $this->hayTotales();

        VentasCompartidas::estado($this->db, 100);

        foreach ($this->db->log() as $consulta) {
            $this->assertStringNotContainsString('email', $consulta['sql']);
            $this->assertStringNotContainsString('telefono', $consulta['sql']);
            $this->assertStringNotContainsString('nombre', $consulta['sql']);
        }
    }

    public function testConPlanoTraeLosLugaresOcupados()
    {
        $plano = ['ancho' => 10, 'alto' => 4, 'elementos' => [
            ['tipo' => 'fila', 'nombre' => 'A', 'butacas' => 4, 'desde' => 1, 'x' => 0, 'y' => 0],
        ]];
        $this->hayEvento(['plano' => json_encode($plano)]);
        $this->hayTotales();
        $this->db->onSelect('FROM ticket_order_lugares tl', [['lugar' => 'f:A:2']]);

        $r = VentasCompartidas::estado($this->db, 100);

        $this->assertSame($plano, $r['plano']);
        $this->assertSame(['f:A:2'], $r['ocupados']);
    }

    /** No alcanza con cuántas van: quien mira quiere saber si se movió esta semana. */
    public function testElRitmoTraeLosTreintaDiasAunqueNoSeHayaVendido()
    {
        $this->hayEvento();
        $this->hayTotales();
        $ayer = date('Y-m-d', strtotime(\Fechas::hoy() . ' -1 day'));
        $this->db->onSelect('DATE(pagada_en)', [['dia' => $ayer, 'vendidas' => '12']]);

        $ritmo = VentasCompartidas::estado($this->db, 100)['ritmo'];

        $this->assertCount(30, $ritmo);
        $this->assertSame(\Fechas::hoy(), $ritmo[29]['dia'], 'el último día es hoy');
        $this->assertSame(['dia' => $ayer, 'vendidas' => 12], $ritmo[28]);
        $this->assertSame(0, $ritmo[0]['vendidas'], 'un día sin ventas vale cero, no se saltea');
    }

    /**
     * Tres días sueltos se dibujaban pegados, como si hubieran sido seguidos.
     * Los días vacíos son parte de la respuesta: muestran que se frenó.
     */
    public function testLosDiasVaciosTambienVienen()
    {
        $this->hayEvento();
        $this->hayTotales();
        $this->db->onSelect('DATE(pagada_en)', []);

        $ritmo = VentasCompartidas::estado($this->db, 100)['ritmo'];

        $this->assertCount(30, $ritmo);
        $this->assertSame(0, array_sum(array_column($ritmo, 'vendidas')));
    }
}
