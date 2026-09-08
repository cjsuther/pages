<?php

namespace Tests\Unit\Handlers;

use PlataformaHandler;
use Plataforma;
use Tests\Support\HandlerTestCase;

/**
 * El reporte cruza las ventas de todas las páginas: cuánto vendió cada una y
 * cuánta comisión dejó. Son datos de gente que no se conoce entre sí, así que
 * lo primero que hay que fijar es quién puede verlo.
 *
 * SUPERADMIN_EMAILS lo define tests/bootstrap.php como ['plataforma@test'].
 */
class PlataformaHandlerTest extends HandlerTestCase
{
    private function esLaPlataforma()
    {
        $this->db->onSelect('SELECT email FROM users', [['plataforma@test']]);
    }

    private function hayVentas(array $resumen = [])
    {
        $this->db->onSelect('FROM ticket_orders o WHERE', [array_merge([
            'ventas' => 3, 'recaudado' => '70000.00', 'pedida' => '1050.00',
            'cobrada' => '1050.00', 'sin_dato' => 0,
        ], $resumen)]);
    }

    // --------------------------------------------------------------- acceso

    public function testExigeSesion()
    {
        $this->assertStatus(401, PlataformaHandler::comisiones($this->db, $this->get()));
    }

    /**
     * 404 y no 403: para quien no administra la plataforma este reporte no
     * existe. Un 403 confirmaría que hay algo detrás de esta URL.
     */
    public function testUnUsuarioComunNiSeEnteraDeQueExiste()
    {
        $this->db->onSelect('SELECT email FROM users', [['alguien@test']]);

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertStatus(404, $res);
    }

    /** Y sobre todo: no llega a consultar ninguna venta. */
    public function testUnUsuarioComunNoLeeNingunaVenta()
    {
        $this->db->onSelect('SELECT email FROM users', [['alguien@test']]);

        PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertSame(0, $this->db->countCalls('FROM ticket_orders'));
    }

    public function testLaPlataformaSiLoVe()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertSame(1050.0, $res->body['resumen']['cobrada']);
    }

    public function testSoloAceptaGet()
    {
        $this->esLaPlataforma();

        $res = PlataformaHandler::comisiones($this->db, $this->post([], $this->user(9)));

        $this->assertStatus(405, $res);
    }

    // ---------------------------------------------------------------- datos

    /**
     * La diferencia entre lo pedido y lo cobrado es la razón de ser del
     * reporte: es plata que se pidió y no entró.
     */
    public function testInformaLoQueSePidioYNoSeCobro()
    {
        $this->esLaPlataforma();
        $this->hayVentas(['pedida' => '1050.00', 'cobrada' => '600.00']);

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertSame(450.0, $res->body['resumen']['diferencia']);
    }

    /** Las gratis no pasan por Mercado Pago: no hay comisión que revisar. */
    public function testSoloMiraVentasPagadasYConPrecio()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor('FROM ticket_orders o WHERE')[0]['sql'];
        $this->assertStringContainsString("o.estado = 'pagada'", $sql);
        $this->assertStringContainsString('o.total > 0', $sql);
    }

    public function testFiltraPorRangoDeFechas()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        PlataformaHandler::comisiones($this->db,
            $this->get(['desde' => '2026-09-01', 'hasta' => '2026-09-30'], $this->user(9)));

        $llamada = $this->db->callsFor('FROM ticket_orders o WHERE')[0];
        $this->assertStringContainsString('DATE(o.pagada_en) >=', $llamada['sql']);
        $this->assertSame(['2026-09-01', '2026-09-30'], $llamada['params']);
    }

    /** Una fecha que no es una fecha no puede llegar a la consulta. */
    public function testUnaFechaInvalidaSeIgnora()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        PlataformaHandler::comisiones($this->db, $this->get(['desde' => 'ayer'], $this->user(9)));

        $llamada = $this->db->callsFor('FROM ticket_orders o WHERE')[0];
        $this->assertStringNotContainsString('pagada_en', $llamada['sql']);
        $this->assertSame([], $llamada['params']);
    }

    public function testAgrupaPorMesYPorPagina()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertArrayHasKey('meses', $res->body);
        $this->assertArrayHasKey('paginas', $res->body);
        $this->assertArrayHasKey('revisar', $res->body);
    }

    /** Sólo las que Mercado Pago confirmó que cobró de menos. */
    public function testLaListaARevisarSonLasQueCobraronDeMenos()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor('o.mp_comision_cobrada <')[0]['sql'];
        $this->assertStringContainsString('o.mp_comision_cobrada IS NOT NULL', $sql);
    }
}
