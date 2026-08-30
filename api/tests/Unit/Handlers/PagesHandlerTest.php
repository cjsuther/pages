<?php

namespace Tests\Unit\Handlers;

use PagesHandler;
use Tests\Support\HandlerTestCase;

class PagesHandlerTest extends HandlerTestCase
{
    // ============================================================ index (GET)

    public function testListarExigeSesion()
    {
        $this->assertError(401, PagesHandler::index($this->db, $this->get()), 'Unauthorized');
    }

    public function testListarDevuelveLasPaginasDelUsuario()
    {
        $this->db->onSelect('SELECT p.*, (p.user_id = ?) AS is_owner', [
            ['id' => 1, 'title' => 'Uno', 'is_owner' => 1],
            ['id' => 2, 'title' => 'Dos', 'is_owner' => 0],
        ]);

        $res = PagesHandler::index($this->db, $this->get([], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertCount(2, $res->body['pages']);
    }

    public function testListarIncluyeLasPaginasDondeEsAdministrador()
    {
        PagesHandler::index($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor('AS is_owner')[0]['sql'];

        $this->assertStringContainsString('page_admins', $sql);
        $this->assertStringContainsString('status = "accepted"', $sql);
    }

    public function testListarPasaElUserIdTresVeces()
    {
        PagesHandler::index($this->db, $this->get([], $this->user(9)));

        $this->assertSame([9, 9, 9], $this->db->paramsFor('AS is_owner'));
    }

    public function testListarDevuelveArrayVacioSinPaginas()
    {
        $res = PagesHandler::index($this->db, $this->get([], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame([], $res->body['pages']);
    }

    // ------------------------------------------------------ buscador y páginas

    public function testListarInformaLaPaginacion()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM pages', [[30]]);
        $this->db->onSelect('AS is_owner', [['id' => 1]]);

        $res = PagesHandler::index($this->db, $this->get([], $this->user()));

        $this->assertSame(1, $res->body['paginacion']['pagina']);
        $this->assertSame(30, $res->body['paginacion']['total']);
        $this->assertSame(3, $res->body['paginacion']['paginas']);
    }

    public function testSePuedeBuscarPorTituloYPorSlug()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM pages', [[2]]);
        $this->db->onSelect('AS is_owner', [['id' => 1]]);

        PagesHandler::index($this->db, $this->get(['q' => 'arena'], $this->user(9)));

        $sql = $this->db->callsFor('AS is_owner')[0]['sql'];

        $this->assertStringContainsString('p.title LIKE ?', $sql);
        $this->assertStringContainsString('p.url_slug LIKE ?', $sql);
        $this->assertContains('%arena%', $this->db->paramsFor('AS is_owner'));
    }

    public function testSinBusquedaNoSeFiltra()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM pages', [[5]]);
        $this->db->onSelect('AS is_owner', [['id' => 1]]);

        PagesHandler::index($this->db, $this->get([], $this->user()));

        $this->assertStringNotContainsString('LIKE', $this->db->callsFor('AS is_owner')[0]['sql']);
    }

    /** Sin tope, alguien podría pedir un millón de filas de una. */
    public function testElTamanoDePaginaTieneUnTope()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM pages', [[500]]);
        $this->db->onSelect('AS is_owner', [['id' => 1]]);

        $res = PagesHandler::index($this->db, $this->get(['por_pagina' => 9999], $this->user()));

        $this->assertSame(PagesHandler::MAX_POR_PAGINA, $res->body['paginacion']['por_pagina']);
    }

    /**
     * Pedir la página 9 de 3 devolvería vacío y parecería que no hay nada, en
     * vez de mostrar la última con resultados.
     */
    public function testUnaPaginaMasAllaDelFinalSeAcotaALaUltima()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM pages', [[30]]);
        $this->db->onSelect('AS is_owner', [['id' => 1]]);

        $res = PagesHandler::index($this->db, $this->get(['pagina' => 9], $this->user()));

        $this->assertSame(3, $res->body['paginacion']['pagina']);
    }

    public function testUnaPaginaNegativaNoRompeElOffset()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM pages', [[30]]);
        $this->db->onSelect('AS is_owner', [['id' => 1]]);

        $res = PagesHandler::index($this->db, $this->get(['pagina' => -5], $this->user()));

        $this->assertSame(1, $res->body['paginacion']['pagina']);
        $this->assertStringContainsString('OFFSET 0', $this->db->callsFor('AS is_owner')[0]['sql']);
    }

    /** El buscador no puede saltarse el control de acceso. */
    public function testLaBusquedaSigueRespetandoQuienPuedeVerQue()
    {
        $this->db->onSelect('SELECT COUNT(*) FROM pages', [[1]]);
        $this->db->onSelect('AS is_owner', [['id' => 1]]);

        PagesHandler::index($this->db, $this->get(['q' => 'algo'], $this->user(9)));

        $sql = $this->db->callsFor('AS is_owner')[0]['sql'];

        $this->assertStringContainsString('p.user_id = ?', $sql);
        $this->assertStringContainsString('page_admins', $sql);
    }

    public function testListarDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('AS is_owner', 'conexión perdida');

        $res = PagesHandler::index($this->db, $this->get([], $this->user()));

        $this->assertError(500, $res, 'Server error: conexión perdida');
    }

    // =========================================================== index (POST)

    public function testCrearExigeSesion()
    {
        $res = PagesHandler::index($this->db, $this->post(['title' => 'T', 'url_slug' => 's']));

        $this->assertError(401, $res, 'Unauthorized');
        $this->assertNoWrites();
    }

    /**
     * @dataProvider cuerposIncompletos
     */
    public function testCrearExigeTituloYSlug($cuerpo)
    {
        $res = PagesHandler::index($this->db, $this->post($cuerpo, $this->user()));

        $this->assertError(400, $res, 'Title and URL slug are required');
    }

    public function cuerposIncompletos()
    {
        return [
            'vacío' => [[]],
            'sin title' => [['url_slug' => 's']],
            'sin url_slug' => [['title' => 'T']],
        ];
    }

    /**
     * @dataProvider slugsInvalidos
     */
    public function testCrearRechazaSlugQueQuedaVacioAlNormalizar($slug)
    {
        $res = PagesHandler::index($this->db, $this->post(
            ['title' => 'T', 'url_slug' => $slug],
            $this->user()
        ));

        $this->assertError(400, $res, 'Invalid URL slug');
        $this->assertNoWrites();
    }

    public function slugsInvalidos()
    {
        return [
            'sólo símbolos' => ['!!!'],
            'sólo espacios' => ['   '],
            'string vacío' => [''],
            'acentos solos' => ['ñáé'],
        ];
    }

    /**
     * @dataProvider slugsReservados
     */
    public function testCrearRechazaSlugsReservados($slug)
    {
        $res = PagesHandler::index($this->db, $this->post(
            ['title' => 'T', 'url_slug' => $slug],
            $this->user()
        ));

        $this->assertError(400, $res, 'This URL is reserved and cannot be used');
        $this->assertNoWrites();
    }

    public function slugsReservados()
    {
        return [
            ['login'], ['api'], ['dashboard'], ['admin'], ['settings'],
            ['LOGIN'],  // se normaliza a minúsculas antes de comparar
        ];
    }

    public function testTodosLosSlugsReservadosSonRechazados()
    {
        foreach (PagesHandler::$reservedSlugs as $slug) {
            $res = PagesHandler::index($this->db, $this->post(
                ['title' => 'T', 'url_slug' => $slug],
                $this->user()
            ));

            $this->assertError(400, $res, 'reserved');
        }
    }

    public function testCrearRechazaSlugYaExistente()
    {
        $this->db->onSelect('SELECT id FROM pages WHERE url_slug = ?', [['id' => 3]]);

        $res = PagesHandler::index($this->db, $this->post(
            ['title' => 'T', 'url_slug' => 'ocupado'],
            $this->user()
        ));

        $this->assertError(400, $res, 'URL slug already exists');
        $this->assertNoWrites();
    }

    public function testCrearGuardaLaPaginaYDevuelve201()
    {
        $this->db->onInsert('INSERT INTO pages', 42);
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 42, 'title' => 'Mi página']]);

        $res = PagesHandler::index($this->db, $this->post(
            ['title' => 'Mi página', 'url_slug' => 'mi-pagina'],
            $this->user(9)
        ));

        $this->assertStatus(201, $res);
        $this->assertSame(['page' => ['id' => 42, 'title' => 'Mi página']], $res->body);
    }

    public function testCrearAplicaLosColoresPorDefecto()
    {
        $this->db->onInsert('INSERT INTO pages', 42);

        PagesHandler::index($this->db, $this->post(
            ['title' => 'Mi página', 'url_slug' => 'mi-pagina'],
            $this->user(9)
        ));

        $params = $this->db->paramsFor('INSERT INTO pages');

        $this->assertSame(9, $params[0], 'user_id');
        $this->assertSame('Mi página', $params[1]);
        $this->assertSame('', $params[2], 'description por defecto');
        $this->assertSame('mi-pagina', $params[3]);
        $this->assertSame('#3B82F6', $params[4], 'primary_color');
        $this->assertSame('#1E40AF', $params[5], 'secondary_color');
        $this->assertSame('#FFFFFF', $params[6], 'background_color');
        $this->assertSame('#000000', $params[7], 'text_color');
        $this->assertNull($params[8], 'profile_image');
        $this->assertNull($params[9], 'background_image');
    }

    public function testCrearRespetaLosColoresEnviados()
    {
        $this->db->onInsert('INSERT INTO pages', 42);

        PagesHandler::index($this->db, $this->post([
            'title' => 'T',
            'url_slug' => 's',
            'primary_color' => '#FF0000',
            'text_color' => '#111111',
        ], $this->user()));

        $params = $this->db->paramsFor('INSERT INTO pages');

        $this->assertSame('#FF0000', $params[4]);
        $this->assertSame('#111111', $params[7]);
    }

    /**
     * @dataProvider slugsANormalizar
     */
    public function testCrearNormalizaElSlug($entrada, $esperado)
    {
        $this->db->onInsert('INSERT INTO pages', 42);

        PagesHandler::index($this->db, $this->post(
            ['title' => 'T', 'url_slug' => $entrada],
            $this->user()
        ));

        $this->assertSame($esperado, $this->db->paramsFor('INSERT INTO pages')[3]);
    }

    public function slugsANormalizar()
    {
        return [
            'mayúsculas' => ['MiPagina', 'mipagina'],
            'espacios' => ['mi pagina', 'mipagina'],
            'símbolos' => ['mi_página!', 'mipgina'],
            'guiones se conservan' => ['mi-pagina-2', 'mi-pagina-2'],
            'ya normalizado' => ['abc-123', 'abc-123'],
        ];
    }

    public function testCrearDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('INSERT INTO pages', 'tabla llena');

        $res = PagesHandler::index($this->db, $this->post(
            ['title' => 'T', 'url_slug' => 's'],
            $this->user()
        ));

        $this->assertError(500, $res, 'Server error: tabla llena');
    }

    public function testIndexRechazaMetodoNoSoportado()
    {
        $res = PagesHandler::index($this->db, new \Request('PATCH', [], [], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    // =========================================================== detail (GET)

    public function testDetailExigeId()
    {
        $this->assertError(400, PagesHandler::detail($this->db, $this->get([], $this->user())), 'Page ID is required');
    }

    public function testDetailValidaElIdAntesQueLaSesion()
    {
        $this->assertError(400, PagesHandler::detail($this->db, $this->get([], null)), 'Page ID is required');
    }

    public function testVerExigeSesion()
    {
        $res = PagesHandler::detail($this->db, $this->get(['id' => '5'], null));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testVerRechazaPaginaAjena()
    {
        $res = PagesHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $this->assertError(404, $res, 'Page not found');
    }

    public function testVerDevuelveLaPaginaConSusGrupos()
    {
        $this->autorizarPagina();
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5, 'title' => 'Mi página']]);
        $this->db->onSelect('SELECT * FROM link_groups WHERE page_id = ?', [
            ['id' => 10, 'type' => 'links', 'title' => 'Links'],
        ]);
        $this->db->onSelect('FROM links WHERE group_id = ? ORDER BY position', [
            ['id' => 100, 'text' => 'Un link'],
        ]);

        $res = PagesHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame('Mi página', $res->body['page']['title']);
        $this->assertCount(1, $res->body['page']['groups']);
        $this->assertSame('Un link', $res->body['page']['groups'][0]['links'][0]['text']);
    }

    public function testVerOrdenaLosGruposPorPosicion()
    {
        $this->autorizarPagina();
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5]]);

        PagesHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $sql = $this->db->callsFor('FROM link_groups WHERE page_id = ?')[0]['sql'];
        $this->assertStringContainsString('ORDER BY position, id', $sql);
    }

    public function testVerCargaColaboracionesEnGruposDeEventos()
    {
        $this->autorizarPagina();
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5]]);
        $this->db->onSelect('SELECT * FROM link_groups WHERE page_id = ?', [
            ['id' => 10, 'type' => 'eventos', 'title' => 'Agenda'],
        ]);
        $this->db->onSelect('FROM links WHERE group_id = ? ORDER BY event_date', [
            ['id' => 100, 'text' => 'Mi evento'],
        ]);
        $this->db->onSelect('FROM event_collaborations ec JOIN pages p', [
            ['id' => 1, 'status' => 'pending', 'page_title' => 'Otra página'],
        ]);
        $this->db->onSelect('FROM event_collaborations ec JOIN links l', [
            ['id' => 200, 'source_page_title' => 'Ajena'],
        ]);

        $res = PagesHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $grupo = $res->body['page']['groups'][0];

        $this->assertSame('Otra página', $grupo['links'][0]['collaborations'][0]['page_title']);
        $this->assertSame('Ajena', $grupo['collaborated_events'][0]['source_page_title']);
    }

    public function testVerSoloTraeColaboracionesAceptadasDeOtrasPaginas()
    {
        $this->autorizarPagina();
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5]]);
        $this->db->onSelect('SELECT * FROM link_groups WHERE page_id = ?', [
            ['id' => 10, 'type' => 'eventos'],
        ]);

        PagesHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $sql = $this->db->callsFor('FROM event_collaborations ec JOIN links l')[0]['sql'];
        $this->assertStringContainsString("ec.status = 'accepted'", $sql);
    }

    public function testVerNoUsaConsultasDeEventoEnGruposNormales()
    {
        $this->autorizarPagina();
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5]]);
        $this->db->onSelect('SELECT * FROM link_groups WHERE page_id = ?', [
            ['id' => 10, 'type' => 'links'],
        ]);

        PagesHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $this->assertSame(0, $this->db->countCalls('FROM event_collaborations'));
    }

    // =========================================================== detail (PUT)

    public function testUpdateExigeSesion()
    {
        $res = PagesHandler::detail($this->db, $this->put(['title' => 'X'], null, ['id' => '5']));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testUpdateRechazaPaginaAjena()
    {
        $res = PagesHandler::detail($this->db, $this->put(['title' => 'X'], $this->user(), ['id' => '5']));

        $this->assertError(404, $res, 'Page not found');
        $this->assertNoWrites();
    }

    public function testUpdateActualizaSoloLosCamposEnviados()
    {
        $this->autorizarPagina();
        $this->db->onWrite('UPDATE pages SET', 1);
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5, 'title' => 'Nuevo']]);

        $res = PagesHandler::detail($this->db, $this->put(['title' => 'Nuevo'], $this->user(), ['id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertSame(['Nuevo', 5], $this->db->paramsFor('UPDATE pages SET'));
    }

    public function testUpdatePermiteCambiarLaPlantilla()
    {
        $this->autorizarPagina();
        $this->db->onWrite('UPDATE pages SET', 1);

        PagesHandler::detail($this->db, $this->put(['template' => 'cards'], $this->user(), ['id' => '5']));

        $this->assertStringContainsString('template = ?', $this->db->callsFor('UPDATE pages SET')[0]['sql']);
        $this->assertSame(['cards', 5], $this->db->paramsFor('UPDATE pages SET'));
    }

    // ------------------------------------------------------- dominio propio

    /**
     * Lo guardado se compara contra el Host de cada visita: si no queda en la
     * misma forma, la página no aparece y en el administrador se ve escrita
     * igual, así que no hay manera de darse cuenta mirando.
     */
    public function testUpdateGuardaElDominioNormalizado()
    {
        $this->autorizarPagina();
        $this->db->onWrite('UPDATE pages SET', 1);
        $this->db->onSelect('SELECT id FROM pages WHERE dominio = ?', []);
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5]]);

        PagesHandler::detail($this->db, $this->put(
            ['dominio' => 'https://WWW.MaxiPeque.com/eventos'],
            $this->user(),
            ['id' => '5']
        ));

        $llamada = $this->db->callsFor('UPDATE pages SET')[0];
        $this->assertStringContainsString('dominio = ?', $llamada['sql']);
        $this->assertSame('maxipeque.com', $this->db->paramsFor('UPDATE pages SET')[0]);
    }

    public function testUpdateRechazaUnDominioQueNoVaAResolver()
    {
        $this->autorizarPagina();

        $res = PagesHandler::detail($this->db, $this->put(
            ['dominio' => 'maxi peque'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStatus(400, $res);
        $this->assertNoWrites();
    }

    public function testUpdateNoDejaReclamarUnDominioDeRezonar()
    {
        $this->autorizarPagina();

        $res = PagesHandler::detail($this->db, $this->put(
            ['dominio' => 'rezon.ar'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStatus(400, $res);
        $this->assertNoWrites();
    }

    /** Dos páginas con el mismo dominio: cuál gana dependería del orden. */
    public function testUpdateRechazaUnDominioDeOtraPagina()
    {
        $this->autorizarPagina();
        $this->db->onSelect('SELECT id FROM pages WHERE dominio = ?', [['id' => 9]]);

        $res = PagesHandler::detail($this->db, $this->put(
            ['dominio' => 'maxipeque.com'],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStatus(400, $res);
        $this->assertNoWrites();
    }

    public function testUpdatePermiteQuitarElDominio()
    {
        $this->autorizarPagina();
        $this->db->onWrite('UPDATE pages SET', 1);
        $this->db->onSelect('SELECT * FROM pages WHERE id = ?', [['id' => 5]]);

        PagesHandler::detail($this->db, $this->put(
            ['dominio' => ''],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertStringContainsString(
            'dominio = ?',
            $this->db->callsFor('UPDATE pages SET')[0]['sql']
        );
        $this->assertNull($this->db->paramsFor('UPDATE pages SET')[0]);
    }

    public function testUpdatePermiteQuitarLaImagenDePerfil()
    {
        $this->autorizarPagina();
        $this->db->onWrite('UPDATE pages SET', 1);

        PagesHandler::detail($this->db, $this->put(['profile_image' => ''], $this->user(), ['id' => '5']));

        $this->assertSame([null, 5], $this->db->paramsFor('UPDATE pages SET'));
    }

    public function testUpdatePermiteQuitarLaImagenDeFondoConNull()
    {
        $this->autorizarPagina();
        $this->db->onWrite('UPDATE pages SET', 1);

        PagesHandler::detail($this->db, $this->put(['background_image' => null], $this->user(), ['id' => '5']));

        $this->assertSame([null, 5], $this->db->paramsFor('UPDATE pages SET'));
    }

    public function testUpdateActualizaTodosLosColores()
    {
        $this->autorizarPagina();
        $this->db->onWrite('UPDATE pages SET', 1);

        PagesHandler::detail($this->db, $this->put([
            'primary_color' => '#111111',
            'secondary_color' => '#222222',
            'background_color' => '#333333',
            'text_color' => '#444444',
        ], $this->user(), ['id' => '5']));

        $this->assertSame(
            ['#111111', '#222222', '#333333', '#444444', 5],
            $this->db->paramsFor('UPDATE pages SET')
        );
    }

    public function testUpdateRechazaCuerpoSinCamposConocidos()
    {
        $this->autorizarPagina();

        $res = PagesHandler::detail($this->db, $this->put(['inventado' => 1], $this->user(), ['id' => '5']));

        $this->assertError(400, $res, 'No fields to update');
        $this->assertNoWrites();
    }

    // ======================================================== detail (DELETE)

    public function testDeleteExigeSesion()
    {
        $res = PagesHandler::detail($this->db, $this->delete(['id' => '5'], null));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testDeleteBorraLaPaginaDelDueno()
    {
        $this->db->onWrite('DELETE FROM pages', 1);

        $res = PagesHandler::detail($this->db, $this->delete(['id' => '5'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertSame(['message' => 'Page deleted successfully'], $res->body);
        $this->assertSame([5, 9], $this->db->paramsFor('DELETE FROM pages'));
    }

    public function testDeleteSoloLoPuedeHacerElDueno()
    {
        // La consulta filtra por user_id: un administrador aceptado no borra
        // nada y rowCount queda en 0.
        $this->db->onWrite('DELETE FROM pages', 0);

        $res = PagesHandler::detail($this->db, $this->delete(['id' => '5'], $this->user(9)));

        $this->assertError(404, $res, 'Page not found');
        $this->assertStringContainsString('user_id = ?', $this->db->callsFor('DELETE FROM pages')[0]['sql']);
    }

    public function testDeleteDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('DELETE FROM pages', 'clave foránea');

        $res = PagesHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $this->assertError(500, $res, 'Server error: clave foránea');
    }

    public function testDetailRechazaMetodoNoSoportado()
    {
        $res = PagesHandler::detail($this->db, new \Request('PATCH', [], ['id' => '5'], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    // =============================================================== utilidades

    /**
     * @dataProvider normalizaciones
     */
    public function testNormalizarSlug($entrada, $esperado)
    {
        $this->assertSame($esperado, PagesHandler::normalizarSlug($entrada));
    }

    public function normalizaciones()
    {
        return [
            ['MiPagina', 'mipagina'],
            ['con espacios', 'conespacios'],
            ['con-guiones', 'con-guiones'],
            ['CON.PUNTOS', 'conpuntos'],
            ['123', '123'],
            ['', ''],
            ['@#$%', ''],
        ];
    }

    public function testEsReservadoDistingueSlugsLibres()
    {
        $this->assertTrue(PagesHandler::esReservado('login'));
        $this->assertFalse(PagesHandler::esReservado('mi-pagina'));
        $this->assertFalse(PagesHandler::esReservado('logins'));
    }

    // ------------------------------------------------------------- ayudantes

    private function autorizarPagina()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
    }
}
