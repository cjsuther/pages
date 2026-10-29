<?php

namespace Tests\Unit\Handlers;

use LinksHandler;
use Notificador;
use ProductosDigitales;
use Tests\Support\FakeDisco;
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
        $this->assertNull($params[3], 'embed_url ausente se guarda como NULL');
        $this->assertSame('Mi link', $params[4], 'text');
        $this->assertNull($params[5], 'image_url');
        $this->assertNull($params[6], 'description');
        $this->assertSame(0, $params[7], 'position por defecto');
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

    public function testIndexGuardaEmbedUrlVacioComoNull()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'galeria']]);

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => 'u',
            'text' => 't',
            'embed_url' => '',
        ], $this->user()));

        $this->assertNull($this->db->paramsFor('INSERT INTO links')[3]);
    }

    public function testIndexGuardaElVideoDeUnItemDeGaleria()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'galeria']]);

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7,
            'url' => '',
            'text' => 'Mi video',
            'embed_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ], $this->user()));

        $this->assertSame(
            'https://youtu.be/dQw4w9WgXcQ',
            $this->db->paramsFor('INSERT INTO links')[3]
        );
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
            'precio_desde' => '15000',
        ], $this->user()));

        $this->assertStatus(201, $res);

        $params = $this->db->paramsFor('INSERT INTO links');
        $this->assertSame('2026-12-01', $params[8], 'event_date');
        $this->assertSame('-34.6037', $params[11], 'event_latitude');
        $this->assertSame('-58.3816', $params[12], 'event_longitude');
        $this->assertSame(15000.0, $params[14], 'precio_desde');
    }

    // ----------------------------------------------- precio de un evento

    private function crearEvento(array $cuerpo, $exigirPrecio = true)
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'eventos']]);
        $this->db->onInsert('INSERT INTO links', 60);
        $this->db->onSelect('SELECT * FROM links WHERE id = ?', [['id' => 60]]);

        return LinksHandler::index($this->db, $this->post(array_merge([
            'group_id' => 7, 'url' => 'u', 'text' => 'Mi evento',
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4',
        ], $cuerpo), $this->user()), $exigirPrecio);
    }

    /**
     * @dataProvider preciosQueNoDicenNada
     */
    public function testUnEventoSinPrecioNoSeCrea($cuerpo)
    {
        $res = $this->crearEvento($cuerpo);

        $this->assertError(400, $res, 'gratis');
        $this->assertSame(0, $this->db->countCalls('INSERT INTO links'));
    }

    public function preciosQueNoDicenNada()
    {
        return [
            'sin el campo' => [[]],
            'null' => [['precio_desde' => null]],
            'vacío' => [['precio_desde' => '']],
            'texto' => [['precio_desde' => 'a consultar']],
        ];
    }

    public function testUnEventoGratisSeCreaConCero()
    {
        $this->assertStatus(201, $this->crearEvento(['precio_desde' => 0]));
        $this->assertSame(0.0, $this->db->paramsFor('INSERT INTO links')[14]);
    }

    /** El asistente (MCP) puede crear un evento sin saber todavía el precio. */
    public function testSinExigirPrecioSeCreaSinPrecio()
    {
        $this->assertStatus(201, $this->crearEvento([], false));
        $this->assertNull($this->db->paramsFor('INSERT INTO links')[14]);
    }

    private function editarEvento(array $cuerpo, $venta = null, $exigirPrecio = true)
    {
        $this->autorizarLink();
        $this->db->onSelect('JOIN link_groups lg ON l.group_id = lg.id', [['id' => 5, 'type' => 'eventos']]);
        $this->db->onSelect('SELECT event_latitude, event_longitude FROM links', [['event_latitude' => '-34.6', 'event_longitude' => '-58.4']]);

        if ($venta !== null) {
            $this->db->onSelect('FROM event_ticketing WHERE link_id', [$venta]);
        }

        $this->db->onSelect('SELECT * FROM links WHERE id = ?', [['id' => 5]]);

        return LinksHandler::detail($this->db, $this->put($cuerpo, $this->user(), ['id' => '5']), null, $exigirPrecio);
    }

    public function testEditarNoDejaSinPrecioAUnEventoQueNoVende()
    {
        $this->assertError(400, $this->editarEvento(['precio_desde' => '']), 'gratis');
        $this->assertSame(0, $this->db->countCalls('UPDATE links SET'));
    }

    public function testEditarGuardaElPrecioNormalizado()
    {
        $this->assertStatus(200, $this->editarEvento(['precio_desde' => '18000.456']));
        $this->assertSame([18000.46, 5], $this->db->paramsFor('UPDATE links SET'));
    }

    /** Lo que no trae el precio (el link de la solapa de entradas) no lo exige. */
    public function testEditarSinMandarElPrecioNoLoExige()
    {
        $this->assertStatus(200, $this->editarEvento(['url' => 'https://otra.example']));
    }

    public function testUnEventoQueVendePorRezonarNoNecesitaPrecio()
    {
        $venta = ['link_id' => 5, 'activo' => 1, 'capacidad' => 10, 'precio' => '5000.00', 'plano' => null, 'tipos' => null];

        $this->assertStatus(200, $this->editarEvento(['precio_desde' => null], $venta));
    }

    public function testUnaVentaDesactivadaNoCuentaComoVenta()
    {
        $venta = ['link_id' => 5, 'activo' => 0, 'capacidad' => 10, 'precio' => '5000.00', 'plano' => null, 'tipos' => null];

        $this->assertError(400, $this->editarEvento(['precio_desde' => null], $venta), 'gratis');
    }

    public function testElAsistentePuedeEditarSinPrecio()
    {
        $this->assertStatus(200, $this->editarEvento(['precio_desde' => null], null, false));
    }

    // ------------------------------------------- aviso a los seguidores

    public function testCrearUnEventoAvisaALosSeguidores()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'eventos']]);
        $this->db->onInsert('INSERT INTO links', 60);
        $this->db->onSelect('FROM links l INNER JOIN link_groups lg', [[
            'id' => 60, 'title' => 'Mi Evento', 'event_date' => '2026-12-01',
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4',
            'page_id' => 5, 'page_title' => 'Mi Página', 'url_slug' => 'mi-pagina', 'owner_id' => 9,
        ]]);
        $this->db->onSelect('FROM page_followers pf INNER JOIN users u', [[
            'user_id' => 11, 'notify_all_events' => 1, 'max_distance_km' => 50,
            'location_latitude' => null, 'location_longitude' => null,
        ]]);
        $this->db->onWrite('INSERT IGNORE INTO notifications', 1);

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7, 'url' => 'u', 'text' => 'Mi Evento',
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4', 'precio_desde' => 0,
        ], $this->user()));

        $this->assertSame('evento:60:11', $this->db->paramsFor('INSERT IGNORE INTO notifications')[6]);
    }

    public function testCrearUnLinkComunNoAvisaANadie()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'links']]);
        $this->db->onInsert('INSERT INTO links', 55);

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7, 'url' => 'u', 'text' => 't',
        ], $this->user()));

        $this->assertSame(0, $this->db->countCalls('FROM page_followers'));
        $this->assertSame(0, $this->db->countCalls('INSERT IGNORE INTO notifications'));
    }

    /** Un fallo al notificar no puede impedir que el evento se cree. */
    public function testSiFallaElAvisoElEventoIgualSeCrea()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'eventos']]);
        $this->db->onInsert('INSERT INTO links', 60);
        $this->db->failOn('FROM links l INNER JOIN link_groups lg', 'tabla bloqueada');
        $this->db->onSelect('SELECT * FROM links WHERE id = ?', [['id' => 60, 'text' => 'Mi Evento']]);

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7, 'url' => 'u', 'text' => 'Mi Evento',
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4', 'precio_desde' => 0,
        ], $this->user()));

        $this->assertStatus(201, $res);
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

    public function testUpdatePermiteVaciarEmbedUrl()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'galeria']]);
        $this->db->onWrite('UPDATE links SET', 1);

        LinksHandler::detail($this->db, $this->put(
            ['embed_url' => ''],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStringContainsString('embed_url = ?', $this->db->callsFor('UPDATE links SET')[0]['sql']);
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

    /**
     * Las compras sobreviven colgadas del registro del evento: tiene que
     * quedar con los últimos datos antes de que el evento desaparezca.
     */
    public function testAntesDeBorrarUnEventoSeRefrescaSuRegistro()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT id FROM event_records WHERE link_id', [[7]]);
        $this->db->onSelect('SELECT id FROM event_records WHERE link_id', [[7]]);
        $this->db->onWrite('DELETE FROM links', 1);

        LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $sqls = array_column($this->db->log(), 'sql');
        $registro = array_keys(array_filter($sqls, function ($s) { return strpos($s, 'INSERT INTO event_records') !== false; }));
        $borrado = array_keys(array_filter($sqls, function ($s) { return strpos($s, 'DELETE FROM links') !== false; }));

        $this->assertNotEmpty($registro, 'se refresca el registro');
        $this->assertLessThan($borrado[0], $registro[0], 'y antes de borrar');
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

    // ------------------------------------------------------ precio de referencia

    /**
     * Son tres cosas distintas: no saber el precio, saber que es gratis, y
     * tener un valor. Si el vacío se guardara como cero, todo evento sin dato
     * aparecería anunciado como gratis.
     */
    public function testUnPrecioVacioEsSinDatoYNoCero()
    {
        $this->assertNull(LinksHandler::precioONull(''));
        $this->assertNull(LinksHandler::precioONull(null));
    }

    public function testElCeroSeGuardaComoCeroYNoComoSinDato()
    {
        $this->assertSame(0.0, LinksHandler::precioONull(0));
        $this->assertSame(0.0, LinksHandler::precioONull('0'));
    }

    public function testUnPrecioSeGuardaConDosDecimales()
    {
        $this->assertSame(25000.0, LinksHandler::precioONull('25000'));
        $this->assertSame(1500.55, LinksHandler::precioONull('1500.554'));
    }

    public function testUnTextoNoNumericoNoSeGuarda()
    {
        $this->assertNull(LinksHandler::precioONull('a consultar'));
    }

    /** Un precio negativo no existe; se trata como gratis. */
    public function testUnNegativoNoSeGuardaComoNegativo()
    {
        $this->assertSame(0.0, LinksHandler::precioONull(-500));
    }

    /**
     * Borrar sólo la longitud también deja el evento sin punto en el mapa,
     * aunque la latitud siga guardada.
     */
    public function testUpdateDeEventoRechazaBorrarSoloLaLongitud()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'eventos']]);
        $this->db->onSelect('SELECT event_latitude, event_longitude', [[
            'event_latitude' => '-34.6',
            'event_longitude' => '-58.4',
        ]]);

        $res = LinksHandler::detail($this->db, $this->put(
            ['event_longitude' => ''],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertError(400, $res, 'Los eventos deben tener coordenadas');
        $this->assertNoWrites();
    }

    /** Mover el punto del mapa manda las dos coordenadas nuevas, no mezcla con las viejas. */
    public function testUpdateDeEventoConCoordenadasNuevas()
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
            ['event_latitude' => '-31.4', 'event_longitude' => '-64.2'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStatus(200, $res);
        $params = $this->db->paramsFor('UPDATE links SET');
        $this->assertContains('-31.4', $params);
        $this->assertContains('-64.2', $params);
    }

    // ===================================================== productos digitales

    private function grupoDeProductos()
    {
        $this->autorizarGrupo();
        $this->db->onSelect('SELECT id, type FROM link_groups', [['id' => 7, 'type' => 'productos']]);
        $this->db->onInsert('INSERT INTO links', 55);
    }

    public function testCrearUnProductoGuardaElLinkYSuPrecio()
    {
        $this->grupoDeProductos();
        $this->db->onSelect('LEFT JOIN digital_products', [['id' => 55, 'text' => 'Guía', 'precio' => '2500.00']]);

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7, 'url' => '', 'text' => 'Guía', 'description' => 'Una guía',
            'image_url' => 'https://rezon.ar/api/uploads/tapa.jpg', 'precio' => '2500',
        ], $this->user()));

        $this->assertStatus(201, $res);
        $this->assertSame('2500.00', $res->body['link']['precio']);
        $this->assertSame([55, 2500.0], $this->db->paramsFor('INSERT INTO digital_products'));
        $this->assertTrue($this->db->committed);

        $link = $this->db->paramsFor('INSERT INTO links');
        $this->assertSame([7, '', 'Guía', 'https://rezon.ar/api/uploads/tapa.jpg', 'Una guía', 0], $link);
    }

    /** Un producto no lleva a ninguna parte: la URL queda vacía, como en los eventos. */
    public function testUnProductoNoGuardaUrl()
    {
        $this->grupoDeProductos();

        LinksHandler::index($this->db, $this->post(
            ['group_id' => 7, 'url' => 'https://otro.sitio', 'text' => 'Guía'],
            $this->user()
        ));

        $this->assertSame('', $this->db->paramsFor('INSERT INTO links')[1]);
    }

    public function testUnProductoSinPrecioEsGratis()
    {
        $this->grupoDeProductos();

        LinksHandler::index($this->db, $this->post(['group_id' => 7, 'url' => '', 'text' => 'Guía'], $this->user()));

        $this->assertSame([55, 0.0], $this->db->paramsFor('INSERT INTO digital_products'));
    }

    public function testUnProductoConPrecioInvalidoNoSeCrea()
    {
        $this->grupoDeProductos();

        $res = LinksHandler::index($this->db, $this->post(
            ['group_id' => 7, 'url' => '', 'text' => 'Guía', 'precio' => '-10'],
            $this->user()
        ));

        $this->assertError(400, $res, 'precio');
        $this->assertNoWrites();
    }

    /** Sin su fila de producto, el link quedaría a medias: se deshace todo. */
    public function testSiFallaLaFilaDelProductoNoQuedaElLinkSuelto()
    {
        $this->grupoDeProductos();
        $this->db->failOn('INSERT INTO digital_products', 'sin conexión');

        $res = LinksHandler::index($this->db, $this->post(['group_id' => 7, 'url' => '', 'text' => 'Guía'], $this->user()));

        $this->assertStatus(500, $res);
        $this->assertTrue($this->db->rolledBack);
        $this->assertFalse($this->db->committed);
    }

    /** A un producto no se le avisa a los seguidores: todavía no se ve. */
    public function testCrearUnProductoNoNotificaANadie()
    {
        $this->grupoDeProductos();

        LinksHandler::index($this->db, $this->post(['group_id' => 7, 'url' => '', 'text' => 'Guía'], $this->user()));

        $this->assertSame(0, $this->db->countCalls('page_followers'));
    }

    private function esProducto()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'productos']]);
    }

    public function testEditarElPrecioDeUnProducto()
    {
        $this->esProducto();
        $this->db->onSelect('LEFT JOIN digital_products', [['id' => 5, 'precio' => '900.00']]);

        $res = LinksHandler::detail($this->db, $this->put(['precio' => '900'], $this->user(), ['id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertSame('900.00', $res->body['link']['precio']);
        $this->assertSame([5, 900.0], $this->db->paramsFor('INSERT INTO digital_products'));
        $this->assertSame(0, $this->db->countCalls('UPDATE links SET'), 'sólo cambió el precio');
    }

    public function testEditarTituloYPrecioDeUnProducto()
    {
        $this->esProducto();

        LinksHandler::detail($this->db, $this->put(
            ['text' => 'Guía 2', 'description' => 'Nueva', 'precio' => 0],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertSame(['Guía 2', 'Nueva', 5], $this->db->paramsFor('UPDATE links SET'));
        $this->assertSame([5, 0.0], $this->db->paramsFor('INSERT INTO digital_products'));
    }

    public function testUnPrecioInvalidoNoCambiaNada()
    {
        $this->esProducto();

        $res = LinksHandler::detail($this->db, $this->put(
            ['text' => 'Guía', 'precio' => 'mucho'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertError(400, $res, 'precio');
        $this->assertNoWrites();
    }

    /** En un link común "precio" no significa nada y no se guarda en ningún lado. */
    public function testEnUnLinkComunElPrecioSeIgnora()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT l.id, lg.type', [['id' => 5, 'type' => 'links']]);

        $res = LinksHandler::detail($this->db, $this->put(['precio' => 100], $this->user(), ['id' => '5']));

        $this->assertError(400, $res, 'No fields to update');
        $this->assertSame(0, $this->db->countCalls('digital_products'));
    }

    public function testBorrarUnProductoSinOrdenesBorraSuFilaYSuArchivo()
    {
        $disco = new FakeDisco();
        $ruta = 'productos/' . str_repeat('c', 32);
        $this->autorizarLink();
        $this->db->onSelect('FOR UPDATE', [['id' => 40, 'link_id' => 5, 'archivo_ruta' => $ruta, 'con_ordenes' => 0, 'con_compras' => 0]]);
        $this->db->onWrite('DELETE FROM links', 1);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()), $disco);

        $this->assertStatus(200, $res);
        $this->assertSame([40], $this->db->paramsFor('DELETE FROM digital_products WHERE id = ?'));
        $this->assertSame([ProductosDigitales::rutaEnDisco($ruta)], $disco->borrados);
        $this->assertTrue($this->db->committed);
    }

    /** Quien lo compró tiene que poder seguir descargándolo (#18): se borra el link, no lo vendido. */
    public function testUnProductoConVentasSeBorraConservandoFilaYArchivo()
    {
        $disco = new FakeDisco();
        $this->autorizarLink();
        $this->db->onSelect('FOR UPDATE', [['id' => 40, 'link_id' => 5, 'archivo_ruta' => 'productos/' . str_repeat('c', 32), 'con_ordenes' => 1, 'con_compras' => 1]]);
        $this->db->onWrite('DELETE FROM links', 1);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()), $disco);

        $this->assertStatus(200, $res);
        $this->assertSame([40], $this->db->paramsFor('SET borrado_en = NOW()'));
        $this->assertFalse($this->db->ran('DELETE FROM digital_products'));
        $this->assertTrue($this->db->ran('DELETE FROM links'));
        $this->assertSame([], $disco->borrados);
    }

    public function testOcultarUnProducto()
    {
        $this->autorizarLink();
        $this->db->onSelect('JOIN link_groups lg ON l.group_id = lg.id', [['id' => 5, 'type' => 'productos']]);

        $res = LinksHandler::detail($this->db, new \Request('PUT', ['oculto' => true], ['id' => '5'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame([1, 5], $this->db->paramsFor('UPDATE digital_products SET oculto = ?'));
        $this->assertFalse($this->db->ran('UPDATE links SET'));
    }

    /** "oculto" es sólo de los productos: en un link común no se toca nada. */
    public function testOcultoNoSeAplicaAUnLinkComun()
    {
        $this->autorizarLink();
        $this->db->onSelect('JOIN link_groups lg ON l.group_id = lg.id', [['id' => 5, 'type' => 'links']]);

        $res = LinksHandler::detail($this->db, new \Request('PUT', ['oculto' => true], ['id' => '5'], $this->user()));

        $this->assertError(400, $res);
        $this->assertFalse($this->db->ran('digital_products'));
    }

    /** El título queda copiado en el producto: es lo que se ve si se borra el link. */
    public function testEditarUnProductoActualizaSuFoto()
    {
        $this->autorizarLink();
        $this->db->onSelect('JOIN link_groups lg ON l.group_id = lg.id', [['id' => 5, 'type' => 'productos']]);

        LinksHandler::detail($this->db, new \Request('PUT', ['text' => 'Nuevo título'], ['id' => '5'], $this->user()));

        $this->assertSame([5], $this->db->paramsFor('SET dp.titulo = l.text, dp.page_id = lg.page_id'));
    }

    public function testBorrarUnProductoSinArchivoNoBorraNadaDelDisco()
    {
        $disco = new FakeDisco();
        $this->autorizarLink();
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [['link_id' => 5, 'archivo_ruta' => null]]);

        LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()), $disco);

        $this->assertSame([], $disco->borrados);
    }

    /** Si la base no lo borró, el archivo tiene que seguir ahí. */
    public function testSiFallaElBorradoElArchivoQueda()
    {
        $disco = new FakeDisco();
        $this->autorizarLink();
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [['link_id' => 5, 'archivo_ruta' => 'productos/' . str_repeat('c', 32)]]);
        $this->db->failOn('DELETE FROM links', 'bloqueada');

        $res = LinksHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()), $disco);

        $this->assertStatus(500, $res);
        $this->assertSame([], $disco->borrados);
    }

    // ======================================================= productos físicos

    public function testCrearUnFisicoSinCaracteristicas()
    {
        $this->grupoDeProductos();

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7, 'url' => '', 'text' => 'Remera', 'precio' => '12000', 'tipo' => 'fisico',
            'costo_envio' => '0', 'zona_envio' => 'Sólo CABA y GBA', 'stock' => '15',
        ], $this->user()));

        $this->assertStatus(201, $res);
        $this->assertSame([55, 12000.0], $this->db->paramsFor('INSERT INTO digital_products'));
        $this->assertSame(['fisico', 55], $this->db->paramsFor('UPDATE digital_products SET tipo = ?'));
        $this->assertSame([0.0, 'Sólo CABA y GBA', 15, 55], $this->db->paramsFor('UPDATE digital_products SET costo_envio = ?'));
        $this->assertFalse($this->db->ran('product_attributes'));
        $this->assertTrue($this->db->committed);
    }

    public function testCrearUnFisicoConCaracteristicasYStockPorCombinacion()
    {
        $this->grupoDeProductos();
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [['id' => 30, 'link_id' => 55, 'tipo' => 'fisico']]);
        $this->db->onInsert('INSERT INTO product_attributes', 70);
        $this->db->onInsert('INSERT INTO product_attribute_options', 100);
        $this->db->onInsert('INSERT INTO product_attribute_options', 101);

        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => 7, 'url' => '', 'text' => 'Remera', 'tipo' => 'fisico', 'stock' => '15',
            'caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['S', 'M']]],
            'variantes' => [['opciones' => ['M'], 'stock' => 0]],
        ], $this->user()));

        $this->assertStatus(201, $res);
        $this->assertSame([null, 55], $this->db->paramsFor('UPDATE digital_products SET stock = ?'), 'con características, el stock general se vacía');
        $this->assertSame([30, 'Talle', 0], $this->db->paramsFor('INSERT INTO product_attributes'));
        $this->assertSame([30, '101', 0], $this->db->paramsFor('INSERT INTO product_variants'));
        $this->assertTrue($this->db->committed);
    }

    public function testUnDigitalNoGuardaNadaDeFisico()
    {
        $this->grupoDeProductos();

        LinksHandler::index($this->db, $this->post([
            'group_id' => 7, 'url' => '', 'text' => 'Guía', 'stock' => 3, 'caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['S']]],
        ], $this->user()));

        $this->assertFalse($this->db->ran('SET tipo'));
        $this->assertFalse($this->db->ran('stock = ?'));
        $this->assertFalse($this->db->ran('product_attributes'));
    }

    public function testUnTipoDesconocidoNoSeCrea()
    {
        $this->grupoDeProductos();

        $res = LinksHandler::index($this->db, $this->post(
            ['group_id' => 7, 'url' => '', 'text' => 'Curso', 'tipo' => 'servicio'],
            $this->user()
        ));

        $this->assertError(400, $res, 'digital o físico');
        $this->assertNoWrites();
    }

    /**
     * @dataProvider fisicosInvalidos
     */
    public function testUnFisicoInvalidoNoSeCrea(array $extra, $fragmento)
    {
        $this->grupoDeProductos();

        $res = LinksHandler::index($this->db, $this->post(
            ['group_id' => 7, 'url' => '', 'text' => 'Remera', 'tipo' => 'fisico'] + $extra,
            $this->user()
        ));

        $this->assertError(400, $res, $fragmento);
        $this->assertNoWrites();
    }

    public function fisicosInvalidos()
    {
        return [
            'stock negativo' => [['stock' => '-1'], 'stock'],
            'stock decimal' => [['stock' => '2.5'], 'stock'],
            'costo de envío negativo' => [['costo_envio' => '-100'], 'costo de envío'],
            'característica sin nombre' => [['caracteristicas' => [['nombre' => ' ', 'opciones' => ['S']]]], 'nombre'],
            'opción vacía' => [['caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['S', '']]]], 'opción vacía'],
            'características duplicadas' => [['caracteristicas' => [
                ['nombre' => 'Color', 'opciones' => ['Rojo']],
                ['nombre' => 'color', 'opciones' => ['Azul']],
            ]], 'dos características'],
            'opciones duplicadas' => [['caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['M', 'M']]]], 'repetida'],
            'stock por combinación negativo' => [[
                'caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['S']]],
                'variantes' => [['opciones' => ['S'], 'stock' => -1]],
            ], 'stock'],
        ];
    }

    /** Un link de productos, autorizado, con su fila de digital_products. */
    private function esProductoDeTipo($tipo)
    {
        $this->esProducto();
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [['id' => 30, 'link_id' => 5, 'tipo' => $tipo]]);
    }

    public function testEditarUnFisico()
    {
        $this->esProductoDeTipo('fisico');
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [['id' => 30, 'link_id' => 5, 'tipo' => 'fisico']]);
        $this->db->onSelect('SELECT id, nombre FROM product_attributes', [['id' => 70, 'nombre' => 'Talle']]);
        $this->db->onSelect('FROM product_attribute_options o', [
            ['id' => 100, 'attribute_id' => 70, 'nombre' => 'S', 'usada' => 0],
        ]);
        $this->db->onInsert('INSERT INTO product_attribute_options', 101);

        $res = LinksHandler::detail($this->db, $this->put([
            'text' => 'Remera negra', 'tipo' => 'fisico', 'precio' => '9000', 'costo_envio' => '2000', 'zona_envio' => '',
            'caracteristicas' => [['id' => 70, 'nombre' => 'Talle', 'opciones' => [['id' => 100, 'nombre' => 'S'], 'M']]],
            'variantes' => [['opciones' => ['S'], 'stock' => '4'], ['opciones' => ['M'], 'stock' => '']],
        ], $this->user(), ['id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertSame(['Remera negra', 5], $this->db->paramsFor('UPDATE links SET'));
        $this->assertSame([2000.0, null, null, 5], $this->db->paramsFor('UPDATE digital_products SET costo_envio = ?'));
        $this->assertSame([70, 'M', 1], $this->db->paramsFor('INSERT INTO product_attribute_options'));
        $this->assertSame([[30, '100', 4]], array_column($this->db->callsFor('INSERT INTO product_variants'), 'params'));
        $this->assertTrue($this->db->committed);
    }

    /** Las órdenes de cada tipo son distintas: el tipo no cambia después del alta. */
    public function testElTipoDeUnProductoNoSeCambia()
    {
        $this->esProductoDeTipo('digital');

        $res = LinksHandler::detail($this->db, $this->put(['tipo' => 'fisico', 'text' => 'Remera'], $this->user(), ['id' => '5']));

        $this->assertError(400, $res, 'no se puede cambiar');
        $this->assertNoWrites();
    }

    public function testMandarElMismoTipoNoMolesta()
    {
        $this->esProductoDeTipo('digital');

        $res = LinksHandler::detail($this->db, $this->put(['tipo' => 'digital', 'text' => 'Guía'], $this->user(), ['id' => '5']));

        $this->assertStatus(200, $res);
    }

    /** A un digital el stock y las características no le significan nada. */
    public function testEditarUnDigitalIgnoraLoDeFisico()
    {
        $this->esProductoDeTipo('digital');

        $res = LinksHandler::detail($this->db, $this->put(['stock' => 5, 'caracteristicas' => []], $this->user(), ['id' => '5']));

        $this->assertError(400, $res, 'No fields to update');
        $this->assertNoWrites();
    }

    public function testUnFisicoConStockInvalidoNoCambiaNada()
    {
        $this->esProductoDeTipo('fisico');

        $res = LinksHandler::detail($this->db, $this->put(['text' => 'Remera', 'stock' => 'muchos'], $this->user(), ['id' => '5']));

        $this->assertError(400, $res, 'stock');
        $this->assertNoWrites();
    }

    /** Si falla una característica, no queda nada a medias. */
    public function testSiFallaGuardarLasCaracteristicasSeDeshaceTodo()
    {
        $this->esProductoDeTipo('fisico');
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [['id' => 30, 'link_id' => 5, 'tipo' => 'fisico']]);
        $this->db->failOn('INSERT INTO product_attributes', 'sin conexión');

        $res = LinksHandler::detail($this->db, $this->put([
            'text' => 'Remera', 'caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['S']]],
        ], $this->user(), ['id' => '5']));

        $this->assertStatus(500, $res);
        $this->assertTrue($this->db->rolledBack);
        $this->assertFalse($this->db->committed);
    }

    // ---------------------------------------------------------- activo/inactivo

    /** "Activo" en el editor es oculto = 0: se reutiliza la columna. */
    public function testActivarUnProductoDesdeLaLista()
    {
        $this->esProductoDeTipo('fisico');

        $res = LinksHandler::detail($this->db, $this->put(['oculto' => false], $this->user(), ['id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertSame([0, 5], $this->db->paramsFor('UPDATE digital_products SET oculto = ?'));
        $this->assertFalse($this->db->ran('UPDATE links SET'));
        $this->assertFalse($this->db->ran('product_attributes'), 'activar no toca las características');
    }

    public function testDesactivarUnProductoDigitalDesdeLaLista()
    {
        $this->esProductoDeTipo('digital');

        $res = LinksHandler::detail($this->db, $this->put(['oculto' => '1'], $this->user(), ['id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertSame([1, 5], $this->db->paramsFor('UPDATE digital_products SET oculto = ?'));
    }
}
