<?php

namespace Tests\Unit\Handlers;

use CompartirHandler;
use Request;
use Tests\Support\HandlerTestCase;

class CompartirHandlerTest extends HandlerTestCase
{
    private function sesion()
    {
        return ['user_id' => 7];
    }

    private function puedeAdministrarElEvento()
    {
        $this->db->onSelect('FROM links l', [['id' => 100, 'user_id' => 7, 'page_id' => 5]]);
    }

    private function clave()
    {
        return str_repeat('d', 32);
    }

    private function laClaveEsDelEvento()
    {
        $this->db->onSelect('FROM event_access_links al', [[
            'id' => 100, 'text' => 'Fiesta', 'event_date' => '2026-12-31', 'event_time' => '22:00:00',
            'event_address' => 'Niceto', 'image_url' => null, 'pagina' => 'Club', 'url_slug' => 'club',
            'primary_color' => '#6FBE44', 'secondary_color' => null, 'card_color' => null,
            'title_color' => null, 'background_color' => '#FFFFFF', 'text_color' => '#000000',
        ]]);
    }

    private function hayVenta()
    {
        $this->db->onSelect('FROM event_ticketing WHERE link_id', [[
            'id' => 1, 'link_id' => 100, 'activo' => 1, 'capacidad' => 120,
            'precio' => '15000.00', 'moneda' => 'ARS', 'max_por_compra' => 10, 'plano' => null,
        ]]);
        $this->db->onSelect('AS vendidas', [[
            'vendidas' => 84, 'recaudado' => '1260000.00', 'ingresadas' => 0,
            'reservadas' => 0, 'compras' => 40,
        ]]);
    }

    // ------------------------------------------------------------------ link

    public function testElLinkExigeSesion()
    {
        $r = CompartirHandler::link($this->db, new Request('GET', [], ['link_id' => 100]));

        $this->assertSame(401, $r->status);
    }

    public function testUnExtranoNoPuedeGenerarElLink()
    {
        $this->db->onSelect('FROM links l', []);

        $r = CompartirHandler::link($this->db, new Request('POST', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(403, $r->status);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO event_access_links'));
    }

    public function testGenerarDevuelveElLinkDeVenta()
    {
        $this->puedeAdministrarElEvento();
        $this->db->onWrite('INSERT INTO event_access_links', 1);

        $r = CompartirHandler::link($this->db, new Request('POST', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(200, $r->status);
        $this->assertMatchesRegularExpression('#/venta\#[a-f0-9]{32}$#', $r->body['url']);
        $this->assertSame('ventas', $this->db->paramsFor('INSERT INTO event_access_links')[1]);
    }

    public function testRevocarBorraElLink()
    {
        $this->puedeAdministrarElEvento();

        CompartirHandler::link($this->db, new Request('DELETE', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame([100, 'ventas'], $this->db->paramsFor('DELETE FROM event_access_links'));
    }

    // ----------------------------------------------------------------- venta

    public function testSinClaveValidaNoSeVeNada()
    {
        $r = CompartirHandler::venta($this->db, new Request('POST', ['clave' => $this->clave()]));

        $this->assertSame(404, $r->status);
        $this->assertStringContainsString('no es válido', $r->body['error']);
    }

    /** La clave viaja en el cuerpo: un GET la dejaría escrita en la URL. */
    public function testSoloSeAceptaPost()
    {
        $r = CompartirHandler::venta($this->db, new Request('GET', [], ['clave' => $this->clave()]));

        $this->assertSame(405, $r->status);
    }

    public function testMuestraElEventoYComoVieneLaVenta()
    {
        $this->laClaveEsDelEvento();
        $this->hayVenta();

        $r = CompartirHandler::venta($this->db, new Request('POST', ['clave' => $this->clave()]));

        $this->assertSame(200, $r->status);
        $this->assertSame('Fiesta', $r->body['evento']['text']);
        $this->assertSame(84, $r->body['venta']['vendidas']);
        $this->assertSame(1260000.0, $r->body['venta']['recaudado']);
    }

    /** El tipo va en la consulta: la clave de la puerta no abre esta pantalla. */
    public function testLaClaveSeBuscaComoDeVentas()
    {
        $this->laClaveEsDelEvento();
        $this->hayVenta();

        CompartirHandler::venta($this->db, new Request('POST', ['clave' => $this->clave()]));

        $this->assertSame(
            [hash('sha256', $this->clave()), 'ventas'],
            $this->db->paramsFor('FROM event_access_links al')
        );
    }

    /** Se comparte por WhatsApp: los datos de los compradores no van ahí. */
    public function testNoDevuelveCompradores()
    {
        $this->laClaveEsDelEvento();
        $this->hayVenta();

        $r = CompartirHandler::venta($this->db, new Request('POST', ['clave' => $this->clave()]));

        $this->assertArrayNotHasKey('ordenes', $r->body);
        $this->assertStringNotContainsString('@', json_encode($r->body));
    }
}
