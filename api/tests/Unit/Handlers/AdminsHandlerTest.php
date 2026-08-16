<?php

namespace Tests\Unit\Handlers;

use AdminsHandler;
use Request;
use Tests\Support\HandlerTestCase;

class AdminsHandlerTest extends HandlerTestCase
{
    // =================================================================== index

    public function testIndexExigeSesion()
    {
        $this->assertError(401, AdminsHandler::index($this->db, $this->get()), 'Unauthorized');
    }

    public function testIndexRechazaMetodoNoSoportado()
    {
        $res = AdminsHandler::index($this->db, $this->put([], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    // ------------------------------------------------- mis invitaciones

    public function testListaMisInvitacionesPendientes()
    {
        $this->db->onSelect('FROM page_admins pa JOIN pages p', [
            ['id' => 1, 'page_title' => 'Una página', 'status' => 'pending'],
        ]);

        $res = AdminsHandler::index($this->db, $this->get(['type' => 'pending'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['invitations']);
        $this->assertSame([9], $this->db->paramsFor('FROM page_admins pa JOIN pages p'));
    }

    public function testMisInvitacionesSoloTraeLasPendientes()
    {
        AdminsHandler::index($this->db, $this->get(['type' => 'pending'], $this->user()));

        $sql = $this->db->callsFor('FROM page_admins pa JOIN pages p')[0]['sql'];

        $this->assertStringContainsString('pa.status = "pending"', $sql);
    }

    // ------------------------------------------- administradores de una página

    public function testListarAdminsExigePageId()
    {
        $res = AdminsHandler::index($this->db, $this->get([], $this->user()));

        $this->assertError(400, $res, 'page_id requerido');
    }

    public function testListarAdminsRechazaAQuienNoGestionaLaPagina()
    {
        $res = AdminsHandler::index($this->db, $this->get(['page_id' => '5'], $this->user()));

        $this->assertError(403, $res, 'Forbidden');
    }

    public function testListarAdminsDevuelveLaLista()
    {
        $this->autorizarPagina();
        $this->db->onSelect('FROM page_admins pa JOIN users u', [
            ['id' => 1, 'user_email' => 'a@b.com', 'status' => 'accepted'],
        ]);

        $res = AdminsHandler::index($this->db, $this->get(['page_id' => '5'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['admins']);
        $this->assertSame([5], $this->db->paramsFor('FROM page_admins pa JOIN users u'));
    }

    // ------------------------------------------------------------- invitar

    public function testInvitarExigePageIdYEmail()
    {
        $res = AdminsHandler::index($this->db, $this->post(['page_id' => 5], $this->user()));

        $this->assertError(400, $res, 'page_id y email son requeridos');
        $this->assertNoWrites();
    }

    public function testInvitarRechazaEmailEnBlanco()
    {
        $res = AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => '   ',
        ], $this->user()));

        $this->assertError(400, $res, 'page_id y email son requeridos');
    }

    public function testSoloElDuenoPuedeInvitar()
    {
        // Sin regla para isOwner: el usuario no es dueño (podría ser admin).
        $res = AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => 'otro@b.com',
        ], $this->user()));

        $this->assertError(403, $res, 'Solo el dueño de la página puede invitar administradores');
        $this->assertNoWrites();
    }

    public function testInvitarRechazaEmailNoRegistrado()
    {
        $this->autorizarDueno();

        $res = AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => 'nadie@b.com',
        ], $this->user()));

        $this->assertError(404, $res, 'No hay ningún usuario registrado con ese email');
        $this->assertNoWrites();
    }

    public function testInvitarRechazaAutoinvitacion()
    {
        $this->autorizarDueno();
        $this->db->onSelect('SELECT id, name, email FROM users WHERE email = ?', [
            ['id' => 9, 'name' => 'Ana', 'email' => 'yo@b.com'],
        ]);

        $res = AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => 'yo@b.com',
        ], $this->user(9)));

        $this->assertError(400, $res, 'No podés invitarte a vos mismo');
        $this->assertNoWrites();
    }

    public function testInvitarNormalizaElEmail()
    {
        $this->autorizarDueno();

        AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => '  OTRO@B.COM  ',
        ], $this->user(9)));

        $this->assertSame(['otro@b.com'], $this->db->paramsFor('SELECT id, name, email FROM users WHERE email = ?'));
    }

    public function testInvitarRechazaSiYaEsAdministrador()
    {
        $this->autorizarDueno();
        $this->invitadoExiste();
        $this->db->onSelect('SELECT id, status FROM page_admins', [['id' => 3, 'status' => 'accepted']]);

        $res = AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => 'otro@b.com',
        ], $this->user(9)));

        $this->assertError(409, $res, 'Ese usuario ya es administrador de esta página');
    }

    public function testInvitarRechazaSiYaHayInvitacionPendiente()
    {
        $this->autorizarDueno();
        $this->invitadoExiste();
        $this->db->onSelect('SELECT id, status FROM page_admins', [['id' => 3, 'status' => 'pending']]);

        $res = AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => 'otro@b.com',
        ], $this->user(9)));

        $this->assertError(409, $res, 'Ya hay una invitación pendiente para ese usuario');
    }

    public function testInvitarCreaLaInvitacionYNotifica()
    {
        $this->autorizarDueno();
        $this->invitadoExiste();
        $this->db->onSelect('SELECT title FROM pages WHERE id = ?', [['title' => 'Mi página']]);
        $this->db->onInsert('INSERT INTO page_admins', 30);

        $res = AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => 'otro@b.com',
        ], $this->user(9)));

        $this->assertStatus(201, $res);
        $this->assertSame('Invitación enviada', $res->body['message']);
        $this->assertSame('pending', $res->body['admin']['status']);
        $this->assertSame([5, 11, 9], $this->db->paramsFor('INSERT INTO page_admins'));
    }

    public function testLaNotificacionDeInvitacionVaAlInvitado()
    {
        $this->autorizarDueno();
        $this->invitadoExiste();
        $this->db->onSelect('SELECT title FROM pages WHERE id = ?', [['title' => 'Mi página']]);
        $this->db->onInsert('INSERT INTO page_admins', 30);

        AdminsHandler::index($this->db, $this->post([
            'page_id' => 5, 'email' => 'otro@b.com',
        ], $this->user(9)));

        $params = $this->db->paramsFor('INSERT INTO notifications');

        $this->assertSame(11, $params[0], 'la notificación es para el invitado, no para el dueño');
        $this->assertStringContainsString('Mi página', $params[2]);
    }

    // ================================================================== detail

    public function testDetailExigeSesion()
    {
        $this->assertError(401, AdminsHandler::detail($this->db, $this->put()), 'Unauthorized');
    }

    public function testDetailRechazaMetodoNoSoportado()
    {
        $res = AdminsHandler::detail($this->db, $this->get([], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    // -------------------------------------------------- responder invitación

    public function testResponderExigeId()
    {
        $res = AdminsHandler::detail($this->db, $this->put(['status' => 'accepted'], $this->user()));

        $this->assertError(400, $res, 'id requerido');
    }

    /**
     * @dataProvider estadosInvalidos
     */
    public function testResponderExigeStatusValido($status)
    {
        $res = AdminsHandler::detail($this->db, $this->put(
            $status === null ? [] : ['status' => $status],
            $this->user(),
            ['id' => '3']
        ));

        $this->assertError(400, $res, 'status inválido');
        $this->assertNoWrites();
    }

    public function estadosInvalidos()
    {
        return [
            'ausente' => [null],
            'inventado' => ['maybe'],
            'pending' => ['pending'],
            'vacío' => [''],
        ];
    }

    public function testResponderRechazaInvitacionInexistente()
    {
        $res = AdminsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'], $this->user(), ['id' => '3']
        ));

        $this->assertError(403, $res, 'Forbidden');
    }

    public function testSoloElInvitadoPuedeResponderSuInvitacion()
    {
        $this->db->onSelect('SELECT pa.id, pa.page_id, pa.user_id', [[
            'id' => 3, 'page_id' => 5, 'user_id' => 77, 'status' => 'pending',
            'page_title' => 'Mi página', 'owner_id' => 9,
        ]]);

        $res = AdminsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'], $this->user(9), ['id' => '3']
        ));

        $this->assertError(403, $res, 'Forbidden');
        $this->assertNoWrites();
    }

    public function testResponderRechazaInvitacionYaProcesada()
    {
        $this->db->onSelect('SELECT pa.id, pa.page_id, pa.user_id', [[
            'id' => 3, 'page_id' => 5, 'user_id' => 11, 'status' => 'accepted',
            'page_title' => 'Mi página', 'owner_id' => 9,
        ]]);

        $res = AdminsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'], $this->user(11), ['id' => '3']
        ));

        $this->assertError(400, $res, 'Esta invitación ya fue procesada');
    }

    public function testAceptarMarcaLaInvitacionComoAceptada()
    {
        $this->invitacionPendienteDe(11);

        $res = AdminsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'], $this->user(11), ['id' => '3']
        ));

        $this->assertStatus(200, $res);
        $this->assertSame('Invitación aceptada', $res->body['message']);
        $this->assertTrue($this->db->ran('UPDATE page_admins SET status = "accepted"'));
        $this->assertSame(0, $this->db->countCalls('DELETE FROM page_admins'));
    }

    public function testRechazarBorraLaInvitacion()
    {
        $this->invitacionPendienteDe(11);

        $res = AdminsHandler::detail($this->db, $this->put(
            ['status' => 'rejected'], $this->user(11), ['id' => '3']
        ));

        $this->assertStatus(200, $res);
        $this->assertSame('Invitación rechazada', $res->body['message']);
        $this->assertSame([3], $this->db->paramsFor('DELETE FROM page_admins WHERE id = ?'));
        $this->assertSame(0, $this->db->countCalls('UPDATE page_admins SET status'));
    }

    public function testResponderNotificaAlDueno()
    {
        $this->invitacionPendienteDe(11, 'Ana');

        AdminsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'], $this->user(11), ['id' => '3']
        ));

        $params = $this->db->paramsFor('INSERT INTO notifications');

        $this->assertSame(9, $params[0], 'la notificación va al dueño');
        $this->assertSame('Invitación aceptada', $params[1]);
        $this->assertStringContainsString('Ana', $params[2]);
        $this->assertStringContainsString('Mi página', $params[2]);
    }

    public function testUsaElEmailSiElUsuarioNoTieneNombre()
    {
        $this->invitacionPendienteDe(11, null);

        AdminsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'], $this->user(11), ['id' => '3']
        ));

        $this->assertStringContainsString('ana@b.com', $this->db->paramsFor('INSERT INTO notifications')[2]);
    }

    public function testResponderMarcaLaNotificacionDeInvitacionComoLeida()
    {
        $this->invitacionPendienteDe(11);

        AdminsHandler::detail($this->db, $this->put(
            ['status' => 'accepted'], $this->user(11), ['id' => '3']
        ));

        $this->assertSame([11, 5], $this->db->paramsFor('UPDATE notifications SET is_read = 1'));
    }

    // -------------------------------------------------------------- remover

    public function testRemoverExigeIdOPageId()
    {
        $res = AdminsHandler::detail($this->db, $this->delete([], $this->user()));

        $this->assertError(400, $res, 'id o page_id requerido');
    }

    public function testRemoverPorIdRechazaInexistente()
    {
        $res = AdminsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user()));

        $this->assertError(404, $res, 'No encontrado');
    }

    public function testElDuenoPuedeRemoverACualquierAdministrador()
    {
        $this->db->onSelect('SELECT pa.id, pa.user_id, p.user_id AS owner_id', [[
            'id' => 3, 'user_id' => 11, 'owner_id' => 9,
        ]]);

        $res = AdminsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertSame('Administrador removido', $res->body['message']);
        $this->assertSame([3], $this->db->paramsFor('DELETE FROM page_admins WHERE id = ?'));
    }

    public function testUnAdministradorPuedeQuitarseASiMismo()
    {
        $this->db->onSelect('SELECT pa.id, pa.user_id, p.user_id AS owner_id', [[
            'id' => 3, 'user_id' => 11, 'owner_id' => 9,
        ]]);

        $res = AdminsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user(11)));

        $this->assertStatus(200, $res);
    }

    public function testUnTerceroNoPuedeRemoverAdministradores()
    {
        $this->db->onSelect('SELECT pa.id, pa.user_id, p.user_id AS owner_id', [[
            'id' => 3, 'user_id' => 11, 'owner_id' => 9,
        ]]);

        $res = AdminsHandler::detail($this->db, $this->delete(['id' => '3'], $this->user(99)));

        $this->assertError(403, $res, 'Forbidden');
        $this->assertSame(0, $this->db->countCalls('DELETE FROM page_admins'));
    }

    public function testAutoSalidaPorPageId()
    {
        $this->db->onWrite('DELETE FROM page_admins WHERE page_id = ?', 1);

        $res = AdminsHandler::detail($this->db, $this->delete(['page_id' => '5'], $this->user(11)));

        $this->assertStatus(200, $res);
        $this->assertSame('Dejaste de administrar la página', $res->body['message']);
        $this->assertSame([5, 11], $this->db->paramsFor('DELETE FROM page_admins WHERE page_id = ?'));
    }

    public function testAutoSalidaAvisaSiNoEraAdministrador()
    {
        $this->db->onWrite('DELETE FROM page_admins WHERE page_id = ?', 0);

        $res = AdminsHandler::detail($this->db, $this->delete(['page_id' => '5'], $this->user(11)));

        $this->assertError(404, $res, 'No sos administrador de esa página');
    }

    // ------------------------------------------------------------- ayudantes

    private function autorizarPagina()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
    }

    private function autorizarDueno()
    {
        $this->db->onSelect('FROM pages WHERE id = ? AND user_id = ?', [['1' => 1]]);
    }

    private function invitadoExiste()
    {
        $this->db->onSelect('SELECT id, name, email FROM users WHERE email = ?', [
            ['id' => 11, 'name' => 'Otro', 'email' => 'otro@b.com'],
        ]);
    }

    private function invitacionPendienteDe($userId, $nombre = 'Ana')
    {
        $this->db->onSelect('SELECT pa.id, pa.page_id, pa.user_id', [[
            'id' => 3, 'page_id' => 5, 'user_id' => $userId, 'status' => 'pending',
            'page_title' => 'Mi página', 'owner_id' => 9,
        ]]);
        $this->db->onSelect('SELECT name, email FROM users WHERE id = ?', [[
            'name' => $nombre, 'email' => 'ana@b.com',
        ]]);
    }
}
