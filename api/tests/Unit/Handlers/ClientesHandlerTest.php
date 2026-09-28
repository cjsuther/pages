<?php

namespace Tests\Unit\Handlers;

use ClientesHandler;
use Tests\Support\HandlerTestCase;

class ClientesHandlerTest extends HandlerTestCase
{
    private function puedeAdministrar()
    {
        $this->db->onSelect('SELECT 1 FROM pages p', [[1]]);
    }

    public function testPideSesion()
    {
        $this->assertStatus(401, ClientesHandler::index($this->db, $this->get(['page_id' => 5])));
    }

    public function testPidePagina()
    {
        $this->assertError(400, ClientesHandler::index($this->db, $this->get([], $this->user())), 'page_id');
    }

    /** Son datos de contacto de terceros. */
    public function testSoloLosVeQuienAdministraLaPagina()
    {
        $res = ClientesHandler::index($this->db, $this->get(['page_id' => 5], $this->user()));

        $this->assertError(403, $res);
        $this->assertFalse($this->db->ran('FROM ticket_orders'));
    }

    public function testDevuelveClientesEventosYResumen()
    {
        $this->puedeAdministrar();

        $res = ClientesHandler::index($this->db, $this->get(['page_id' => 5], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame([], $res->body['clientes']);
        $this->assertSame([], $res->body['eventos']);
        $this->assertSame(0, $res->body['resumen']['clientes']);
    }

    public function testExportaAExcel()
    {
        $this->puedeAdministrar();
        $this->db->onSelect('SELECT url_slug FROM pages', [['la-trastienda']]);

        $res = ClientesHandler::index($this->db, $this->get(['page_id' => 5, 'formato' => 'excel'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertContains('Content-Disposition: attachment; filename="clientes-la-trastienda.xlsx"', $res->headers);
    }

    public function testSoloAceptaGet()
    {
        $this->assertStatus(405, ClientesHandler::index($this->db, $this->post(['page_id' => 5], $this->user())));
    }
}
