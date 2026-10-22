<?php

namespace Tests\Unit\Handlers;

use SugerenciasHandler;
use Tests\Support\HandlerTestCase;

/**
 * El endpoint de sugerencias con FakePdo: validación de la entrada, la lista
 * cerrada de campos y que corte apenas junta tres. El orden de los niveles
 * contra una base de verdad está en Tests\Integration\SugerenciasTest.
 */
class SugerenciasHandlerTest extends HandlerTestCase
{
    const X = 'WHERE l.id = ? AND lg.type = ?';
    const PRODUCTOS = 'INNER JOIN digital_products dp ON dp.link_id = l.id';
    const EVENTOS = 'ORDER BY l.event_date, l.event_time, l.id LIMIT';
    const TICKETING = 'FROM event_ticketing WHERE link_id = ?';

    private function pedir(array $query)
    {
        return SugerenciasHandler::listar($this->db, $this->get($query));
    }

    private function x($pageId = 5, array $extra = [])
    {
        $this->db->onSelect(self::X, [array_merge([
            'id' => 100, 'page_id' => $pageId, 'event_latitude' => null, 'event_longitude' => null,
        ], $extra)]);
    }

    private function productoDeLaBase($id, array $extra = [])
    {
        return array_merge([
            'id' => (string) $id, 'text' => "Producto $id", 'image_url' => "https://img/$id.jpg",
            'precio' => '11900.00', 'page_title' => 'La Sala',
            // Lo que no tiene que salir aunque la consulta lo traiga.
            'archivo_ruta' => 'productos/secreto', 'oculto' => '0', 'ventas' => '12',
        ], $extra);
    }

    private function eventoDeLaBase($id, array $extra = [])
    {
        return array_merge([
            'id' => (string) $id, 'text' => "Evento $id", 'image_url' => null,
            'event_date' => '2026-10-24', 'event_time' => '21:00:00', 'page_title' => 'La Sala',
            'event_address' => 'Corrientes 1234', 'description' => 'Largo',
        ], $extra);
    }

    // -------------------------------------------------------------- entrada

    public function testRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, SugerenciasHandler::listar($this->db, $this->post(['tipo' => 'evento', 'id' => 1])));
    }

    public function testExigeUnTipoConocido()
    {
        $this->assertError(400, $this->pedir(['tipo' => 'pagina', 'id' => '1']), 'Tipo inválido');
        $this->assertError(400, $this->pedir(['id' => '1']), 'Tipo inválido');
    }

    public function testExigeUnId()
    {
        $this->assertError(400, $this->pedir(['tipo' => 'evento']), 'ID requerido');
        $this->assertError(400, $this->pedir(['tipo' => 'evento', 'id' => '-3']), 'ID requerido');
    }

    public function testUnXQueNoExisteDa404()
    {
        $this->assertError(404, $this->pedir(['tipo' => 'producto', 'id' => '9']), 'Producto no encontrado');
        $this->assertSame([9, 'productos'], $this->db->paramsFor(self::X));
    }

    public function testUnEventoSeBuscaEnUnGrupoDeEventos()
    {
        $this->assertError(404, $this->pedir(['tipo' => 'evento', 'id' => '9']), 'Evento no encontrado');
        $this->assertSame([9, 'eventos'], $this->db->paramsFor(self::X));
    }

    public function testUnErrorDeLaBaseDa500()
    {
        $this->db->failOn(self::X);

        $this->assertStatus(500, $this->pedir(['tipo' => 'evento', 'id' => '1']));
    }

    // ------------------------------------------------------------ respuesta

    public function testCadaSugerenciaTraeSoloLaListaCerradaDeCampos()
    {
        $this->x();
        $this->db->onSelect(self::PRODUCTOS, [$this->productoDeLaBase(201)]);
        $this->db->onSelect(self::EVENTOS, [$this->eventoDeLaBase(301)]);

        $res = $this->pedir(['tipo' => 'evento', 'id' => '100']);

        $this->assertStatus(200, $res);
        $this->assertSame([
            [
                'tipo' => 'producto', 'id' => 201, 'text' => 'Producto 201',
                'image_url' => 'https://img/201.jpg', 'precio' => 11900.0, 'page_title' => 'La Sala',
            ],
            [
                'tipo' => 'evento', 'id' => 301, 'text' => 'Evento 301', 'image_url' => null,
                'event_date' => '2026-10-24', 'event_time' => '21:00:00', 'page_title' => 'La Sala',
            ],
        ], $res->body['sugerencias']);
        $this->assertStringNotContainsString('archivo_ruta', json_encode($res->body));
    }

    public function testConTresProductosNoCalculaLosNivelesSiguientes()
    {
        $this->x();
        $this->db->onSelect(self::PRODUCTOS, [
            $this->productoDeLaBase(201), $this->productoDeLaBase(202),
            $this->productoDeLaBase(203), $this->productoDeLaBase(204),
        ]);

        $res = $this->pedir(['tipo' => 'evento', 'id' => '100']);

        $this->assertSame([201, 202, 203], array_column($res->body['sugerencias'], 'id'));
        $this->assertSame(1, $this->db->countCalls(self::PRODUCTOS));
        $this->assertFalse($this->db->ran(self::EVENTOS), 'con tres no hace falta buscar eventos');
        $this->assertFalse($this->db->ran('event_collaborations'));
    }

    /** La disponibilidad se pide de los candidatos, no de todos los eventos. */
    public function testLaDisponibilidadSePideSoloHastaCompletar()
    {
        $this->x();
        $this->db->onSelect(self::EVENTOS, [
            $this->eventoDeLaBase(301), $this->eventoDeLaBase(302), $this->eventoDeLaBase(303),
            $this->eventoDeLaBase(304), $this->eventoDeLaBase(305),
        ]);

        $res = $this->pedir(['tipo' => 'evento', 'id' => '100']);

        $this->assertSame([301, 302, 303], array_column($res->body['sugerencias'], 'id'));
        $this->assertSame(3, $this->db->countCalls(self::TICKETING));
    }

    public function testSaltaLosEventosAgotados()
    {
        $this->x();
        $this->db->onSelect(self::EVENTOS, [$this->eventoDeLaBase(301), $this->eventoDeLaBase(302)]);
        $this->db->onSelect(self::TICKETING, [[
            'activo' => 1, 'capacidad' => 10, 'precio' => 5000, 'moneda' => 'ARS', 'max_por_compra' => 4,
        ]]);
        $this->db->onSelect('FROM ticket_orders', [['10']]);

        $res = $this->pedir(['tipo' => 'evento', 'id' => '100']);

        $this->assertSame([302], array_column($res->body['sugerencias'], 'id'));
    }

    public function testNuncaSugiereAX()
    {
        $this->x();
        $this->db->onSelect(self::EVENTOS, [$this->eventoDeLaBase(100), $this->eventoDeLaBase(301)]);

        $res = $this->pedir(['tipo' => 'evento', 'id' => '100']);

        $this->assertSame([301], array_column($res->body['sugerencias'], 'id'));
    }

    // --------------------------------------------------------------- página

    public function testUnaPaginaQueNoMuestraAXSeIgnora()
    {
        $this->x(5);

        $this->pedir(['tipo' => 'evento', 'id' => '100', 'pagina' => '77']);

        $this->assertSame([100, 77], $this->db->paramsFor("ec.status = 'accepted' AND cg.type = 'eventos' AND cg.page_id = ?"));
        $this->assertSame([5, 5], $this->db->paramsFor(self::PRODUCTOS), 'V pasa a ser la dueña');
    }

    public function testUnaColaboradoraAceptadaEsLaPaginaDondeSeVe()
    {
        $this->x(5);
        $this->db->onSelect("cg.type = 'eventos' AND cg.page_id = ?", [['1']]);

        $this->pedir(['tipo' => 'evento', 'id' => '100', 'pagina' => '77']);

        $productos = $this->db->callsFor(self::PRODUCTOS);
        $this->assertSame([77, 77], $productos[0]['params'], 'primero los productos de V');
        $this->assertSame([5, 5], $productos[1]['params'], 'después los de la dueña');
    }

    public function testUnProductoNoTieneColaboradoras()
    {
        $this->x(5);

        $this->pedir(['tipo' => 'producto', 'id' => '100', 'pagina' => '77']);

        $this->assertFalse($this->db->ran('cg.page_id = ?'), 'pagina no se valida: V es la dueña');
        $this->assertFalse($this->db->ran('ORDER BY ec.id'), 'sin nivel de colaboradoras');
        $this->assertSame([5, 5], $this->db->paramsFor(self::PRODUCTOS));
    }

    // ------------------------------------------------------------- cercanos

    public function testSinNadaEnLasPaginasBuscaCercaDeX()
    {
        $this->x(5, ['event_latitude' => '-34.6', 'event_longitude' => '-58.4']);
        $this->db->onSelect(self::EVENTOS, []);
        $this->db->onSelect('HAVING distancia <= 30', [$this->eventoDeLaBase(401)]);

        $res = $this->pedir(['tipo' => 'evento', 'id' => '100']);

        $this->assertSame([401], array_column($res->body['sugerencias'], 'id'));
        $this->assertSame([-34.6, -34.6, -58.4], array_slice($this->db->paramsFor('HAVING distancia <= 30'), 0, 3));
        $this->assertTrue($this->db->ran('HAVING distancia IS NULL OR distancia > 30'), 'completa con los más lejanos');
    }

    public function testSinCoordenadasNiReferenciaOrdenaPorFecha()
    {
        $this->x(5);

        $res = $this->pedir(['tipo' => 'producto', 'id' => '100']);

        $this->assertStatus(200, $res);
        $this->assertSame([], $res->body['sugerencias']);
        $this->assertFalse($this->db->ran('distancia'));
        $this->assertTrue($this->db->ran("WHERE lg.type = 'eventos' AND l.event_date >= ? ORDER BY l.event_date"));
    }
}
