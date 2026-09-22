<?php

namespace Tests\Unit\Handlers;

use PuertaHandler;
use Request;
use Tests\Support\HandlerTestCase;

class PuertaHandlerTest extends HandlerTestCase
{
    private function sesion()
    {
        return ['user_id' => 7];
    }

    private function puedeAdministrarElEvento()
    {
        $this->db->onSelect('FROM links l', [['id' => 100, 'user_id' => 7, 'page_id' => 5]]);
    }

    private function claveValida()
    {
        return str_repeat('c', 32);
    }

    private function laClaveEsDelEvento($linkId = 100)
    {
        $this->db->onSelect('FROM event_door_access da', [[
            'id' => $linkId, 'text' => 'Fiesta', 'event_date' => '2026-12-31',
            'event_time' => '22:00:00', 'event_address' => 'Niceto', 'pagina' => 'Club',
        ]]);
    }

    // ------------------------------------------------------------------ link

    public function testElLinkExigeSesion()
    {
        $r = PuertaHandler::link($this->db, new Request('GET', [], ['link_id' => 100]));

        $this->assertSame(401, $r->status);
    }

    public function testUnExtranoNoPuedeGenerarElLink()
    {
        $this->db->onSelect('FROM links l', []);

        $r = PuertaHandler::link($this->db, new Request('POST', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(403, $r->status);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO event_door_access'));
    }

    public function testGenerarDevuelveElLinkConLaClave()
    {
        $this->puedeAdministrarElEvento();
        $this->db->onWrite('INSERT INTO event_door_access', 1);

        $r = PuertaHandler::link($this->db, new Request('POST', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(200, $r->status);
        $this->assertMatchesRegularExpression('#/puerta\#[a-f0-9]{32}$#', $r->body['url']);
    }

    public function testSinLinkGeneradoDevuelveNull()
    {
        $this->puedeAdministrarElEvento();

        $r = PuertaHandler::link($this->db, new Request('GET', [], ['link_id' => 100], $this->sesion()));

        $this->assertNull($r->body['url']);
    }

    public function testRevocarBorraElLink()
    {
        $this->puedeAdministrarElEvento();

        PuertaHandler::link($this->db, new Request('DELETE', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame([100], $this->db->paramsFor('DELETE FROM event_door_access'));
    }

    // ---------------------------------------------------------------- puerta

    public function testSinClaveValidaNoSeVeNada()
    {
        $r = PuertaHandler::puerta($this->db, new Request('POST', ['clave' => $this->claveValida()]));

        $this->assertSame(404, $r->status);
        $this->assertStringContainsString('no es válido', $r->body['error']);
    }

    /** La clave viaja en el cuerpo: un GET la dejaría escrita en la URL. */
    public function testSoloSeAceptaPost()
    {
        $r = PuertaHandler::puerta($this->db, new Request('GET', [], ['clave' => $this->claveValida()]));

        $this->assertSame(405, $r->status);
    }

    public function testElEstadoTraeElEventoYLaLista()
    {
        $this->laClaveEsDelEvento();
        $this->db->onSelect("estado = 'pagada'", [
            ['id' => 1, 'codigo' => 'A', 'nombre' => 'Ana', 'cantidad' => 2, 'ingresadas' => 0, 'ingreso_en' => null],
        ]);

        $r = PuertaHandler::puerta($this->db, new Request('POST', ['clave' => $this->claveValida(), 'accion' => 'estado']));

        $this->assertSame(200, $r->status);
        $this->assertSame('Fiesta', $r->body['evento']['text']);
        $this->assertCount(1, $r->body['ordenes']);
    }

    /** La clave de un evento sólo marca entradas de ese evento. */
    public function testIngresarUsaElEventoDeLaClave()
    {
        $this->laClaveEsDelEvento(100);
        $this->db->onWrite('SET ingresadas = ingresadas + ?', 1);

        PuertaHandler::puerta($this->db, new Request('POST', [
            'clave' => $this->claveValida(), 'accion' => 'ingresar', 'codigo' => 'ABC123DEF456', 'cantidad' => 2,
        ]));

        $this->assertSame([2, 'ABC123DEF456', 100, 2], $this->db->paramsFor('SET ingresadas = ingresadas + ?'));
    }

    public function testUnaAccionDesconocidaEsUnError()
    {
        $this->laClaveEsDelEvento();

        $r = PuertaHandler::puerta($this->db, new Request('POST', ['clave' => $this->claveValida(), 'accion' => 'borrar']));

        $this->assertSame(400, $r->status);
    }
}
