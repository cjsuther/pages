<?php

namespace Tests\Unit\Handlers;

use GroupsHandler;
use Tests\Support\HandlerTestCase;

class GroupsHandlerTest extends HandlerTestCase
{
    // =================================================================== index

    public function testIndexRechazaMetodosDistintosDePost()
    {
        $this->assertError(405, GroupsHandler::index($this->db, $this->get()), 'Method not allowed');
    }

    public function testIndexExigeSesion()
    {
        $res = GroupsHandler::index($this->db, $this->post(['page_id' => 1, 'title' => 'T']));

        $this->assertError(401, $res, 'Unauthorized');
        $this->assertNoWrites();
    }

    /**
     * @dataProvider cuerposIncompletos
     */
    public function testIndexExigePageIdYTitulo($cuerpo)
    {
        $res = GroupsHandler::index($this->db, $this->post($cuerpo, $this->user()));

        $this->assertError(400, $res, 'Page ID and title are required');
        $this->assertNoWrites();
    }

    public function cuerposIncompletos()
    {
        return [
            'vacío' => [[]],
            'sin page_id' => [['title' => 'T']],
            'sin title' => [['page_id' => 1]],
            'title en null' => [['page_id' => 1, 'title' => null]],
        ];
    }

    public function testIndexRechazaPaginaAjena()
    {
        $res = GroupsHandler::index($this->db, $this->post(
            ['page_id' => 99, 'title' => 'T'],
            $this->user()
        ));

        $this->assertError(404, $res, 'Page not found');
        $this->assertNoWrites();
    }

    public function testIndexCreaElGrupoYDevuelve201()
    {
        $this->autorizarPagina();
        $this->db->onInsert('INSERT INTO link_groups', 12);
        $this->db->onSelect('SELECT * FROM link_groups WHERE id = ?', [['id' => 12, 'title' => 'Mis links']]);

        $res = GroupsHandler::index($this->db, $this->post(
            ['page_id' => 3, 'title' => 'Mis links'],
            $this->user()
        ));

        $this->assertStatus(201, $res);
        $this->assertSame(['group' => ['id' => 12, 'title' => 'Mis links']], $res->body);
    }

    public function testIndexUsaTipoLinksYPosicionCeroPorDefecto()
    {
        $this->autorizarPagina();
        $this->db->onInsert('INSERT INTO link_groups', 12);

        GroupsHandler::index($this->db, $this->post(
            ['page_id' => 3, 'title' => 'Mis links'],
            $this->user()
        ));

        $this->assertSame([3, 'Mis links', 'links', 0], $this->db->paramsFor('INSERT INTO link_groups'));
    }

    public function testIndexRespetaElTipoYLaPosicionEnviados()
    {
        $this->autorizarPagina();
        $this->db->onInsert('INSERT INTO link_groups', 12);

        GroupsHandler::index($this->db, $this->post(
            ['page_id' => 3, 'title' => 'Agenda', 'type' => 'eventos', 'position' => 4],
            $this->user()
        ));

        $this->assertSame([3, 'Agenda', 'eventos', 4], $this->db->paramsFor('INSERT INTO link_groups'));
    }

    public function testIndexNoPrecargaLinksEnGruposNormales()
    {
        $this->autorizarPagina();
        $this->db->onInsert('INSERT INTO link_groups', 12);

        GroupsHandler::index($this->db, $this->post(
            ['page_id' => 3, 'title' => 'Mis links'],
            $this->user()
        ));

        $this->assertSame(0, $this->db->countCalls('INSERT INTO links'));
    }

    public function testIndexPrecargaLasRedesSocialesEnGruposDeTipoRedes()
    {
        $this->autorizarPagina();
        $this->db->onInsert('INSERT INTO link_groups', 20);

        GroupsHandler::index($this->db, $this->post(
            ['page_id' => 3, 'title' => 'Redes', 'type' => 'redes'],
            $this->user()
        ));

        $esperadas = GroupsHandler::redesPorDefecto();
        $llamadas = $this->db->callsFor('INSERT INTO links');

        $this->assertCount(count($esperadas), $llamadas);

        foreach ($esperadas as $i => $red) {
            $this->assertSame(
                // lastInsertId() devuelve string, igual que en PDO real.
                ['20', $red[1], $red[0], $red[2], $i],
                $llamadas[$i]['params'],
                'La red en la posición ' . $i . ' no se precargó como corresponde'
            );
        }
    }

    public function testLasRedesPrecargadasTienenLogoYUrl()
    {
        foreach (GroupsHandler::redesPorDefecto() as $red) {
            list($nombre, $url, $logo) = $red;

            $this->assertNotEmpty($nombre);
            $this->assertStringStartsWith('https://', $url);
            $this->assertStringEndsWith('.svg', $logo);
        }
    }

    public function testIndexDevuelve500SiLaBaseFalla()
    {
        $this->autorizarPagina();
        $this->db->failOn('INSERT INTO link_groups', 'sin espacio');

        $res = GroupsHandler::index($this->db, $this->post(
            ['page_id' => 3, 'title' => 'T'],
            $this->user()
        ));

        $this->assertError(500, $res, 'Server error: sin espacio');
    }

    // ================================================================== detail

    public function testDetailExigeId()
    {
        $this->assertError(400, GroupsHandler::detail($this->db, $this->put([], $this->user())), 'Group ID is required');
    }

    public function testDetailValidaElIdAntesQueLaSesion()
    {
        $this->assertError(400, GroupsHandler::detail($this->db, $this->put([], null)), 'Group ID is required');
    }

    public function testDetailExigeSesion()
    {
        $res = GroupsHandler::detail($this->db, $this->put([], null, ['id' => '5']));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testDetailRechazaMetodoNoSoportado()
    {
        $res = GroupsHandler::detail($this->db, $this->get(['id' => '5'], $this->user()));

        $this->assertError(405, $res, 'Method not allowed');
    }

    // ------------------------------------------------------------------- PUT

    public function testUpdateRechazaGrupoAjeno()
    {
        $res = GroupsHandler::detail($this->db, $this->put(['title' => 'X'], $this->user(), ['id' => '5']));

        $this->assertError(404, $res, 'Group not found');
        $this->assertNoWrites();
    }

    public function testUpdateActualizaSoloLosCamposEnviados()
    {
        $this->autorizarGrupo();
        $this->db->onWrite('UPDATE link_groups SET', 1);
        $this->db->onSelect('SELECT * FROM link_groups WHERE id = ?', [['id' => 5, 'title' => 'Nuevo']]);

        $res = GroupsHandler::detail($this->db, $this->put(['title' => 'Nuevo'], $this->user(), ['id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertSame(['group' => ['id' => 5, 'title' => 'Nuevo']], $res->body);

        $sql = $this->db->callsFor('UPDATE link_groups SET')[0]['sql'];
        $this->assertStringContainsString('title = ?', $sql);
        $this->assertStringNotContainsString('type = ?', $sql);
        $this->assertSame(['Nuevo', 5], $this->db->paramsFor('UPDATE link_groups SET'));
    }

    public function testUpdatePermiteReordenar()
    {
        $this->autorizarGrupo();
        $this->db->onWrite('UPDATE link_groups SET', 1);

        GroupsHandler::detail($this->db, $this->put(['position' => 3], $this->user(), ['id' => '5']));

        $this->assertSame([3, 5], $this->db->paramsFor('UPDATE link_groups SET'));
    }

    public function testUpdateAceptaVariosCamposALaVez()
    {
        $this->autorizarGrupo();
        $this->db->onWrite('UPDATE link_groups SET', 1);

        GroupsHandler::detail($this->db, $this->put(
            ['title' => 'Agenda', 'type' => 'eventos', 'position' => 1],
            $this->user(),
            ['id' => '5']
        ));

        $this->assertSame(['Agenda', 'eventos', 1, 5], $this->db->paramsFor('UPDATE link_groups SET'));
    }

    public function testUpdateRechazaCuerpoSinCamposConocidos()
    {
        $this->autorizarGrupo();

        $res = GroupsHandler::detail($this->db, $this->put(['otro' => 1], $this->user(), ['id' => '5']));

        $this->assertError(400, $res, 'No fields to update');
        $this->assertNoWrites();
    }

    public function testUpdateDevuelve500SiLaBaseFalla()
    {
        $this->autorizarGrupo();
        $this->db->failOn('UPDATE link_groups SET', 'deadlock');

        $res = GroupsHandler::detail($this->db, $this->put(['title' => 'X'], $this->user(), ['id' => '5']));

        $this->assertError(500, $res, 'Server error: deadlock');
    }

    // ---------------------------------------------------------------- DELETE

    public function testDeleteRechazaGrupoAjeno()
    {
        $res = GroupsHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $this->assertError(404, $res, 'Group not found');
        $this->assertNoWrites();
    }

    public function testDeleteBorraElGrupo()
    {
        $this->autorizarGrupo();
        $this->db->onWrite('DELETE FROM link_groups', 1);

        $res = GroupsHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(['message' => 'Group deleted successfully'], $res->body);
        $this->assertSame([5], $this->db->paramsFor('DELETE FROM link_groups'));
    }

    public function testDeleteDevuelve500SiLaBaseFalla()
    {
        $this->autorizarGrupo();
        $this->db->failOn('DELETE FROM link_groups', 'fallo');

        $res = GroupsHandler::detail($this->db, $this->delete(['id' => '5'], $this->user()));

        $this->assertError(500, $res, 'Server error: fallo');
    }

    // ------------------------------------------------------------- ayudantes

    private function autorizarPagina()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
    }

    private function autorizarGrupo()
    {
        $this->db->onSelect('FROM link_groups lg JOIN pages p', [['1' => 1]]);
    }
}
