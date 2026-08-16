<?php

namespace Tests\Unit\Handlers;

use LinksHandler;
use Tests\Support\HandlerTestCase;

class LinksHandlerTest extends HandlerTestCase
{
    // =================================================================== index

    public function testIndexRechazaMetodosDistintosDePost()
    {
        $res = LinksHandler::index($this->db, $this->get());

        $this->assertError(405, $res, 'Method not allowed');
    }

    public function testIndexExigeSesion()
    {
        $res = LinksHandler::index($this->db, $this->post(['group_id' => 1, 'url' => 'u', 'text' => 't']));

        $this->assertError(401, $res, 'Unauthorized');
        $this->assertNoWrites();
    }

    /**
     * @dataProvider cuerposIncompletos
     */
    public function testIndexExigeLosTresCamposObligatorios($cuerpo)
    {
        $res = LinksHandler::index($this->db, $this->post($cuerpo, $this->user()));

        $this->assertError(400, $res, 'Group ID, URL, and text are required');
        $this->assertNoWrites();
    }

    public function cuerposIncompletos()
    {
        return [
            'vacío' => [[]],
            'sin group_id' => [['url' => 'u', 'text' => 't']],
            'sin url' => [['group_id' => 1, 'text' => 't']],
            'sin text' => [['group_id' => 1, 'url' => 'u']],
            'url en null' => [['group_id' => 1, 'url' => null, 'text' => 't']],
        ];
    }

    public function testIndexRechazaGrupoAjeno()
    {
        // Sin regla para PageAccess, canManageGroup devuelve false.
        $res = LinksHandler::index($this->db, $this->post(
            ['group_id' => 99, 'url' => 'u', 'text' => 't'],
            $this->user()
        ));

        $this->assertError(404, $res, 'Group not found');
        $this->assertNoWrites();
    }

    public function testIndexCreaElLinkYDevuelve201()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'links']]);
        $this->db->onInsert('INSERT INTO links', 55);
        $this->db->onSelect('SELECT * FROM links WHERE id = ?', [['id' => 55, 'text' => 'Mi link']]);

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'https://ejemplo.com',
            'text' => 'Mi link',
        ], $this->user()));

        $this->assertStatus(201, $res);
        $this->assertSame(['link' => ['id' => 55, 'text' => 'Mi link']], $res->body);
    }

    public function testIndexAplicaLosValoresPorDefecto()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'links']]);
        $this->db->onInsert('INSERT INTO links', 55);

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'https://ejemplo.com',
            'text' => 'Mi link',
        ], $this->user()));

        $params = $this->db->paramsFor('INSERT INTO links');

        $this->assertSame(7, $params[0], 'group_id');
        $this->assertSame('https://ejemplo.com', $params[1], 'url');
        $this->assertNull($params[2], 'url_text ausente se guarda como NULL');
        $this->assertSame('Mi link', $params[3], 'text');
        $this->assertNull($params[4], 'image_url');
        $this->assertNull($params[5], 'description');
        $this->assertSame(0, $params[6], 'position por defecto');
    }

    public function testIndexGuardaUrlTextVacioComoNull()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'links']]);

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'u',
            'text' => 't',
            'url_text' => '',
        ], $this->user()));

        $this->assertNull($this->db->paramsFor('INSERT INTO links')[2]);
    }

    public function testIndexGuardaUrlTextCuandoTieneContenido()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'links']]);

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'u',
            'text' => 't',
            'url_text' => 'Comprar entradas',
        ], $this->user()));

        $this->assertSame('Comprar entradas', $this->db->paramsFor('INSERT INTO links')[2]);
    }

    public function testIndexExigeCoordenadasEnGruposDeEventos()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'eventos']]);

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'u',
            'text' => 'Mi evento',
        ], $this->user()));

        $this->assertError(400, $res, 'Los eventos deben tener coordenadas');
        $this->assertNoWrites();
    }

    /**
     * @dataProvider coordenadasIncompletas
     */
    public function testIndexRechazaCoordenadasParciales($lat, $lng)
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'eventos']]);

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'u',
            'text' => 'Mi evento',
            'event_latitude' => $lat,
            'event_longitude' => $lng,
        ], $this->user()));

        $this->assertError(400, $res, 'Los eventos deben tener coordenadas');
    }

    public function coordenadasIncompletas()
    {
        return [
            'sólo latitud' => ['-34.6', null],
            'sólo longitud' => [null, '-58.4'],
            'ambas vacías' => ['', ''],
            'latitud vacía' => ['', '-58.4'],
        ];
    }

    public function testIndexAceptaEventoConCoordenadas()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'eventos']]);
        $this->db->onInsert('INSERT INTO links', 60);
        $this->db->onSelect('SELECT * FROM links WHERE id = ?', [['id' => 60]]);

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'u',
            'text' => 'Mi evento',
            'event_latitude' => '-34.6037',
            'event_longitude' => '-58.3816',
            'event_date' => '2026-12-01',
        ], $this->user()));

        $this->assertStatus(201, $res);

        $params = $this->db->paramsFor('INSERT INTO links');
        $this->assertSame('2026-12-01', $params[7], 'event_date');
        $this->assertSame('-34.6037', $params[10], 'event_latitude');
        $this->assertSame('-58.3816', $params[11], 'event_longitude');
    }

    public function testIndexDevuelve500SiLaBaseFalla()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'links']]);
        $this->db->failOn('INSERT INTO links', 'tabla bloqueada');

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'u',
            'text' => 't',
        ], $this->user()));

        $this->assertError(500, $res, 'Server error: tabla bloqueada');
    }

    // ================================================================== detail

    public function testDetailExigeIdEnElQueryString()
    {
        $res = LinksHandler::detail($this->db, $this->put([], $this->user()));

        $this->assertError(400, $res, 'Link ID is required');
    }

    public function testDetailRechazaIdCero()
    {
        $res = LinksHandler::detail($this->db, $this->put([], $this->user(), ['id' => '0']));

        $this->assertError(400, $res, 'Link ID is required');
    }

    public function testDetailValidaElIdAntesQueLaSesion()
    {
        // El endpoint original devolvía 400 antes que 401; el frontend distingue
        // ambos casos, así que el orden es parte del contrato.
        $res = LinksHandler::detail($this->db, $this->put([], null, []));

        $this->assertError(400, $res, 'Link ID is required');
    }

    public function testDetailExigeSesion()
    {
        $res = LinksHandler::detail($this->db, $this->put([], null, ['id' => '5']));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testDetailRechazaMetodoNoSoportado()
    {
        $res = LinksHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    // ------------------------------------------------------------------- PUT

    public function testUpdateRechazaLinkAjeno()
    {
        $res = LinksHandler::detail($this->db, $this->put(['text' => 'x'], $this->user(), ['id' => '5']));

        $this->assertError(404, $res, 'Link not found');
        $this->assertNoWrites();
    }

    public function testUpdateActualizaSoloLosCamposEnviados()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'links']]);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id = ?', [['id' => 5, 'text' => 'Nuevo']]);

        $res = LinksHandler::detail($this->db, $this->put(
            ['text' => 'Nuevo'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStatus(200, $res);

        $sql = $this->db->callsFor('UPDATE links SET')[0]['sql'];
        $this->assertStringContainsString('text = ?', $sql);
        $this->assertStringNotContainsString('url = ?', $sql, 'No debe tocar campos no enviados');
        $this->assertSame(['Nuevo', 5], $this->db->paramsFor('UPDATE links SET'));
    }

    public function testUpdateRechazaCuerpoSinCamposConocidos()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'links']]);

        $res = LinksHandler::detail($this->db, $this->put(
            ['campo_inventado' => 'x'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertError(400, $res, 'No fields to update');
        $this->assertNoWrites();
    }

    public function testUpdatePermiteVaciarUrlText()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'links']]);
        $this->db->onWrite('UPDATE links SET', 1);

        LinksHandler::detail($this->db, $this->put(
            ['url_text' => ''],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStringContainsString('url_text = ?', $this->db->callsFor('UPDATE links SET')[0]['sql']);
        $this->assertSame([null, 5], $this->db->paramsFor('UPDATE links SET'));
    }

    public function testUpdatePermiteVaciarImageUrlConNull()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'links']]);
        $this->db->onWrite('UPDATE links SET', 1);

        LinksHandler::detail($this->db, $this->put(
            ['image_url' => null],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertSame([null, 5], $this->db->paramsFor('UPDATE links SET'));
    }

    public function testUpdateIgnoraUnTextEnNull()
    {
        // text no es nullable: enviarlo en null equivale a no enviarlo.
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'links']]);

        $res = LinksHandler::detail($this->db, $this->put(
            ['text' => null],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertError(400, $res, 'No fields to update');
    }

    public function testUpdateDeEventoConservaLasCoordenadasGuardadas()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'eventos']]);
        $this->db->onSelect('SELECT event_latitude, event_longitude', [[
            'event_latitude' => '-34.6',
            'event_longitude' => '-58.4',
        ]]);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onSelect('SELECT * FROM links WHERE id = ?', [['id' => 5]]);

        $res = LinksHandler::detail($this->db, $this->put(
            ['text' => 'Nuevo título'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStatus(200, $res);
    }

    public function testUpdateDeEventoRechazaBorrarLasCoordenadas()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'eventos']]);
        $this->db->onSelect('SELECT event_latitude, event_longitude', [[
            'event_latitude' => '-34.6',
            'event_longitude' => '-58.4',
        ]]);

        $res = LinksHandler::detail($this->db, $this->put(
            ['event_latitude' => ''],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertError(400, $res, 'Los eventos deben tener coordenadas');
        $this->assertNoWrites();
    }

    public function testUpdateDeEventoRechazaSiNuncaTuvoCoordenadas()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'eventos']]);
        $this->db->onSelect('SELECT event_latitude, event_longitude', [[
            'event_latitude' => null,
            'event_longitude' => null,
        ]]);

        $res = LinksHandler::detail($this->db, $this->put(
            ['text' => 'x'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertError(400, $res, 'Los eventos deben tener coordenadas');
    }

    public function testUpdateDevuelve500SiLaBaseFalla()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'links']]);
        $this->db->failOn('UPDATE links SET', 'deadlock');

        $res = LinksHandler::detail($this->db, $this->put(['text' => 'x'], $this->user(), ['id' => '5']));

        $this->assertError(500, $res, 'Server error: deadlock');
    }

    // ---------------------------------------------------------------- DELETE

    public function testDeleteRechazaLinkAjeno()
    {
        $res = LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $this->assertError(404, $res, 'Link not found');
        $this->assertNoWrites();
    }

    public function testDeleteBorraElLink()
    {
        $this->autorizarLink();
        $this->db->onWrite('DELETE FROM links', 1);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(['message' => 'Link deleted successfully'], $res->body);
        $this->assertSame([5], $this->db->paramsFor('DELETE FROM links'));
    }

    public function testDeleteDevuelve500SiLaBaseFalla()
    {
        $this->autorizarLink();
        $this->db->failOn('DELETE FROM links', 'restricción de clave foránea');

        $res = LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $this->assertError(500, $res, 'Server error: restricción de clave foránea');
    }

    // ------------------------------------------------------------- ayudantes

    /** Hace que PageAccess::canManageGroup devuelva true. */
    private function autorizarGrupo()
    {
        $this->db->onSelect('FROM link_groups lg JOIN pages p', [['1' => 1]]);
    }

    /** Hace que PageAccess::canManageLink devuelva true. */
    private function autorizarLink()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['1' => 1]]);
    }
}
