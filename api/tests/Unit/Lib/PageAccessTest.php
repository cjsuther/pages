<?php

namespace Tests\Unit\Lib;

use PageAccess;
use Tests\Support\FakePdo;
use PHPUnit\Framework\TestCase;

/**
 * PageAccess decide quién puede modificar qué. Los tests fijan dos cosas:
 * que la respuesta sea booleana estricta (los handlers hacen `if (!...)`) y
 * que los parámetros lleguen a la consulta en el orden correcto — un orden
 * invertido dejaría pasar autorizaciones ajenas sin fallar ningún otro test.
 */
class PageAccessTest extends TestCase
{
    /** @var FakePdo */
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePdo();
    }

    // ------------------------------------------------------------- canManage

    public function testCanManageEsTrueSiLaConsultaDevuelveFila()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);

        $this->assertTrue(PageAccess::canManage($this->db, 10, 5));
    }

    public function testCanManageEsFalseSiNoHayFila()
    {
        $this->assertFalse(PageAccess::canManage($this->db, 10, 5));
    }

    public function testCanManageDevuelveBooleanoNoLaFila()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);

        $this->assertIsBool(PageAccess::canManage($this->db, 10, 5));
    }

    public function testCanManagePasaPageIdYDosVecesElUserId()
    {
        PageAccess::canManage($this->db, 10, 5);

        // La consulta compara p.user_id = ? y pa.user_id = ?: el userId va dos veces.
        $this->assertSame([10, 5, 5], $this->db->paramsFor('FROM pages p WHERE p.id = ?'));
    }

    public function testCanManageExigeAdminAceptado()
    {
        PageAccess::canManage($this->db, 10, 5);

        $sql = $this->db->callsFor('FROM pages p WHERE p.id = ?')[0]['sql'];

        $this->assertStringContainsString('status = "accepted"', $sql);
    }

    // -------------------------------------------------------------- isOwner

    public function testIsOwnerEsTrueSiLaPaginaEsDelUsuario()
    {
        $this->db->onSelect('FROM pages WHERE id = ? AND user_id = ?', [['1' => 1]]);

        $this->assertTrue(PageAccess::isOwner($this->db, 10, 5));
    }

    public function testIsOwnerEsFalseSiNoLoEs()
    {
        $this->assertFalse(PageAccess::isOwner($this->db, 10, 5));
    }

    public function testIsOwnerPasaPageIdYUserId()
    {
        PageAccess::isOwner($this->db, 10, 5);

        $this->assertSame([10, 5], $this->db->paramsFor('FROM pages WHERE id = ? AND user_id = ?'));
    }

    public function testIsOwnerNoConsultaLaTablaDeAdministradores()
    {
        PageAccess::isOwner($this->db, 10, 5);

        $sql = $this->db->callsFor('FROM pages WHERE id = ?')[0]['sql'];

        // Borrar la página y gestionar admins son acciones exclusivas del dueño:
        // si isOwner considerara a los admins, un admin podría expulsar al dueño.
        $this->assertStringNotContainsString('page_admins', $sql);
    }

    // -------------------------------------------------------- canManageGroup

    public function testCanManageGroupEsTrueSiLaConsultaDevuelveFila()
    {
        $this->db->onSelect('FROM link_groups lg', [['1' => 1]]);

        $this->assertTrue(PageAccess::canManageGroup($this->db, 20, 5));
    }

    public function testCanManageGroupEsFalseSiNoHayFila()
    {
        $this->assertFalse(PageAccess::canManageGroup($this->db, 20, 5));
    }

    public function testCanManageGroupPasaGroupIdYDosVecesElUserId()
    {
        PageAccess::canManageGroup($this->db, 20, 5);

        $this->assertSame([20, 5, 5], $this->db->paramsFor('FROM link_groups lg'));
    }

    public function testCanManageGroupResuelveLaPaginaPorJoin()
    {
        PageAccess::canManageGroup($this->db, 20, 5);

        $sql = $this->db->callsFor('FROM link_groups lg')[0]['sql'];

        $this->assertStringContainsString('JOIN pages p ON lg.page_id = p.id', $sql);
    }

    // --------------------------------------------------------- canManageLink

    public function testCanManageLinkEsTrueSiLaConsultaDevuelveFila()
    {
        $this->db->onSelect('FROM links l', [['1' => 1]]);

        $this->assertTrue(PageAccess::canManageLink($this->db, 30, 5));
    }

    public function testCanManageLinkEsFalseSiNoHayFila()
    {
        $this->assertFalse(PageAccess::canManageLink($this->db, 30, 5));
    }

    public function testCanManageLinkPasaLinkIdYDosVecesElUserId()
    {
        PageAccess::canManageLink($this->db, 30, 5);

        $this->assertSame([30, 5, 5], $this->db->paramsFor('FROM links l'));
    }

    public function testCanManageLinkSubeLaCadenaHastaLaPagina()
    {
        PageAccess::canManageLink($this->db, 30, 5);

        $sql = $this->db->callsFor('FROM links l')[0]['sql'];

        $this->assertStringContainsString('JOIN link_groups lg ON l.group_id = lg.id', $sql);
        $this->assertStringContainsString('JOIN pages p ON lg.page_id = p.id', $sql);
    }

    // ------------------------------------------------------------- transversal

    /**
     * Ninguna comprobación de permisos debe escribir en la base.
     *
     * @dataProvider metodosDeLectura
     */
    public function testLasComprobacionesNoEscriben($metodo)
    {
        PageAccess::$metodo($this->db, 1, 2);

        foreach ($this->db->log() as $entry) {
            $this->assertStringStartsWith('SELECT', trim($entry['sql']));
        }
    }

    public function metodosDeLectura()
    {
        return [
            ['canManage'],
            ['isOwner'],
            ['canManageGroup'],
            ['canManageLink'],
        ];
    }
}
