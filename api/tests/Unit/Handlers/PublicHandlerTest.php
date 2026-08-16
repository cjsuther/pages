<?php

namespace Tests\Unit\Handlers;

use Fechas;
use PublicHandler;
use Tests\Support\HandlerTestCase;

class PublicHandlerTest extends HandlerTestCase
{
    // =================================================================== page

    public function testPageRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::page($this->db, $this->post()), 'Method not allowed');
    }

    public function testPageExigeSlug()
    {
        $this->assertError(400, PublicHandler::page($this->db, $this->get()), 'Slug is required');
    }

    public function testPageRechazaSlugEnBlanco()
    {
        $this->assertError(400, PublicHandler::page($this->db, $this->get(['slug' => '   '])), 'Slug is required');
    }

    public function testPageDevuelve404SiNoExiste()
    {
        $res = PublicHandler::page($this->db, $this->get(['slug' => 'no-existe']));

        $this->assertError(404, $res, 'Page not found');
    }

    public function testPageBuscaPorSlugRecortado()
    {
        PublicHandler::page($this->db, $this->get(['slug' => '  mi-pagina  ']));

        $this->assertSame(['mi-pagina'], $this->db->paramsFor('FROM pages WHERE url_slug = ?'));
    }

    public function testPageDevuelveLaPaginaConGruposYSeguidores()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5, 'title' => 'Mi página']]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'links']]);
        $this->db->onSelect('FROM links WHERE group_id = ? ORDER BY position', [['id' => 100, 'text' => 'Un link']]);
        $this->db->onSelect('COUNT(*) as count FROM page_followers', [['count' => '42']]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $this->assertStatus(200, $res);
        $this->assertSame('Mi página', $res->body['page']['title']);
        $this->assertSame(42, $res->body['page']['follower_count'], 'el contador debe ser int, no string');
        $this->assertSame('Un link', $res->body['page']['groups'][0]['links'][0]['text']);
    }

    public function testPageDevuelveCeroSeguidoresSiNoHayFilas()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $this->assertSame(0, $res->body['page']['follower_count']);
    }

    public function testPageSoloMuestraEventosFuturos()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);

        PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $llamada = $this->db->callsFor('WHERE group_id = ? AND event_date')[0];

        // >= y no >: un evento de hoy a las 20:30 todavía no pasó.
        $this->assertStringContainsString('event_date >= ?', $llamada['sql']);
        $this->assertContains(Fechas::hoy(), $llamada['params'], 'compara contra hoy en Argentina');
    }

    /**
     * El corte se calcula en Argentina y no con CURDATE(): el servidor corre en
     * UTC, tres horas adelante, así que a partir de las 21:00 la base creería
     * que ya es el día siguiente y los eventos de esa misma noche desaparecerían
     * de la página justo cuando la gente los está por ir a ver.
     */
    public function testElCorteDeFechaNoUsaLaHoraDelServidor()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);

        PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        foreach ($this->db->log() as $llamada) {
            $this->assertStringNotContainsString('CURDATE()', $llamada['sql']);
        }
    }

    public function testPageCargaColaboradoresDeCadaEvento()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);
        $this->db->onSelect('FROM links WHERE group_id = ? AND event_date', [['id' => 100, 'text' => 'Evento']]);
        $this->db->onSelect('JOIN pages p ON ec.collaborator_page_id', [['page_title' => 'Colaboradora']]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $evento = $res->body['page']['groups'][0]['links'][0];
        $this->assertSame('Colaboradora', $evento['collaborators'][0]['page_title']);
    }

    public function testPageSoloTraeColaboracionesAceptadas()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);

        PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $sql = $this->db->callsFor('FROM event_collaborations ec JOIN links l')[0]['sql'];

        $this->assertStringContainsString("ec.status = 'accepted'", $sql);
    }

    public function testPageDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('FROM pages WHERE url_slug = ?', 'sin conexión');

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'x']));

        $this->assertError(500, $res, 'Server error: sin conexión');
    }

    // ================================================================== event

    public function testEventExigeId()
    {
        $this->assertError(400, PublicHandler::event($this->db, $this->get()), 'ID de evento requerido');
    }

    public function testEventDevuelve404SiNoExiste()
    {
        $res = PublicHandler::event($this->db, $this->get(['id' => '99']));

        $this->assertError(404, $res, 'Evento no encontrado');
    }

    public function testEventDevuelveElEventoConSuPagina()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [[
            'id' => 100,
            'text' => 'Mi evento',
            'page_title' => 'Mi página',
            'page_slug' => 'mi-pagina',
        ]]);

        $res = PublicHandler::event($this->db, $this->get(['id' => '100']));

        $this->assertStatus(200, $res);
        $this->assertSame('Mi evento', $res->body['event']['text']);
        $this->assertSame('mi-pagina', $res->body['event']['page_slug']);
    }

    public function testEventSoloDevuelveLinksDeGruposDeEventos()
    {
        PublicHandler::event($this->db, $this->get(['id' => '100']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString("lg.type = 'eventos'", $sql);
    }

    public function testEventIncluyeElTextoDelBoton()
    {
        PublicHandler::event($this->db, $this->get(['id' => '100']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString('l.url_text', $sql);
    }

    // ================================================================= events

    public function testEventsUsaElRangoPorDefectoDeTreintaDias()
    {
        PublicHandler::events($this->db, $this->get());

        $params = $this->db->paramsFor('l.event_date BETWEEN ? AND ?');

        $this->assertSame(date('Y-m-d'), $params[0]);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $params[1]);
    }

    public function testEventsRespetaElRangoPedido()
    {
        PublicHandler::events($this->db, $this->get(['start' => '2026-01-01', 'end' => '2026-01-31']));

        $this->assertSame(['2026-01-01', '2026-01-31'], $this->db->paramsFor('l.event_date BETWEEN ? AND ?'));
    }

    public function testRangoPorDefectoEsDeterministaConFechaFija()
    {
        list($inicio, $fin) = PublicHandler::rangoPorDefecto(mktime(0, 0, 0, 1, 1, 2026));

        $this->assertSame('2026-01-01', $inicio);
        $this->assertSame('2026-01-31', $fin);
    }

    public function testEventsDevuelveLista()
    {
        $this->db->onSelect('l.event_date BETWEEN ? AND ?', [
            ['id' => 1, 'title' => 'Uno'],
            ['id' => 2, 'title' => 'Dos'],
        ]);

        $res = PublicHandler::events($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertCount(2, $res->body['events']);
    }

    // ============================================================== followers

    public function testFollowersExigePageId()
    {
        $this->assertError(400, PublicHandler::followers($this->db, $this->get()), 'ID de página requerido');
    }

    public function testFollowersRechazaPaginaInexistente()
    {
        $res = PublicHandler::followers($this->db, $this->get(['page_id' => '99']));

        $this->assertError(404, $res, 'Página no encontrada');
    }

    public function testFollowersDevuelveLaLista()
    {
        $this->db->onSelect('SELECT id FROM pages WHERE id = ?', [['id' => 5]]);
        $this->db->onSelect('FROM page_followers pf JOIN users u', [
            ['email' => 'a@b.com', 'page_title' => 'Su página', 'followed_at' => '2026-01-01'],
        ]);

        $res = PublicHandler::followers($this->db, $this->get(['page_id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['followers']);
    }

    public function testFollowersOrdenaPorMasRecientePrimero()
    {
        $this->db->onSelect('SELECT id FROM pages WHERE id = ?', [['id' => 5]]);

        PublicHandler::followers($this->db, $this->get(['page_id' => '5']));

        $sql = $this->db->callsFor('FROM page_followers pf JOIN users u')[0]['sql'];
        $this->assertStringContainsString('ORDER BY pf.created_at DESC', $sql);
    }

    // =========================================================== recent-pages

    public function testRecentPagesRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::recentPages($this->db, $this->post()), 'Method not allowed');
    }

    public function testRecentPagesLimitaADoceResultados()
    {
        PublicHandler::recentPages($this->db, $this->get());

        $sql = $this->db->callsFor('FROM pages p JOIN users u')[0]['sql'];

        $this->assertStringContainsString('LIMIT 12', $sql);
        $this->assertStringContainsString('ORDER BY p.created_at DESC', $sql);
    }

    public function testRecentPagesIncluyeElContadorDeSeguidores()
    {
        $this->db->onSelect('FROM pages p JOIN users u', [
            ['id' => 1, 'title' => 'Una', 'follower_count' => '3'],
        ]);

        $res = PublicHandler::recentPages($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertSame('3', $res->body['pages'][0]['follower_count']);
    }

    public function testRecentPagesDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('FROM pages p JOIN users u', 'timeout');

        $this->assertError(500, PublicHandler::recentPages($this->db, $this->get()), 'Server error: timeout');
    }

    // ========================================================== recent-events

    public function testRecentEventsRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::recentEvents($this->db, $this->post()), 'Method not allowed');
    }

    public function testRecentEventsSoloTraeEventosFuturos()
    {
        PublicHandler::recentEvents($this->db, $this->get());

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString("l.event_date >= '" . Fechas::hoy() . "'", $sql);
        $this->assertStringContainsString("lg.type = 'eventos'", $sql);
        $this->assertStringContainsString('LIMIT 30', $sql);
    }

    public function testRecentEventsDevuelveLista()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 1, 'text' => 'Evento']]);

        $res = PublicHandler::recentEvents($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['events']);
    }

    // ================================================================= search

    public function testSearchRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::search($this->db, $this->post()), 'Method not allowed');
    }

    /**
     * @dataProvider consultasCortas
     */
    public function testSearchNoConsultaConMenosDeDosCaracteres($q)
    {
        $res = PublicHandler::search($this->db, $this->get($q === null ? [] : ['q' => $q]));

        $this->assertStatus(200, $res);
        $this->assertSame(['results' => []], $res->body);
        $this->assertSame([], $this->db->log(), 'No debe tocar la base con una consulta tan corta');
    }

    public function consultasCortas()
    {
        return [
            'sin parámetro' => [null],
            'vacío' => [''],
            'una letra' => ['a'],
            'sólo espacios' => ['   '],
            'espacio y letra' => [' a '],
        ];
    }

    public function testSearchBuscaPaginasYEventos()
    {
        $this->db->onSelect('FROM pages p WHERE p.title LIKE', [
            ['id' => 1, 'title' => 'Rock en el parque'],
        ]);
        $this->db->onSelect('FROM links l JOIN link_groups lg', [
            ['id' => 100, 'title' => 'Recital de rock'],
        ]);

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertStatus(200, $res);
        $this->assertCount(2, $res->body['results']);
    }

    public function testSearchEtiquetaCadaResultadoConSuTipo()
    {
        $this->db->onSelect('FROM pages p WHERE p.title LIKE', [['id' => 1, 'title' => 'Una página']]);
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 100, 'title' => 'Un evento']]);

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertSame('page', $res->body['results'][0]['type']);
        $this->assertSame('event', $res->body['results'][1]['type']);
    }

    public function testSearchDevuelveLasPaginasAntesQueLosEventos()
    {
        $this->db->onSelect('FROM pages p WHERE p.title LIKE', [['id' => 1], ['id' => 2]]);
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 100]]);

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $tipos = array_column($res->body['results'], 'type');
        $this->assertSame(['page', 'page', 'event'], $tipos);
    }

    public function testSearchEnvuelveElTerminoEnComodines()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertSame(['%rock%', '%rock%'], $this->db->paramsFor('FROM pages p WHERE p.title LIKE'));
    }

    public function testSearchRecortaEspaciosDelTermino()
    {
        PublicHandler::search($this->db, $this->get(['q' => '  rock  ']));

        $this->assertSame(['%rock%', '%rock%'], $this->db->paramsFor('FROM pages p WHERE p.title LIKE'));
    }

    public function testSearchBuscaEventosPorTextoDescripcionYDireccion()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertSame(
            ['%rock%', '%rock%', '%rock%'],
            $this->db->paramsFor('FROM links l JOIN link_groups lg')
        );
    }

    public function testSearchNoDevuelveEventosPasados()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString("l.event_date >= '" . Fechas::hoy() . "'", $sql);
    }

    public function testSearchLimitaCadaTipoADiezResultados()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertStringContainsString('LIMIT 10', $this->db->callsFor('FROM pages p WHERE p.title LIKE')[0]['sql']);
        $this->assertStringContainsString('LIMIT 10', $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql']);
    }

    public function testSearchTrataElTerminoComoParametroNoComoSql()
    {
        // El término va siempre por placeholder: un intento de inyección debe
        // viajar como dato literal.
        PublicHandler::search($this->db, $this->get(['q' => "'; DROP TABLE pages; --"]));

        $sql = $this->db->callsFor('FROM pages p WHERE p.title LIKE')[0]['sql'];

        $this->assertStringNotContainsString('DROP TABLE', $sql);
        $this->assertSame("%'; DROP TABLE pages; --%", $this->db->paramsFor('FROM pages p WHERE p.title LIKE')[0]);
    }

    public function testSearchDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('FROM pages p WHERE p.title LIKE', 'índice corrupto');

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertError(500, $res, 'Server error: índice corrupto');
    }
}
