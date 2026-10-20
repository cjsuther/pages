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
        ], $this->user()));

        $this->assertStatus(201, $res);

        $params = $this->db->paramsFor('INSERT INTO links');
        $this->assertSame('2026-12-01', $params[8], 'event_date');
        $this->assertSame('-34.6037', $params[11], 'event_latitude');
        $this->assertSame('-58.3816', $params[12], 'event_longitude');
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
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4',
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
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4',
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
}
