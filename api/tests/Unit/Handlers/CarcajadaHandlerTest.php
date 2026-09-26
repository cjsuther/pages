<?php

namespace Tests\Unit\Handlers;

use CarcajadaHandler;
use Request;
use Tests\Support\HandlerTestCase;

class CarcajadaHandlerTest extends HandlerTestCase
{
    private function sesion()
    {
        return ['user_id' => 7, 'email' => 'comediante@test.local'];
    }

    private function produce($si = true)
    {
        // Plataforma pregunta por el correo y después se mira la lista propia.
        $this->db->onSelect('FROM users WHERE id', [['email' => 'comediante@test.local']]);
        $this->db->onSelect('FROM carcajada_productores', $si ? [[1]] : []);
    }

    // ------------------------------------------------------------ comediante

    public function testAnotarseExigeCuenta()
    {
        $r = CarcajadaHandler::comediante($this->db, new Request('GET'));

        $this->assertSame(401, $r->status);
    }

    /**
     * Quien entra por primera vez no tiene que volver a tipear lo que ya
     * cargó en su página de Rezonar.
     */
    public function testAlEntrarTraeLoQueYaSabemosDeSuPagina()
    {
        $this->db->onSelect('FROM carcajada_comediantes c', []);
        $this->db->onSelect('FROM pages p', [[
            'id' => 5, 'title' => 'Ana Gómez', 'url_slug' => 'anagomez', 'profile_image' => 'https://x/ana.jpg',
        ]]);
        $this->db->onSelect('FROM page_socials', [['red' => 'instagram', 'url' => 'https://instagram.com/anagomez']]);
        $this->produce(false);

        $r = CarcajadaHandler::comediante($this->db, new Request('GET', [], [], $this->sesion()));

        $this->assertNull($r->body['comediante'], 'todavía no se anotó');
        $this->assertSame('anagomez', $r->body['sugerencia']['instagram']);
        $this->assertSame('https://x/ana.jpg', $r->body['sugerencia']['foto_url']);
        $this->assertFalse($r->body['productor']);
    }

    /** Sin página en Rezonar no hay nada que sugerir: el alta lo va a pedir. */
    public function testSinPaginaNoHaySugerencia()
    {
        $this->db->onSelect('FROM carcajada_comediantes c', []);
        $this->db->onSelect('FROM pages p', []);
        $this->produce(false);

        $r = CarcajadaHandler::comediante($this->db, new Request('GET', [], [], $this->sesion()));

        $this->assertNull($r->body['sugerencia']);
    }

    public function testElErrorDelAltaLlegaComoTal()
    {
        $this->db->onSelect('FROM pages WHERE id = ? AND user_id = ?', []);

        $r = CarcajadaHandler::comediante($this->db, new Request('POST', [
            'page_id' => 5, 'nombre' => 'Ana',
        ], [], $this->sesion()));

        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('no es tuya', $r->body['error']);
    }

    // ------------------------------------------------------------- productor

    public function testUnComedianteNoPuedeVerLasFichasDeLosDemas()
    {
        $this->produce(false);

        $r = CarcajadaHandler::comediantes($this->db, new Request('GET', [], [], $this->sesion()));

        $this->assertSame(403, $r->status);
    }

    public function testUnComedianteNoPuedeArmarShows()
    {
        $this->produce(false);

        $r = CarcajadaHandler::shows($this->db, new Request('POST', [
            'ciclo_id' => 1, 'fecha' => '2026-10-15',
        ], [], $this->sesion()));

        $this->assertSame(403, $r->status);
        $this->assertNoWrites();
    }

    public function testUnComedianteNoPuedeEvaluar()
    {
        $this->produce(false);

        $r = CarcajadaHandler::show($this->db, new Request('POST', [
            'accion' => 'evaluar', 'comediante_id' => 9, 'puntaje' => 5,
        ], ['id' => 3], $this->sesion()));

        $this->assertSame(403, $r->status);
        $this->assertNoWrites();
    }

    public function testSinSesionTampoco()
    {
        $this->assertSame(401, CarcajadaHandler::shows($this->db, new Request('GET'))->status);
        $this->assertSame(401, CarcajadaHandler::comediantes($this->db, new Request('GET'))->status);
        $this->assertSame(401, CarcajadaHandler::show($this->db, new Request('GET', [], ['id' => 1]))->status);
    }

    public function testQuienProduceCreaUnShow()
    {
        $this->produce();
        $this->db->onInsert('INSERT INTO carcajada_shows', 12);
        $this->db->onSelect('FROM carcajada_shows s', [[
            'id' => 12, 'fecha' => '2026-10-15', 'hora' => '21:00:00', 'lugar' => null, 'notas' => null,
            'ciclo_id' => 1, 'ciclo' => 'JaJaJaJueves', 'ciclo_slug' => 'jajajajueves',
        ]]);
        $this->db->onSelect('FROM carcajada_lineup l', []);

        $r = CarcajadaHandler::shows($this->db, new Request('POST', [
            'ciclo_id' => 1, 'fecha' => '2026-10-15', 'hora' => '21:00',
        ], [], $this->sesion()));

        $this->assertSame(200, $r->status);
        $this->assertSame(12, $r->body['show']['id']);
    }

    public function testUnShowSinFechaNoSeCrea()
    {
        $this->produce();

        $r = CarcajadaHandler::shows($this->db, new Request('POST', ['ciclo_id' => 1], [], $this->sesion()));

        $this->assertSame(400, $r->status);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO carcajada_shows'));
    }

    public function testUnaAccionDesconocidaSobreUnShowEsUnError()
    {
        $this->produce();

        $r = CarcajadaHandler::show($this->db, new Request('POST', ['accion' => 'romper'], ['id' => 3], $this->sesion()));

        $this->assertSame(400, $r->status);
    }

    // --------------------------------------------------------------- público

    /** Es un cartel en la pared de un bar: no puede pedir sesión. */
    public function testLaPantallaDelQrNoPideNada()
    {
        $this->db->onSelect('WHERE s.fecha >= ?', []);

        $r = CarcajadaHandler::hoy($this->db, new Request('GET'));

        $this->assertSame(200, $r->status);
        $this->assertNull($r->body['show']);
    }
}
