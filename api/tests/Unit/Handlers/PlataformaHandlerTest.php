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
        $this->assertSame(0, $this->db->countCalls('FROM product_orders'));
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

    // ------------------------------------------------- productos digitales

    private function hayVentasDeProductos(array $resumen = [])
    {
        $this->db->onSelect(self::DIGITALES, [array_merge([
            'ventas' => 2, 'recaudado' => '5000.00', 'pedida' => '75.00',
            'cobrada' => '50.00', 'sin_dato' => 1,
        ], $resumen)]);
    }

    private function hayVentasDeFisicos(array $resumen = [])
    {
        $this->db->onSelect(self::FISICOS, [array_merge([
            'ventas' => 1, 'recaudado' => '5500.00', 'pedida' => '82.50',
            'cobrada' => '82.50', 'sin_dato' => 0,
        ], $resumen)]);
    }

    /** El resumen de cada tipo de producto, sin los joins de la página. */
    const DIGITALES = "FROM product_orders o INNER JOIN digital_products dp ON dp.id = o.product_id AND dp.tipo = 'digital' WHERE";
    const FISICOS = "FROM product_orders o INNER JOIN digital_products dp ON dp.id = o.product_id AND dp.tipo = 'fisico' WHERE";

    /** Sin ventas de productos, el reporte es el mismo de siempre. */
    public function testSinProductosLosNumerosDeEntradasNoCambian()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertSame(3, $res->body['resumen']['ventas']);
        $this->assertSame(70000.0, $res->body['resumen']['recaudado']);
        $this->assertSame(1050.0, $res->body['resumen']['cobrada']);
        $this->assertSame($res->body['resumen'], $res->body['por_tipo']['entradas']);
        $this->assertSame(0, $res->body['por_tipo']['productos']['ventas']);
        $this->assertSame(0, $res->body['por_tipo']['fisicos']['ventas']);
    }

    public function testElTotalSumaEntradasYProductosYLosDistingue()
    {
        $this->esLaPlataforma();
        $this->hayVentas();
        $this->hayVentasDeProductos();

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertSame(5, $res->body['resumen']['ventas']);
        $this->assertSame(75000.0, $res->body['resumen']['recaudado']);
        $this->assertSame(1125.0, $res->body['resumen']['pedida']);
        $this->assertSame(1100.0, $res->body['resumen']['cobrada']);
        $this->assertSame(25.0, $res->body['resumen']['diferencia']);
        $this->assertSame(1, $res->body['resumen']['sin_dato']);

        // Lo de entradas, igual que sin productos.
        $this->assertSame(3, $res->body['por_tipo']['entradas']['ventas']);
        $this->assertSame(70000.0, $res->body['por_tipo']['entradas']['recaudado']);
        $this->assertSame(1050.0, $res->body['por_tipo']['entradas']['cobrada']);
        $this->assertSame(2, $res->body['por_tipo']['productos']['ventas']);
        $this->assertSame(5000.0, $res->body['por_tipo']['productos']['recaudado']);
    }

    /** Los productos se miran con el mismo criterio y el mismo rango que las entradas. */
    public function testLosProductosUsanElMismoFiltro()
    {
        $this->esLaPlataforma();
        $this->hayVentas();
        $this->hayVentasDeProductos();

        PlataformaHandler::comisiones($this->db,
            $this->get(['desde' => '2026-09-01', 'hasta' => '2026-09-30'], $this->user(9)));

        foreach ([self::DIGITALES, self::FISICOS] as $fuente) {
            $llamada = $this->db->callsFor($fuente)[0];
            $this->assertStringContainsString("o.estado = 'pagada'", $llamada['sql']);
            $this->assertStringContainsString('o.total > 0', $llamada['sql']);
            $this->assertSame(['2026-09-01', '2026-09-30'], $llamada['params']);
        }
    }

    // ---------------------------------------------------- productos físicos

    /**
     * Los físicos comparten tabla con los digitales pero se cuentan aparte:
     * cada consulta de productos dice de qué tipo es, y ninguna los mezcla.
     */
    public function testLosFisicosSeCuentanAparteDeLosDigitales()
    {
        $this->esLaPlataforma();
        $this->hayVentas();
        $this->hayVentasDeProductos();
        $this->hayVentasDeFisicos();

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertSame(2, $res->body['por_tipo']['productos']['ventas']);
        $this->assertSame(5000.0, $res->body['por_tipo']['productos']['recaudado']);
        $this->assertSame(1, $res->body['por_tipo']['fisicos']['ventas']);
        $this->assertSame(5500.0, $res->body['por_tipo']['fisicos']['recaudado']);
        $this->assertSame(82.5, $res->body['por_tipo']['fisicos']['cobrada']);
        $this->assertSame(6, $res->body['resumen']['ventas']);
        $this->assertSame(80500.0, $res->body['resumen']['recaudado']);
        $this->assertSame(1182.5, $res->body['resumen']['cobrada']);

        foreach ($this->db->callsFor('FROM product_orders o') as $llamada) {
            $this->assertMatchesRegularExpression("/dp\.tipo = '(digital|fisico)'/", $llamada['sql']);
        }
        $this->assertSame(4, $this->db->countCalls("dp.tipo = 'fisico'"), 'resumen, meses, páginas y a revisar');
    }

    /** El nombre de un físico sale de la foto del pedido. */
    public function testElFisicoSeNombraPorLaFotoDelPedido()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor("dp.tipo = 'fisico' LEFT JOIN links")[1]['sql'];
        $this->assertStringContainsString('COALESCE(o.producto_titulo, l.text, dp.titulo) AS evento', $sql);
    }

    public function testLosMesesYLasPaginasSumanLosDosTiposSinPerderElDetalle()
    {
        $this->esLaPlataforma();
        $fila = function ($extra) {
            return array_merge(['ventas' => 1, 'recaudado' => '1000.00', 'pedida' => '15.00', 'cobrada' => '15.00', 'sin_dato' => 0], $extra);
        };
        $this->db->onSelect('FROM ticket_orders o WHERE', [$fila([])]);
        $this->db->onSelect("FROM ticket_orders o WHERE o.estado = 'pagada' AND o.total > 0 AND o.pagada_en IS NOT NULL", [
            $fila(['mes' => '2026-09']),
        ]);
        $this->db->onSelect('FROM ticket_orders o INNER JOIN links', [
            $fila(['id' => 1, 'title' => 'La Sala', 'url_slug' => 'la-sala']),
        ]);
        $this->db->onSelect(self::DIGITALES, [$fila([])]);
        $this->db->onSelect(self::DIGITALES . " o.estado = 'pagada' AND o.total > 0 AND o.pagada_en IS NOT NULL", [
            $fila(['mes' => '2026-09']),
            $fila(['mes' => '2026-10']),
        ]);
        $this->db->onSelect("dp.tipo = 'digital' LEFT JOIN links", [
            $fila(['id' => 1, 'title' => 'La Sala', 'url_slug' => 'la-sala']),
            $fila(['id' => 2, 'title' => 'Otra', 'url_slug' => 'otra', 'cobrada' => '99.00']),
        ]);
        $this->db->onSelect(self::FISICOS, [$fila([])]);
        $this->db->onSelect(self::FISICOS . " o.estado = 'pagada' AND o.total > 0 AND o.pagada_en IS NOT NULL", [
            $fila(['mes' => '2026-10']),
        ]);
        $this->db->onSelect("dp.tipo = 'fisico' LEFT JOIN links", [
            $fila(['id' => 1, 'title' => 'La Sala', 'url_slug' => 'la-sala']),
        ]);

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertSame(['2026-10', '2026-09'], array_column($res->body['meses'], 'mes'));
        $this->assertSame([2, 2], array_column($res->body['meses'], 'ventas'));
        $this->assertSame([1, 1], array_column($res->body['meses'], 'ventas_productos'));
        $this->assertSame([1, 0], array_column($res->body['meses'], 'ventas_fisicos'));

        $this->assertSame([2, 1], array_column($res->body['paginas'], 'id'), 'ordenadas por lo cobrado');
        $laSala = $res->body['paginas'][1];
        $this->assertSame(3, $laSala['ventas']);
        $this->assertSame(1, $laSala['ventas_productos']);
        $this->assertSame(1, $laSala['ventas_fisicos']);
        $this->assertSame(3000.0, $laSala['recaudado']);
    }

    /** Un producto borrado se reconoce por la foto: su página y su título. */
    public function testLosProductosSeBuscanPorLaFotoSiSeBorraronElLink()
    {
        $this->esLaPlataforma();
        $this->hayVentas();

        PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor("dp.tipo = 'digital' LEFT JOIN links")[0]['sql'];
        $this->assertStringContainsString('LEFT JOIN links l ON l.id = dp.link_id', $sql);
        $this->assertStringContainsString('COALESCE(lg.page_id, dp.page_id)', $sql);
    }

    public function testLaListaARevisarDiceSiEsEntradaProductoOFisico()
    {
        $this->esLaPlataforma();
        $this->hayVentas();
        $this->db->onSelect('FROM ticket_orders o INNER JOIN links l ON l.id = o.link_id INNER JOIN link_groups lg ON lg.id = l.group_id INNER JOIN pages p ON p.id = lg.page_id WHERE', []);
        $this->db->onSelect('o.mp_comision_cobrada <', [[
            'codigo' => 'AAA', 'pagada_en' => '2026-09-01 10:00:00', 'total' => '1000.00', 'pedida' => '15.00',
            'cobrada' => '0.00', 'evento' => 'Show', 'pagina' => 'La Sala', 'url_slug' => 'la-sala',
        ]]);
        $this->db->onSelect('o.mp_comision_cobrada <', [[
            'codigo' => 'BBB', 'pagada_en' => '2026-09-05 10:00:00', 'total' => '2500.00', 'pedida' => '37.50',
            'cobrada' => '0.00', 'evento' => 'Guía', 'pagina' => 'La Sala', 'url_slug' => 'la-sala',
        ]]);
        $this->db->onSelect('o.mp_comision_cobrada <', [[
            'codigo' => 'CCC', 'pagada_en' => '2026-09-03 10:00:00', 'total' => '5500.00', 'pedida' => '82.50',
            'cobrada' => '0.00', 'evento' => 'Remera', 'pagina' => 'La Sala', 'url_slug' => 'la-sala',
        ]]);

        $res = PlataformaHandler::comisiones($this->db, $this->get([], $this->user(9)));

        $this->assertSame(['BBB', 'CCC', 'AAA'], array_column($res->body['revisar'], 'codigo'));
        $this->assertSame(['producto', 'fisico', 'entrada'], array_column($res->body['revisar'], 'tipo'));
        $this->assertSame(2500.0, $res->body['revisar'][0]['total']);
    }
}
