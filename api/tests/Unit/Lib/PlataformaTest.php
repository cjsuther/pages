<?php

namespace Tests\Unit\Lib;

use Plataforma;
use Tests\Support\FakePdo;
use PHPUnit\Framework\TestCase;

/**
 * Quién administra la plataforma.
 *
 * Lo que importa fijar es lo que pasa cuando la respuesta es "no": este
 * permiso abre la edición de páginas ajenas, así que un falso positivo no lo
 * ve nadie hasta que alguien edita lo que no es suyo.
 *
 * SUPERADMIN_EMAILS lo define tests/bootstrap.php como ['plataforma@test'].
 */
class PlataformaTest extends TestCase
{
    /** @var FakePdo */
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePdo();
        Plataforma::olvidar();
    }

    protected function tearDown(): void
    {
        Plataforma::olvidar();
        parent::tearDown();
    }

    private function usuarioEs($correo)
    {
        $this->db->onSelect('SELECT email FROM users', [[$correo]]);
    }

    // ------------------------------------------------------------- por correo

    public function testReconoceElCorreoConfigurado()
    {
        $this->assertTrue(Plataforma::esCorreoAdmin('plataforma@test'));
    }

    public function testNoReconoceOtroCorreo()
    {
        $this->assertFalse(Plataforma::esCorreoAdmin('cualquiera@test'));
    }

    /** Los correos no distinguen mayúsculas y la gente los escribe como sale. */
    public function testElCorreoSeComparaSinMayusculas()
    {
        $this->assertTrue(Plataforma::esCorreoAdmin('  Plataforma@TEST '));
    }

    public function testUnCorreoVacioNoEsAdmin()
    {
        $this->assertFalse(Plataforma::esCorreoAdmin(''));
        $this->assertFalse(Plataforma::esCorreoAdmin(null));
    }

    // ------------------------------------------------------------ por usuario

    public function testElUsuarioConEseCorreoEsAdmin()
    {
        $this->usuarioEs('plataforma@test');

        $this->assertTrue(Plataforma::esAdmin($this->db, 9));
    }

    public function testOtroUsuarioNoEsAdmin()
    {
        $this->usuarioEs('alguien@test');

        $this->assertFalse(Plataforma::esAdmin($this->db, 9));
    }

    /** Un id que no existe devuelve false, no una consulta rota. */
    public function testUnUsuarioInexistenteNoEsAdmin()
    {
        $this->assertFalse(Plataforma::esAdmin($this->db, 12345));
    }

    public function testSinSesionNoEsAdmin()
    {
        $this->assertFalse(Plataforma::esAdmin($this->db, 0));
        $this->assertSame(0, $this->db->countCalls('SELECT email FROM users'));
    }

    public function testDevuelveBooleanoNoLaFila()
    {
        $this->usuarioEs('plataforma@test');

        $this->assertIsBool(Plataforma::esAdmin($this->db, 9));
    }

    /**
     * El permiso se consulta muchas veces por petición —cada endpoint que toca
     * una página pregunta— y sin cache serían otras tantas consultas.
     */
    public function testNoConsultaDosVecesPorElMismoUsuario()
    {
        $this->usuarioEs('plataforma@test');

        Plataforma::esAdmin($this->db, 9);
        Plataforma::esAdmin($this->db, 9);

        $this->assertSame(1, $this->db->countCalls('SELECT email FROM users'));
    }
}
