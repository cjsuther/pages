<?php

namespace Tests\Integration;

use PagesHandler;
use Tests\Support\IntegracionTestCase;

/**
 * El listado de "Mis páginas" contra una MariaDB de verdad: cada página dice
 * de quién es, también las que se administran sin ser propias.
 */
class MisPaginasTest extends IntegracionTestCase
{
    public function testCadaPaginaDiceDeQuienEs()
    {
        $ana = $this->usuario('ana@test.local', 'Ana');
        $beto = $this->usuario('beto@test.local', 'Beto');

        $propia = $this->pagina($ana, 'la-de-ana', 'La de Ana');
        $ajena = $this->pagina($beto, 'la-de-beto', 'La de Beto');
        $this->insertar('page_admins', [
            'page_id' => $ajena, 'user_id' => $ana, 'invited_by' => $beto, 'status' => 'accepted',
        ]);

        $res = PagesHandler::index($this->db, $this->get([], $this->user($ana, 'ana@test.local')));

        $this->assertStatus(200, $res);
        $porId = array_column($res->body['pages'], null, 'id');
        $this->assertCount(2, $porId);

        $this->assertSame('Ana', $porId[$propia]['owner_name']);
        $this->assertSame('ana@test.local', $porId[$propia]['owner_email']);
        $this->assertSame('Beto', $porId[$ajena]['owner_name']);
        $this->assertSame('beto@test.local', $porId[$ajena]['owner_email']);
    }
}
