<?php

namespace Tests\Integration;

use CollaborationsHandler;
use Tests\Support\IntegracionTestCase;

/**
 * "Aceptar siempre" las invitaciones a colaborar de una página, contra una
 * base de verdad: que la regla se guarde, que las invitaciones siguientes se
 * acepten solas con el mismo efecto que a mano, que sea de página a página y
 * que sólo la página invitada la pueda sacar.
 */
class ColaboracionesAutomaticasTest extends IntegracionTestCase
{
    private $duena;
    private $sala;
    private $agendaSala;
    private $socia;
    private $paginaSocia;
    private $agendaSocia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duena = $this->usuario();
        $this->sala = $this->pagina($this->duena);
        $this->agendaSala = $this->grupo($this->sala);

        $this->socia = $this->usuario('socia@test.local', 'Socia');
        $this->paginaSocia = $this->pagina($this->socia, 'la-socia', 'La Socia');
        $this->agendaSocia = $this->grupo($this->paginaSocia, 'eventos', 'Agenda de la Socia');
    }

    private function cuenta($tabla, $where = '1', array $params = [])
    {
        return (int) $this->valor("SELECT COUNT(*) FROM `$tabla` WHERE $where", $params);
    }

    private function invitar($linkId, $paginaInvitada = null, $quien = null)
    {
        return CollaborationsHandler::index($this->db, $this->post([
            'link_id' => $linkId,
            'collaborator_page_id' => $paginaInvitada ?: $this->paginaSocia,
        ], $this->user($quien ?: $this->duena)));
    }

    private function aceptar($collabId, $siempre, $quien = null, $grupo = null)
    {
        return CollaborationsHandler::detail($this->db, $this->put([
            'status' => 'accepted',
            'group_id' => $grupo ?: $this->agendaSocia,
            'aceptar_siempre' => $siempre,
        ], $this->user($quien ?: $this->socia, 'socia@test.local'), ['id' => $collabId]));
    }

    /** La primera se acepta a mano marcando "siempre"; devuelve el id de la regla. */
    private function aceptarSiempreALaSala()
    {
        $primera = $this->invitar($this->evento($this->agendaSala, ['text' => 'Primera']));
        $this->assertStatus(200, $this->aceptar($primera->body['collaboration_id'], true));

        return (int) $this->valor('SELECT id FROM colaboraciones_automaticas');
    }

    public function testAceptarSiempreGuardaLaReglaDeLaPaginaInvitada()
    {
        $this->aceptarSiempreALaSala();

        $regla = $this->fila('SELECT * FROM colaboraciones_automaticas');
        $this->assertEquals($this->paginaSocia, $regla['collaborator_page_id']);
        $this->assertEquals($this->sala, $regla['requester_page_id']);
        $this->assertEquals($this->agendaSocia, $regla['group_id']);
        $this->assertEquals($this->socia, $regla['created_by']);
    }

    public function testLasSiguientesSeAceptanSolasConElMismoEfecto()
    {
        $this->aceptarSiempreALaSala();
        $linkId = $this->evento($this->agendaSala, ['text' => 'Segunda']);

        $res = $this->invitar($linkId);

        $this->assertStatus(201, $res);
        $this->assertSame('accepted', $res->body['status']);
        $this->assertTrue($res->body['aceptada_automaticamente']);
        $collabId = $res->body['collaboration_id'];

        $collab = $this->fila('SELECT * FROM event_collaborations WHERE id = ?', [$collabId]);
        $this->assertSame('accepted', $collab['status']);
        $this->assertEquals($this->agendaSocia, $collab['collaborator_group_id']);

        // Comparte los clientes del evento, como la aceptación manual.
        $recordId = $this->valor('SELECT id FROM event_records WHERE link_id = ?', [$linkId]);
        $this->assertSame(1, $this->cuenta('event_record_pages', 'record_id = ? AND page_id = ? AND rol = ?', [$recordId, $this->paginaSocia, 'colaborador']));

        // La sala recibe la misma respuesta; la socia, el aviso de que se aceptó sola.
        $this->assertSame(1, $this->cuenta('notifications', 'user_id = ? AND link_id = ? AND type = ?', [$this->duena, $linkId, 'collaboration_response']));
        $this->assertSame(1, $this->cuenta('notifications', 'user_id = ? AND collaboration_id = ? AND type = ?', [$this->socia, $collabId, 'collaboration_auto_accepted']));
        $this->assertSame(0, $this->cuenta('notifications', 'collaboration_id = ? AND type = ?', [$collabId, 'collaboration_request']));

        // Y ya no queda nada pendiente para responder.
        $pendientes = CollaborationsHandler::index($this->db, $this->get(['type' => 'pending'], $this->user($this->socia)));
        $this->assertCount(0, $pendientes->body['pending']);
    }

    /** La regla es de página a página: otra página de la misma dueña no entra. */
    public function testOtraPaginaDeLaMismaPersonaNoSeAceptaSola()
    {
        $this->aceptarSiempreALaSala();
        $otraPagina = $this->pagina($this->duena, 'otra-sala', 'Otra Sala');
        $linkId = $this->evento($this->grupo($otraPagina));

        $res = $this->invitar($linkId);

        $this->assertStatus(201, $res);
        $this->assertSame('pending', $res->body['status']);
        $this->assertSame('pending', $this->valor('SELECT status FROM event_collaborations WHERE link_id = ?', [$linkId]));
    }

    /** Ni otra página de la socia: la regla es sólo de la página que aceptó. */
    public function testOtraPaginaDeLaInvitadaNoSeAceptaSola()
    {
        $this->aceptarSiempreALaSala();
        $otraDeLaSocia = $this->pagina($this->socia, 'socia-2', 'Socia 2');
        $this->grupo($otraDeLaSocia);

        $res = $this->invitar($this->evento($this->agendaSala), $otraDeLaSocia);

        $this->assertSame('pending', $res->body['status']);
    }

    public function testSinMarcarSiempreLaSiguienteQuedaPendiente()
    {
        $primera = $this->invitar($this->evento($this->agendaSala));
        $this->assertStatus(200, $this->aceptar($primera->body['collaboration_id'], false));

        $res = $this->invitar($this->evento($this->agendaSala, ['text' => 'Segunda']));

        $this->assertSame('pending', $res->body['status']);
        $this->assertSame(0, $this->cuenta('colaboraciones_automaticas'));
    }

    public function testDejarDeAceptarNoRevocaLasYaAceptadas()
    {
        $reglaId = $this->aceptarSiempreALaSala();
        $this->invitar($this->evento($this->agendaSala, ['text' => 'Segunda']));
        $this->assertSame(2, $this->cuenta('event_collaborations', 'status = ?', ['accepted']));

        $lista = CollaborationsHandler::automaticas($this->db, $this->get(['page_id' => $this->paginaSocia], $this->user($this->socia)));
        $this->assertStatus(200, $lista);
        $this->assertCount(1, $lista->body['automaticas']);
        $this->assertSame('La Sala', $lista->body['automaticas'][0]['requester_page_title']);
        $this->assertSame('Agenda de la Socia', $lista->body['automaticas'][0]['group_title']);

        $res = CollaborationsHandler::automaticas($this->db, $this->delete(['id' => $reglaId], $this->user($this->socia)));

        $this->assertStatus(200, $res);
        $this->assertSame(0, $this->cuenta('colaboraciones_automaticas'));
        $this->assertSame(2, $this->cuenta('event_collaborations', 'status = ?', ['accepted']), 'las ya aceptadas siguen');

        $nueva = $this->invitar($this->evento($this->agendaSala, ['text' => 'Tercera']));
        $this->assertSame('pending', $nueva->body['status']);
    }

    public function testQuienInvitaNoVeNiBorraLaRegla()
    {
        $reglaId = $this->aceptarSiempreALaSala();

        $lista = CollaborationsHandler::automaticas($this->db, $this->get(['page_id' => $this->paginaSocia], $this->user($this->duena)));
        $borra = CollaborationsHandler::automaticas($this->db, $this->delete(['id' => $reglaId], $this->user($this->duena)));

        $this->assertStatus(403, $lista);
        $this->assertStatus(403, $borra);
        $this->assertSame(1, $this->cuenta('colaboraciones_automaticas'));
    }

    /** Un administrador aceptado de la página invitada puede manejarla, como puede aceptar. */
    public function testUnAdministradorDeLaPaginaInvitadaPuedeManejarLaRegla()
    {
        $admin = $this->usuario('admin@test.local', 'Admin');
        $this->insertar('page_admins', [
            'page_id' => $this->paginaSocia, 'user_id' => $admin, 'invited_by' => $this->socia, 'status' => 'accepted',
        ]);
        $primera = $this->invitar($this->evento($this->agendaSala));

        $this->assertStatus(200, $this->aceptar($primera->body['collaboration_id'], true, $admin));
        $reglaId = (int) $this->valor('SELECT id FROM colaboraciones_automaticas');
        $this->assertStatus(200, CollaborationsHandler::automaticas($this->db, $this->delete(['id' => $reglaId], $this->user($admin))));
    }

    /** Volver a marcar "siempre" con la misma página sólo cambia el grupo. */
    public function testMarcarSiempreOtraVezCambiaElGrupo()
    {
        // Dos invitaciones pendientes a la vez, aceptadas las dos con "siempre".
        $otraAgenda = $this->grupo($this->paginaSocia, 'eventos', 'Otra agenda');
        $primera = $this->invitar($this->evento($this->agendaSala, ['text' => 'Primera']));
        $segunda = $this->invitar($this->evento($this->agendaSala, ['text' => 'Segunda']));

        $this->assertStatus(200, $this->aceptar($primera->body['collaboration_id'], true, null, $otraAgenda));
        $this->assertStatus(200, $this->aceptar($segunda->body['collaboration_id'], true, null, $this->agendaSocia));

        $this->assertSame(1, $this->cuenta('colaboraciones_automaticas'));
        $this->assertEquals($this->agendaSocia, $this->valor('SELECT group_id FROM colaboraciones_automaticas'));
    }

    /** Si se borra el grupo elegido, la regla se va con él y se vuelve a preguntar. */
    public function testSiSeBorraElGrupoVuelveAQuedarPendiente()
    {
        $this->aceptarSiempreALaSala();
        $this->db->prepare('UPDATE event_collaborations SET collaborator_group_id = NULL')->execute();
        $this->db->prepare('DELETE FROM link_groups WHERE id = ?')->execute([$this->agendaSocia]);

        $res = $this->invitar($this->evento($this->agendaSala, ['text' => 'Segunda']));

        $this->assertSame(0, $this->cuenta('colaboraciones_automaticas'));
        $this->assertSame('pending', $res->body['status']);
    }
}
