<?php

namespace Tests\Unit\Lib;

use SubidasConToken;
use Tests\Support\HandlerTestCase;

class SubidasConTokenTest extends HandlerTestCase
{
    private function hayPermiso(array $overrides = [])
    {
        $this->db->onSelect('FROM image_uploads WHERE token_hash', [array_merge([
            'id' => 4, 'user_id' => 7, 'link_id' => 300,
            'expira_en' => date('Y-m-d H:i:s', time() + 600),
            'usado_en' => null,
        ], $overrides)]);
    }

    public function testCrearDevuelveUnTokenYNoLoGuardaEnClaro()
    {
        $this->db->onWrite('INSERT INTO image_uploads', 1);

        $token = SubidasConToken::crear($this->db, 7, 300);

        $this->assertNotEmpty($token);
        $this->assertNotContains($token, $this->db->paramsFor('INSERT INTO image_uploads'));
        $this->assertContains(SubidasConToken::hash($token), $this->db->paramsFor('INSERT INTO image_uploads'));
    }

    /** El evento se ata al emitir: quien tenga el link no puede elegir otro. */
    public function testElPermisoQuedaAtadoAlEvento()
    {
        $this->db->onWrite('INSERT INTO image_uploads', 1);

        SubidasConToken::crear($this->db, 7, 300);

        $this->assertContains(300, $this->db->paramsFor('INSERT INTO image_uploads'));
    }

    public function testUnTokenVigenteDevuelveElPermiso()
    {
        $this->hayPermiso();

        $this->assertSame(300, (int) SubidasConToken::vigente($this->db, 'x')['link_id']);
    }

    public function testUnTokenVencidoNoSirve()
    {
        $this->hayPermiso(['expira_en' => date('Y-m-d H:i:s', time() - 10)]);

        $this->assertNull(SubidasConToken::vigente($this->db, 'x'));
    }

    /** Sirve una sola vez: si no, el link sería una puerta abierta. */
    public function testUnTokenYaUsadoNoSirve()
    {
        $this->hayPermiso(['usado_en' => date('Y-m-d H:i:s')]);

        $this->assertNull(SubidasConToken::vigente($this->db, 'x'));
    }

    public function testUnTokenInventadoNoSirve()
    {
        $this->db->onSelect('FROM image_uploads WHERE token_hash', []);

        $this->assertNull(SubidasConToken::vigente($this->db, 'inventado'));
    }

    /**
     * Dos personas soltando el archivo a la vez leen lo mismo: la condición va
     * también en el UPDATE para que sólo una pueda seguir.
     */
    public function testMarcarUsadoNoSePuedeHacerDosVeces()
    {
        $this->db->onWrite('UPDATE image_uploads', 1);
        $this->assertTrue(SubidasConToken::marcarUsado($this->db, 4));

        $this->db->onWrite('UPDATE image_uploads', 0);
        $this->assertFalse(SubidasConToken::marcarUsado($this->db, 4));

        $this->assertStringContainsString('usado_en IS NULL', $this->db->callsFor('UPDATE image_uploads')[0]['sql']);
    }
}
