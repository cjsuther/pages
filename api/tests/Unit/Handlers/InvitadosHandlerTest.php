<?php

namespace Tests\Unit\Handlers;

use CodigoQR;
use InvitadosHandler;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

/**
 * Invitados sin costo: permisos, validación y que el alta no cobra nada.
 *
 * Lo que pasa en la base de verdad —cupo, puerta, anulación— está en
 * Tests\Integration\InvitadosTest.
 */
class InvitadosHandlerTest extends HandlerTestCase
{
    /** @var FakeMailer */
    private $mailer;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // La librería del QR avisa deprecaciones la primera vez que se carga.
        ob_start();
        CodigoQR::png('https://rezonar.test/entrada/PRECARGA');
        ob_end_clean();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new FakeMailer();
    }

    /** El evento 10 es de la página 5, y el usuario la administra. */
    private function administra()
    {
        $this->db->onSelect('FROM links l INNER JOIN link_groups lg', [['page_id' => 5]]);
        $this->db->onSelect('FROM pages p', [[1]]);
    }

    /** El usuario no es dueño ni administrador de la página del evento. */
    private function noAdministra()
    {
        $this->db->onSelect('FROM links l INNER JOIN link_groups lg', [['page_id' => 5]]);
        $this->db->onSelect('FROM pages p', []);
    }

    private function eventoConCapacidad($capacidad = 10, $ocupadas = 0, array $extra = [])
    {
        $this->db->onSelect('FROM event_ticketing WHERE link_id = ? FOR UPDATE', [array_merge([
            'id' => 1, 'link_id' => 10, 'activo' => 1, 'capacidad' => $capacidad, 'precio' => 5000,
            'moneda' => 'ARS', 'max_por_compra' => 10, 'max_invitados' => null, 'cargos_al_comprador' => 1,
            'tipos' => null, 'plano' => null,
        ], $extra)]);
        $this->db->onSelect('SELECT COALESCE(SUM(cantidad), 0) FROM ticket_orders', [[$ocupadas]]);
    }

    // ------------------------------------------------------------- acceso

    public function testExigeSesion()
    {
        $r = InvitadosHandler::invitados($this->db, $this->get(['link_id' => 10]));

        $this->assertSame(401, $r->status);
    }

    public function testExigeElEvento()
    {
        $r = InvitadosHandler::invitados($this->db, $this->get([], $this->user(7)));

        $this->assertSame(400, $r->status);
    }

    public function testMetodoNoPermitido()
    {
        $r = InvitadosHandler::invitados($this->db, $this->delete(['link_id' => 10], $this->user(7)));

        $this->assertSame(405, $r->status);
    }

    /**
     * Quien no administra la página del evento —por ejemplo, el dueño de una
     * página colaboradora, que muestra el evento pero no lo organiza— no ve
     * la lista ni puede agregar a nadie.
     */
    public function testQuienNoAdministraLaPaginaDelEventoNoVeLaLista()
    {
        $this->noAdministra();

        $r = InvitadosHandler::invitados($this->db, $this->get(['link_id' => 10], $this->user(7)));

        $this->assertSame(403, $r->status);
    }

    public function testQuienNoAdministraNoPuedeAgregar()
    {
        $this->noAdministra();

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Colado'], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(403, $r->status);
        $this->assertFalse($this->db->ran('INSERT INTO ticket_orders'));
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testUnEventoQueNoExisteNoDaPermiso()
    {
        $r = InvitadosHandler::invitados($this->db, $this->get(['link_id' => 999], $this->user(7)));

        $this->assertSame(403, $r->status);
    }

    // --------------------------------------------------------------- alta

    public function testFaltaElNombre()
    {
        $this->administra();

        $r = InvitadosHandler::invitados($this->db, $this->post(['link_id' => 10, 'nombre' => '  '], $this->user(7)), $this->mailer);

        $this->assertSame(400, $r->status);
        $this->assertFalse($this->db->ran('INSERT INTO ticket_orders'));
    }

    public function testUnEmailCargadoTieneQueSerValido()
    {
        $this->administra();

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Ana', 'email' => 'no-es-un-mail'], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(400, $r->status);
    }

    public function testUnCampoQueNoEsTextoSeRechaza()
    {
        $this->administra();

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => ['Ana']], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(400, $r->status);
    }

    public function testLaCantidadTieneTope()
    {
        $this->administra();

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Ana', 'cantidad' => 21], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(400, $r->status);
    }

    public function testSinEntradasConfiguradasNoSePuedeInvitar()
    {
        $this->administra();

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Ana'], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('capacidad', $r->body['error']);
        $this->assertTrue($this->db->rolledBack);
    }

    /** Los invitados descuentan de la capacidad: no se puede regalar lo que no hay. */
    public function testNoSePuedeInvitarPorEncimaDeLaCapacidad()
    {
        $this->administra();
        $this->eventoConCapacidad(10, 9);

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Ana', 'cantidad' => 2], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('Sólo quedan 1', $r->body['error']);
        $this->assertFalse($this->db->ran('INSERT INTO ticket_orders'));
    }

    public function testRespetaElLimiteDeInvitados()
    {
        $this->administra();
        $this->eventoConCapacidad(100, 10, ['max_invitados' => 5]);
        // Lugares de invitados vigentes.
        $this->db->onSelect("origen = 'invitacion' AND estado = 'pagada'", [[4]]);

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Ana', 'cantidad' => 2], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('Quedan 1 lugares para invitados', $r->body['error']);
    }

    /**
     * El corazón del pedido: un invitado no pasa por Mercado Pago, no lleva
     * comisión ni cargos aunque el evento los sume, y queda marcado.
     */
    public function testElInvitadoNaceSinCostoNiComisionYMarcado()
    {
        $this->administra();
        $this->eventoConCapacidad(10, 0);
        $this->db->onInsert('INSERT INTO ticket_orders', 77);
        // La orden recién creada, como la lee el envío del mail.
        $this->db->onSelect('FROM ticket_orders o INNER JOIN links l', [[
            'id' => 77, 'codigo' => 'ABCDEF123456', 'link_id' => 10, 'origen' => 'invitacion', 'estado' => 'pagada',
            'email' => 'ana@test.local', 'nombre' => 'Ana Invitada', 'cantidad' => 2, 'total' => 0, 'moneda' => 'ARS',
            'cargo_mercadopago' => 0, 'cargo_plataforma' => 0, 'mail_enviado_en' => null, 'mail_intentos' => 0,
            'evento' => 'Noche', 'event_date' => null, 'event_time' => null, 'event_address' => null,
            'evento_imagen' => null, 'pagina' => 'La Sala', 'url_slug' => 'la-sala', 'email_contacto' => null,
        ]]);

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Ana Invitada', 'email' => 'ana@test.local', 'cantidad' => 2], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(201, $r->status);
        $this->assertTrue($this->db->committed);

        $insert = $this->db->callsFor('INSERT INTO ticket_orders')[0];
        $this->assertStringContainsString("'invitacion'", $insert['sql']);
        $this->assertStringContainsString("'pagada'", $insert['sql']);
        // total, precio, cargos y comisiones van fijos en 0 en la consulta.
        $this->assertMatchesRegularExpression('/VALUES \(\?, \?, \?, \'invitacion\', \?, \?, \?, \?, \?, 0, 0, 0, 0, 0, 0,/', preg_replace('/\s+/', ' ', $insert['sql']));
        $this->assertSame(7, $insert['params'][3]);
        $this->assertSame('Ana Invitada', $insert['params'][4]);
        $this->assertSame(2, $insert['params'][7]);

        // Nada de Mercado Pago ni de comisión.
        $this->assertFalse($this->db->ran('mp_preference_id'));
        $this->assertFalse($this->db->ran('SET comision'));

        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame('Tu invitación para Noche', $this->mailer->enviados[0]['asunto']);
        $this->assertStringContainsString('Estás invitado por La Sala', $this->mailer->enviados[0]['texto']);
        $this->assertTrue($r->body['mail']['enviado']);
        $this->assertStringContainsString('/entrada/', $r->body['invitado']['url']);
    }

    public function testSinEmailNoSeMandaMailYSeDevuelveElLink()
    {
        $this->administra();
        $this->eventoConCapacidad(10, 0);
        $this->db->onInsert('INSERT INTO ticket_orders', 78);

        $r = InvitadosHandler::invitados(
            $this->db,
            $this->post(['link_id' => 10, 'nombre' => 'Sin Mail'], $this->user(7)),
            $this->mailer
        );

        $this->assertSame(201, $r->status);
        $this->assertSame([], $this->mailer->enviados);
        $this->assertFalse($r->body['mail']['enviado']);
        $this->assertSame('', $this->db->callsFor('INSERT INTO ticket_orders')[0]['params'][5]);
        $this->assertStringContainsString('/entrada/', $r->body['invitado']['url']);
    }

    // -------------------------------------------------------------- límite

    public function testElLimiteTieneQueSerEntero()
    {
        $this->administra();

        $r = InvitadosHandler::invitados($this->db, $this->put(['link_id' => 10, 'max_invitados' => 'muchos'], $this->user(7)));

        $this->assertSame(400, $r->status);
    }

    public function testElLimiteNoBajaDeLoYaInvitado()
    {
        $this->administra();
        $this->db->onSelect('SELECT * FROM event_ticketing WHERE link_id = ?', [['link_id' => 10, 'capacidad' => 100, 'tipos' => null, 'plano' => null]]);
        $this->db->onSelect("origen = 'invitacion' AND estado = 'pagada'", [[6]]);

        $r = InvitadosHandler::invitados($this->db, $this->put(['link_id' => 10, 'max_invitados' => 5], $this->user(7)));

        $this->assertSame(409, $r->status);
        $this->assertFalse($this->db->ran('UPDATE event_ticketing SET max_invitados'));
    }

    // ------------------------------------------------- anular y reenviar

    public function testLasAccionesExigenUnCodigoValido()
    {
        $r = InvitadosHandler::invitado($this->db, $this->post(['codigo' => 'x', 'accion' => 'anular'], $this->user(7)));

        $this->assertSame(400, $r->status);
    }

    public function testLaAccionTieneQueSerConocida()
    {
        $r = InvitadosHandler::invitado($this->db, $this->post(['codigo' => 'ABCDEF123456', 'accion' => 'borrar'], $this->user(7)));

        $this->assertSame(400, $r->status);
    }

    /** Por acá sólo se tocan invitados: una compra se cancela desde Ventas. */
    public function testNoSePuedeAnularUnaCompraPorAca()
    {
        $this->db->onSelect('FROM ticket_orders o INNER JOIN links l', [[
            'id' => 1, 'codigo' => 'ABCDEF123456', 'link_id' => 10, 'origen' => 'venta', 'estado' => 'pagada', 'email' => 'a@b.c',
        ]]);

        $r = InvitadosHandler::invitado($this->db, $this->post(['codigo' => 'ABCDEF123456', 'accion' => 'anular'], $this->user(7)));

        $this->assertSame(404, $r->status);
        $this->assertFalse($this->db->ran("SET estado = 'cancelada'"));
    }

    public function testQuienNoAdministraNoPuedeAnular()
    {
        $this->db->onSelect('FROM ticket_orders o INNER JOIN links l', [[
            'id' => 1, 'codigo' => 'ABCDEF123456', 'link_id' => 10, 'origen' => 'invitacion', 'estado' => 'pagada', 'email' => '',
        ]]);
        $this->noAdministra();

        $r = InvitadosHandler::invitado($this->db, $this->post(['codigo' => 'ABCDEF123456', 'accion' => 'anular'], $this->user(7)));

        $this->assertSame(403, $r->status);
        $this->assertFalse($this->db->ran("SET estado = 'cancelada'"));
    }

    public function testNoSeReenviaSinEmail()
    {
        $this->db->onSelect('FROM ticket_orders o INNER JOIN links l', [[
            'id' => 1, 'codigo' => 'ABCDEF123456', 'link_id' => 10, 'origen' => 'invitacion', 'estado' => 'pagada', 'email' => '',
        ]]);
        $this->administra();

        $r = InvitadosHandler::invitado($this->db, $this->post(['codigo' => 'ABCDEF123456', 'accion' => 'reenviar'], $this->user(7)), $this->mailer);

        $this->assertSame(409, $r->status);
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testNoSeReenviaUnAnulado()
    {
        $this->db->onSelect('FROM ticket_orders o INNER JOIN links l', [[
            'id' => 1, 'codigo' => 'ABCDEF123456', 'link_id' => 10, 'origen' => 'invitacion', 'estado' => 'cancelada', 'email' => 'a@b.c',
        ]]);
        $this->administra();

        $r = InvitadosHandler::invitado($this->db, $this->post(['codigo' => 'ABCDEF123456', 'accion' => 'reenviar'], $this->user(7)), $this->mailer);

        $this->assertSame(409, $r->status);
        $this->assertSame([], $this->mailer->enviados);
    }
}
