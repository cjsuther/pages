<?php

namespace Tests\Unit\Handlers;

use CollaborationsHandler;
use Tests\Support\HandlerTestCase;

class CollaborationsHandlerTest extends HandlerTestCase
{
    // =================================================================== index

    public function testIndexExigeSesion()
    {
        $this->assertError(401, CollaborationsHandler::index($this->db, $this->get()), 'Unauthorized');
    }

    public function testIndexRechazaMetodoNoSoportado()
    {
        $res = CollaborationsHandler::index($this->db, $this->put([], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    public function testListarExigeLinkIdOTypePending()
    {
        $res = CollaborationsHandler::index($this->db, $this->get([], $this->user()));

        $this->assertError(400, $res, 'Missing parameters: link_id or type=pending required');
    }

    public function testListarPorEventoRechazaEventoAjeno()
    {
        $res = CollaborationsHandler::index($this->db, $this->get(['link_id' => '100'], $this->user()));

        $this->assertError(403, $res, 'Forbidden');
    }

    public function testListarPorEventoDevuelveLasColaboraciones()
    {
        $this->esDuenoDelEvento();
        $this->db->onSelect('FROM event_collaborations ec JOIN pages p', [
            ['id' => 1, 'status' => 'pending', 'page_title' => 'Otra'],
        ]);

        $res = CollaborationsHandler::index($this->db, $this->get(['link_id' => '100'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['collaborations']);
        $this->assertSame([100], $this->db->paramsFor('FROM event_collaborations ec JOIN pages p'));
    }

    public function testListarPendientesDeMisPaginas()
    {
        $this->db->onSelect('WHERE cp.user_id = ? AND ec.status = "pending"', [
            ['id' => 1, 'event_title' => 'Un evento'],
        ]);

        $res = CollaborationsHandler::index($this->db, $this->get(['type' => 'pending'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['pending']);
        $this->assertSame([9], $this->db->paramsFor('WHERE cp.user_id = ?'));
    }

    public function testLinkIdTienePrioridadSobreTypePending()
    {
        $this->esDuenoDelEvento();

        CollaborationsHandler::index($this->db, $this->get([
            'link_id' => '100', 'type' => 'pending',
        ], $this->user(9)));

        $this->assertSame(0, $this->db->countCalls('WHERE cp.user_id = ? AND ec.status = "pending"'));
    }

    // ------------------------------------------------------------- invitar

    public function testInvitarExigeLinkIdYPagina()
    {
        $res = CollaborationsHandler::index($this->db, $this->post(['link_id' => 100], $this->user()));

        $this->assertError(400, $res, 'link_id and collaborator_page_id are required');
        $this->assertNoWrites();
    }

    public function testInvitarRechazaEventoAjenoOInexistente()
    {
        $res = CollaborationsHandler::index($this->db, $this->post([
            'link_id' => 100, 'collaborator_page_id' => 7,
        ], $this->user()));

        $this->assertError(404, $res, 'Event not found or not authorized');
        $this->assertNoWrites();
    }

    public function testInvitarSoloAceptaEventosDeGruposDeEventos()
    {
        CollaborationsHandler::index($this->db, $this->post([
            'link_id' => 100, 'collaborator_page_id' => 7,
        ], $this->user(9)));

        $sql = $this->db->callsFor('SELECT l.id, l.text as event_title')[0]['sql'];

        $this->assertStringContainsString('lg.type = "eventos"', $sql);
        $this->assertStringContainsString('p.user_id = ?', $sql);
    }

    public function testInvitarRechazaPaginaColaboradoraInexistente()
    {
        $this->eventoPropio();

        $res = CollaborationsHandler::index($this->db, $this->post([
            'link_id' => 100, 'collaborator_page_id' => 7,
        ], $this->user(9)));

        $this->assertError(404, $res, 'Collaborator page not found');
    }

    public function testInvitarRechazaLaPropiaPaginaDelEvento()
    {
        $this->eventoPropio();
        $this->db->onSelect('SELECT id, user_id FROM pages WHERE id = ?', [['id' => 5, 'user_id' => 9]]);

        $res = CollaborationsHandler::index($this->db, $this->post([
            'link_id' => 100, 'collaborator_page_id' => 5,
        ], $this->user(9)));

        $this->assertError(400, $res, 'No puedes invitar tu propia página');
        $this->assertNoWrites();
    }

    public function testInvitarRechazaDuplicados()
    {
        $this->eventoPropio();
        $this->db->onSelect('SELECT id, user_id FROM pages WHERE id = ?', [['id' => 7, 'user_id' => 11]]);
        $this->db->onSelect('SELECT id FROM event_collaborations WHERE link_id = ?', [['id' => 3]]);

        $res = CollaborationsHandler::index($this->db, $this->post([
            'link_id' => 100, 'collaborator_page_id' => 7,
        ], $this->user(9)));

        $this->assertError(409, $res, 'Esta página ya fue invitada a colaborar');
    }

    public function testInvitarCreaLaColaboracion()
    {
        $this->invitacionValida();

        $res = CollaborationsHandler::index($this->db, $this->post([
            'link_id' => 100, 'collaborator_page_id' => 7,
        ], $this->user(9)));

        $this->assertStatus(201, $res);
        $this->assertSame('Invitación enviada', $res->body['message']);
        $this->assertSame([100, 7, 5], $this->db->paramsFor('INSERT INTO event_collaborations'));
    }

    public function testLaNotificacionVaAlDuenoDeLaPaginaInvitada()
    {
        $this->invitacionValida();

        CollaborationsHandler::index($this->db, $this->post([
            'link_id' => 100, 'collaborator_page_id' => 7,
        ], $this->user(9)));

        $params = $this->db->paramsFor('INSERT INTO notifications');

        $this->assertSame(11, $params[0], 'va al dueño de la página invitada');
        $this->assertStringContainsString('Mi página', $params[2]);
        $this->assertStringContainsString('Mi evento', $params[2]);
        $this->assertSame(7, $params[3], 'page_id apunta a la página del invitado');
        $this->assertSame(100, $params[4]);
    }

    // ================================================================== detail

    public function testDetailExigeId()
    {
        $res = CollaborationsHandler::detail($this->db, $this->put([], $this->user()));

        $this->assertError(400, $res, 'Collaboration ID required');
    }

    public function testDetailValidaElIdAntesQueLaSesion()
    {
        $this->assertError(400, CollaborationsHandler::detail($this->db, $this->put()), 'Collaboration ID required');
    }

    public function testDetailExigeSesion()
    {
        $res = CollaborationsHandler::detail($this->db, $this->put([], null, ['id' => '3']));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testDetailRechazaMetodoNoSoportado()
    {
        $res = CollaborationsHandler::detail($this->db, $this->get(['id' => '3'], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    // -------------------------------------------------------------- responder

    /**
     * @dataProvider estadosInvalidos
     */
    public function testResponderExigeEstadoValido($status)
    {
        $res = CollaborationsHandler::detail($this->db, $this->put(
            $status === null ? [] : ['status' => $status],
            $this->user(),
            ['id' => '3']
        ));

        $this->assertError(400, $res, 'Estado inválido');
        $this->assertNoWrites();
    }

    public function estadosInvalidos()
    {
        return [
            'ausente' => [null],
            'inventado' => ['quizas'],
            'pending' => ['pending'],
        ];
    }

    public function testAceptarExigeGroupId()
    {
        $res = CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'],
            $this->user(),
            ['id' => '3']
        ));

        $this->assertError(400, $res, 'group_id es requerido al aceptar');
        $this->assertNoWrites();
    }

    public function testRechazarNoExigeGroupId()
    {
        $this->colaboracionPendienteDe(11);

        $res = CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'rejected'],
            $this->user(11),
            ['id' => '3']
        ));

        $this->assertStatus(200, $res);
    }

    public function testSoloElInvitadoPuedeResponder()
    {
        $this->colaboracionPendienteDe(11);

        $res = CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'rejected'],
            $this->user(99),
            ['id' => '3']
        ));

        $this->assertError(403, $res, 'Forbidden');
        $this->assertNoWrites();
    }

    public function testResponderRechazaColaboracionInexistente()
    {
        $res = CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'rejected'], $this->user(11), ['id' => '3']
        ));

        $this->assertError(403, $res, 'Forbidden');
    }

    public function testResponderRechazaColaboracionYaProcesada()
    {
        $this->colaboracionPendienteDe(11, 'accepted');

        $res = CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'rejected'], $this->user(11), ['id' => '3']
        ));

        $this->assertError(400, $res, 'Esta colaboración ya fue procesada');
    }

    public function testAceptarRechazaGrupoQueNoEsDelInvitado()
    {
        $this->colaboracionPendienteDe(11);
        // Sin regla para la validación del grupo: no matchea.

        $res = CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'accepted', 'group_id' => 50],
            $this->user(11),
            ['id' => '3']
        ));

        $this->assertError(400, $res, 'Grupo inválido: debe ser un grupo de eventos de tu página');
        $this->assertNoWrites();
    }

    public function testAceptarGuardaElGrupoElegido()
    {
        $this->colaboracionPendienteDe(11);
        $this->db->onSelect('SELECT lg.id FROM link_groups lg', [['id' => 50]]);

        $res = CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'accepted', 'group_id' => 50],
            $this->user(11),
            ['id' => '3']
        ));

        $this->assertStatus(200, $res);
        $this->assertSame('Colaboración aceptada', $res->body['message']);
        $this->assertSame(['accepted', 50, 3], $this->db->paramsFor('UPDATE event_collaborations SET status'));
    }

    public function testRechazarDejaElGrupoEnNull()
    {
        $this->colaboracionPendienteDe(11);

        CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'rejected'],
            $this->user(11),
            ['id' => '3']
        ));

        $this->assertSame(['rejected', null, 3], $this->db->paramsFor('UPDATE event_collaborations SET status'));
    }

    public function testResponderNotificaAlDuenoDelEvento()
    {
        $this->colaboracionPendienteDe(11);
        $this->db->onSelect('SELECT lg.id FROM link_groups lg', [['id' => 50]]);

        CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'accepted', 'group_id' => 50],
            $this->user(11),
            ['id' => '3']
        ));

        $params = $this->db->paramsFor('INSERT INTO notifications');

        $this->assertSame(9, $params[0], 'va al dueño del evento');
        $this->assertSame('Colaboración aceptada', $params[1]);
        $this->assertStringContainsString('Página Invitada', $params[2]);
        $this->assertStringContainsString('Mi evento', $params[2]);
    }

    public function testResponderMarcaLaInvitacionComoLeida()
    {
        $this->colaboracionPendienteDe(11);

        CollaborationsHandler::detail($this->db, $this->put(
            ['status' => 'rejected'],
            $this->user(11),
            ['id' => '3']
        ));

        $this->assertSame([3, 11], $this->db->paramsFor('UPDATE notifications SET is_read = 1'));
    }

    // ---------------------------------------------------------------- borrar

    public function testEliminarRechazaColaboracionInexistente()
    {
        $res = CollaborationsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user()));

        $this->assertError(404, $res, 'Colaboración no encontrada');
    }

    public function testElSolicitantePuedeEliminar()
    {
        $this->colaboracionEntre(9, 11);

        $res = CollaborationsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertSame('Colaboración eliminada', $res->body['message']);
        $this->assertSame([3], $this->db->paramsFor('DELETE FROM event_collaborations'));
    }

    public function testElColaboradorPuedeEliminar()
    {
        $this->colaboracionEntre(9, 11);

        $res = CollaborationsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user(11)));

        $this->assertStatus(200, $res);
    }

    public function testUnTerceroNoPuedeEliminar()
    {
        $this->colaboracionEntre(9, 11);

        $res = CollaborationsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user(99)));

        $this->assertError(403, $res, 'Forbidden');
        $this->assertSame(0, $this->db->countCalls('DELETE FROM event_collaborations'));
    }

    // ------------------------------------------------------------- ayudantes

    private function esDuenoDelEvento()
    {
        $this->db->onSelect('SELECT l.id FROM links l', [['id' => 100]]);
    }

    private function eventoPropio()
    {
        $this->db->onSelect('SELECT l.id, l.text as event_title', [[
            'id' => 100,
            'event_title' => 'Mi evento',
            'page_id' => 5,
            'page_title' => 'Mi página',
        ]]);
    }

    private function invitacionValida()
    {
        $this->eventoPropio();
        $this->db->onSelect('SELECT id, user_id FROM pages WHERE id = ?', [['id' => 7, 'user_id' => 11]]);
        $this->db->onInsert('INSERT INTO event_collaborations', 40);
    }

    private function colaboracionPendienteDe($collaboratorOwnerId, $status = 'pending')
    {
        $this->db->onSelect('SELECT ec.*, ec.requester_page_id', [[
            'id' => 3,
            'link_id' => 100,
            'status' => $status,
            'requester_page_id' => 5,
            'collaborator_owner_id' => $collaboratorOwnerId,
            'collaborator_page_title' => 'Página Invitada',
            'requester_owner_id' => 9,
            'requester_page_title' => 'Mi página',
            'event_title' => 'Mi evento',
        ]]);
    }

    private function colaboracionEntre($requesterOwnerId, $collaboratorOwnerId)
    {
        $this->db->onSelect('SELECT ec.*, rp.user_id as requester_owner_id', [[
            'id' => 3,
            'requester_owner_id' => $requesterOwnerId,
            'collaborator_owner_id' => $collaboratorOwnerId,
        ]]);
    }
}
