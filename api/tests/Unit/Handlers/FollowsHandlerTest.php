<?php

namespace Tests\Unit\Handlers;

use FollowsHandler;
use Request;
use Tests\Support\HandlerTestCase;

class FollowsHandlerTest extends HandlerTestCase
{
    // ============================================================ autenticación

    public function testFollowRechazaPeticionSinCabeceraAuthorization()
    {
        $res = FollowsHandler::follow($this->db, new Request('POST', ['page_id' => 1]));

        $this->assertError(401, $res, 'Token no proporcionado');
    }

    public function testFollowDistingueTokenInvalidoDeTokenAusente()
    {
        // El frontend usa este matiz para decidir si reintenta o cierra sesión.
        $res = FollowsHandler::follow($this->db, $this->conTokenInvalido('POST'));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testFollowingRechazaPeticionSinCabeceraAuthorization()
    {
        $res = FollowsHandler::following($this->db, new Request('GET'));

        $this->assertError(401, $res, 'Token no proporcionado');
    }

    public function testFollowingDistingueTokenInvalido()
    {
        $res = FollowsHandler::following($this->db, $this->conTokenInvalido('GET'));

        $this->assertError(401, $res, 'Unauthorized');
    }

    // ================================================================ POST

    public function testSeguirExigePageId()
    {
        $res = FollowsHandler::follow($this->db, $this->post([], $this->user()));

        $this->assertError(400, $res, 'ID de página requerido');
        $this->assertNoWrites();
    }

    public function testSeguirRechazaPageIdCero()
    {
        $res = FollowsHandler::follow($this->db, $this->post(['page_id' => 0], $this->user()));

        $this->assertError(400, $res, 'ID de página requerido');
    }

    public function testSeguirRechazaPaginaInexistente()
    {
        $res = FollowsHandler::follow($this->db, $this->post(['page_id' => 99], $this->user()));

        $this->assertError(404, $res, 'Página no encontrada');
        $this->assertNoWrites();
    }

    public function testSeguirGuardaElSeguimiento()
    {
        $this->existePagina();

        $res = FollowsHandler::follow($this->db, $this->post(['page_id' => 3], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['success']);
        $this->assertSame(3, $res->body['page_id']);
        $this->assertSame([9, 3, 1, 50.00], $this->db->paramsFor('INSERT INTO page_followers'));
    }

    public function testSeguirUsaLosValoresPorDefecto()
    {
        $this->existePagina();

        $res = FollowsHandler::follow($this->db, $this->post(['page_id' => 3], $this->user()));

        $this->assertTrue($res->body['notify_all_events'], 'notifica todo por defecto');
        $this->assertSame(50.00, $res->body['max_distance_km']);
    }

    public function testSeguirRespetaLasPreferenciasEnviadas()
    {
        $this->existePagina();

        $res = FollowsHandler::follow($this->db, $this->post([
            'page_id' => 3,
            'notify_all_events' => false,
            'max_distance_km' => 12.5,
        ], $this->user(9)));

        $this->assertFalse($res->body['notify_all_events']);
        $this->assertSame(12.5, $res->body['max_distance_km']);
        $this->assertSame([9, 3, 0, 12.5], $this->db->paramsFor('INSERT INTO page_followers'));
    }

    public function testSeguirDosVecesActualizaEnLugarDeFallar()
    {
        $this->existePagina();

        FollowsHandler::follow($this->db, $this->post(['page_id' => 3], $this->user()));

        $sql = $this->db->callsFor('INSERT INTO page_followers')[0]['sql'];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    public function testSeguirConvierteElBooleanoAEntero()
    {
        $this->existePagina();

        FollowsHandler::follow($this->db, $this->post([
            'page_id' => 3,
            'notify_all_events' => true,
        ], $this->user()));

        $this->assertSame(1, $this->db->paramsFor('INSERT INTO page_followers')[2]);
    }

    // ============================================================== DELETE

    public function testDejarDeSeguirExigePageId()
    {
        $res = FollowsHandler::follow($this->db, $this->delete([], $this->user()));

        $this->assertError(400, $res, 'ID de página requerido');
    }

    public function testDejarDeSeguirBorraElRegistro()
    {
        $this->db->onWrite('DELETE FROM page_followers', 1);

        $res = FollowsHandler::follow($this->db, $this->delete(['page_id' => '3'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['success']);
        $this->assertSame([9, 3], $this->db->paramsFor('DELETE FROM page_followers'));
    }

    public function testDejarDeSeguirAvisaSiNoSeguia()
    {
        $this->db->onWrite('DELETE FROM page_followers', 0);

        $res = FollowsHandler::follow($this->db, $this->delete(['page_id' => '3'], $this->user()));

        $this->assertError(404, $res, 'No seguías esta página');
    }

    // ================================================================= GET

    public function testEstadoExigePageId()
    {
        $res = FollowsHandler::follow($this->db, $this->get([], $this->user()));

        $this->assertError(400, $res, 'ID de página requerido');
    }

    public function testEstadoDevuelveFalseSiNoSigue()
    {
        $res = FollowsHandler::follow($this->db, $this->get(['page_id' => '3'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(['is_following' => false], $res->body);
    }

    public function testEstadoDevuelveLasPreferenciasConTiposReales()
    {
        $this->db->onSelect('SELECT notify_all_events, max_distance_km, created_at', [[
            'notify_all_events' => '1',
            'max_distance_km' => '25.50',
            'created_at' => '2026-01-01 10:00:00',
        ]]);

        $res = FollowsHandler::follow($this->db, $this->get(['page_id' => '3'], $this->user()));

        $this->assertTrue($res->body['is_following']);
        $this->assertSame(true, $res->body['notify_all_events'], 'MySQL devuelve "1", debe ser bool');
        $this->assertSame(25.50, $res->body['max_distance_km'], 'debe ser float, no string');
        $this->assertSame('2026-01-01 10:00:00', $res->body['following_since']);
    }

    public function testFollowRechazaMetodoNoSoportado()
    {
        $res = FollowsHandler::follow($this->db, new Request(
            'PATCH', [], [], $this->user(), [], ['Authorization' => 'Bearer x']
        ));

        $this->assertError(405, $res, 'Método no permitido');
    }

    // ============================================================ following

    public function testFollowingDevuelveListaVacia()
    {
        $res = FollowsHandler::following($this->db, $this->get([], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(['following' => [], 'total' => 0], $res->body);
    }

    public function testFollowingConvierteLosTipos()
    {
        $this->db->onSelect('FROM page_followers pf INNER JOIN pages p', [
            [
                'id' => '7',
                'slug' => 'mi-pagina',
                'title' => 'Mi página',
                'notify_all_events' => '0',
                'max_distance_km' => '30.00',
                'follower_count' => '12',
            ],
        ]);

        $res = FollowsHandler::following($this->db, $this->get([], $this->user()));

        $pagina = $res->body['following'][0];

        $this->assertSame(7, $pagina['id']);
        $this->assertFalse($pagina['notify_all_events']);
        $this->assertSame(30.00, $pagina['max_distance_km']);
        $this->assertSame(1, $res->body['total']);
    }

    public function testFollowingFiltraPorElUsuarioDeLaSesion()
    {
        FollowsHandler::following($this->db, $this->get([], $this->user(9)));

        $this->assertSame([9], $this->db->paramsFor('FROM page_followers pf INNER JOIN pages p'));
    }

    public function testFollowingOrdenaPorMasRecientePrimero()
    {
        FollowsHandler::following($this->db, $this->get([], $this->user()));

        $sql = $this->db->callsFor('FROM page_followers pf INNER JOIN pages p')[0]['sql'];
        $this->assertStringContainsString('ORDER BY pf.created_at DESC', $sql);
    }

    public function testFollowingRechazaMetodoNoSoportado()
    {
        $res = FollowsHandler::following($this->db, $this->post([], $this->user()));

        $this->assertError(405, $res, 'Método no permitido');
    }

    // ------------------------------------------------------------ ayudantes

    private function existePagina()
    {
        $this->db->onSelect('SELECT id FROM pages WHERE id = ?', [['id' => 3]]);
    }
}
