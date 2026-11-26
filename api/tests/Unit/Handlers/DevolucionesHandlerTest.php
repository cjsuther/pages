<?php

namespace Tests\Unit\Handlers;

use Devoluciones;
use DevolucionesHandler;
use Request;
use Tests\Support\FakeHttpClient;
use Tests\Support\HandlerTestCase;

/**
 * La puerta de las devoluciones: quién puede, con qué pedido, y que lo que no
 * es un intento (sin sesión, pedido mal formado, compra ajena) no deje rastro
 * ni llegue a Mercado Pago.
 */
class DevolucionesHandlerTest extends HandlerTestCase
{
    /** @var FakeHttpClient */
    private $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttpClient();
    }

    protected function tearDown(): void
    {
        Devoluciones::forzar(null);
        parent::tearDown();
    }

    private function sesion()
    {
        return ['user_id' => 7];
    }

    private function hayOrden(array $overrides = [])
    {
        $this->db->onSelect('FROM ticket_orders o', [array_merge([
            'id' => 1, 'codigo' => 'ABC123DEF456', 'link_id' => 100, 'origen' => 'venta',
            'nombre' => 'Ana Gómez', 'email' => 'ana@example.com', 'cantidad' => 2, 'ingresadas' => 0,
            'total' => '10000.00', 'comision' => '100.00', 'mp_comision_cobrada' => '100.00',
            'mp_neto' => '9368.81', 'moneda' => 'ARS', 'estado' => 'pagada', 'mp_payment_id' => '99',
            'pagada_en' => date('Y-m-d H:i:s'), 'evento' => 'Show',
        ], $overrides)]);
    }

    private function puedeAdministrarElEvento()
    {
        $this->db->onSelect('FROM links l', [['id' => 100, 'user_id' => 7, 'page_id' => 5]]);
    }

    private function noHuboIntento()
    {
        $this->assertFalse($this->db->ran('INSERT INTO ticket_order_devoluciones'), 'no se anota nada');
        $this->assertSame([], $this->http->llamadas, 'no se llama a Mercado Pago');
    }

    public function testExigeSesion()
    {
        $r = DevolucionesHandler::devolucion($this->db, new Request('POST', ['codigo' => 'ABC123DEF456']), $this->http);

        $this->assertSame(401, $r->status);
        $this->noHuboIntento();
    }

    public function testSoloGetYPost()
    {
        $r = DevolucionesHandler::devolucion($this->db, new Request('DELETE', [], [], $this->sesion()), $this->http);

        $this->assertSame(405, $r->status);
    }

    public function testUnCodigoMalFormadoNoEsUnIntento()
    {
        Devoluciones::forzar(true);

        $r = DevolucionesHandler::devolucion($this->db, $this->post(['codigo' => "x' OR 1=1"], $this->sesion()), $this->http);

        $this->assertSame(400, $r->status);
        $this->noHuboIntento();
    }

    public function testUnaCompraQueNoExiste()
    {
        $r = DevolucionesHandler::devolucion($this->db, $this->post(['codigo' => 'NOEXISTE1234', 'modo' => 'completa'], $this->sesion()), $this->http);

        $this->assertSame(404, $r->status);
        $this->noHuboIntento();
    }

    /** La plata sale de la cuenta del organizador: nadie más la puede mover. */
    public function testUnExtranoNoPuedeDevolver()
    {
        Devoluciones::forzar(true);
        $this->hayOrden();

        $r = DevolucionesHandler::devolucion($this->db, $this->post(
            ['codigo' => 'ABC123DEF456', 'modo' => 'completa', 'confirmacion' => 9368.81], $this->sesion()
        ), $this->http);

        $this->assertSame(403, $r->status);
        $this->noHuboIntento();
    }

    public function testConLasDevolucionesApagadasLaPantallaNoAbre()
    {
        $this->hayOrden();
        $this->puedeAdministrarElEvento();

        $r = DevolucionesHandler::devolucion($this->db, new Request('GET', [], ['codigo' => 'ABC123DEF456'], $this->sesion()), $this->http);

        $this->assertSame(403, $r->status);
        $this->noHuboIntento();
    }

    /** Con el interruptor apagado, un intento igual queda registrado con su motivo. */
    public function testConLasDevolucionesApagadasElIntentoSeAnotaComoRechazado()
    {
        $this->hayOrden();
        $this->puedeAdministrarElEvento();
        $this->hayOrden();

        $r = DevolucionesHandler::devolucion($this->db, $this->post(
            ['codigo' => 'ABC123DEF456', 'modo' => 'completa', 'confirmacion' => 9368.81], $this->sesion()
        ), $this->http);

        $this->assertSame(403, $r->status);
        $this->assertSame(Devoluciones::MOTIVOS['apagadas'], $r->body['error']);
        $this->assertTrue($this->db->ran('INSERT INTO ticket_order_devoluciones'));
        $this->assertContains(Devoluciones::MOTIVOS['apagadas'], $this->db->paramsFor('INSERT INTO ticket_order_devoluciones'));
        $this->assertTrue($this->db->ran('SET devolucion_error = ?'), 'queda visible en la compra');
        $this->assertSame([], $this->http->llamadas);
    }

    public function testUnModoInventadoEsUnPedidoMalFormado()
    {
        Devoluciones::forzar(true);
        $this->hayOrden();
        $this->puedeAdministrarElEvento();
        $this->hayOrden();

        $r = DevolucionesHandler::devolucion($this->db, $this->post(
            ['codigo' => 'ABC123DEF456', 'modo' => 'todo', 'confirmacion' => 1], $this->sesion()
        ), $this->http);

        $this->assertSame(400, $r->status);
        $this->noHuboIntento();
    }

    public function testSinConfirmarElMontoNoSeDevuelve()
    {
        Devoluciones::forzar(true);
        $this->hayOrden();
        $this->puedeAdministrarElEvento();
        $this->hayOrden();

        $r = DevolucionesHandler::devolucion($this->db, $this->post(
            ['codigo' => 'ABC123DEF456', 'modo' => 'completa'], $this->sesion()
        ), $this->http);

        $this->assertSame(400, $r->status);
        $this->noHuboIntento();
    }

    public function testUnInvitadoNoSeDevuelveYQuedaElMotivo()
    {
        Devoluciones::forzar(true);
        $this->hayOrden(['origen' => 'invitacion', 'total' => '0.00', 'mp_payment_id' => null]);
        $this->puedeAdministrarElEvento();
        $this->hayOrden(['origen' => 'invitacion', 'total' => '0.00', 'mp_payment_id' => null]);

        $r = DevolucionesHandler::devolucion($this->db, $this->post(
            ['codigo' => 'ABC123DEF456', 'modo' => 'completa', 'confirmacion' => 0], $this->sesion()
        ), $this->http);

        $this->assertSame(409, $r->status);
        $this->assertSame(Devoluciones::MOTIVOS['invitado'], $r->body['error']);
        $this->assertTrue($this->db->ran('INSERT INTO ticket_order_devoluciones'));
        $this->assertSame([], $this->http->llamadas);
    }
}
