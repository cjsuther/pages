<?php

namespace Tests\Unit\Lib;

use Clientes;
use Tests\Support\HandlerTestCase;
use ZipArchive;

class ClientesTest extends HandlerTestCase
{
    private function compra(array $overrides = [])
    {
        return array_merge([
            'codigo' => 'ABC123DEF456',
            'nombre' => 'Ana Gómez',
            'email' => 'ana@example.com',
            'telefono' => '+54 11 2233-4455',
            'cantidad' => 2,
            'ingresadas' => 0,
            'ingreso_en' => null,
            'total' => '3000.00',
            'moneda' => 'ARS',
            'estado' => 'pagada',
            'created_at' => '2026-08-01 20:00:00',
            'pagada_en' => '2026-08-01 20:01:00',
            'vencida' => 0,
            'record_id' => 7,
            'titulo' => 'Fiesta de primavera',
            'event_date' => '2026-09-21',
            'event_time' => '22:00:00',
            'link_id' => 100,
            'rol' => 'organizador',
            'user_id' => null,
        ], $overrides);
    }

    private function hayCompras(array $compras)
    {
        $this->db->onSelect('INNER JOIN ticket_orders o ON o.record_id = r.id', $compras);
    }

    private function haySeguidores(array $seguidores)
    {
        $this->db->onSelect('FROM page_followers pf', $seguidores);
    }

    private function listar(array $filtros = [])
    {
        return Clientes::dePagina($this->db, 5, $filtros);
    }

    // ------------------------------------------------------------- registro

    public function testRegistrarUnEventoGuardaSusDatosYDevuelveElRegistro()
    {
        $this->db->onSelect('SELECT id FROM event_records WHERE link_id', [[7]]);

        $this->assertSame(7, Clientes::registrarEvento($this->db, 100));
        $this->assertSame([100], $this->db->paramsFor('INSERT INTO event_records'));
    }

    public function testRegistrarSumaLaOrganizadoraYLasColaboracionesAceptadas()
    {
        $this->db->onSelect('SELECT id FROM event_records WHERE link_id', [[7]]);

        Clientes::registrarEvento($this->db, 100);

        $this->assertSame([7], $this->db->paramsFor("SELECT id, page_id, 'organizador'"));
        $this->assertSame([7, 100], $this->db->paramsFor("SELECT ?, collaborator_page_id, 'colaborador'"));
    }

    /** Una colaboración deshecha deja de ver a los clientes. */
    public function testRegistrarSacaLasColaboracionesQueYaNoEstan()
    {
        $this->db->onSelect('SELECT id FROM event_records WHERE link_id', [[7]]);

        Clientes::registrarEvento($this->db, 100);

        $this->assertSame([7, 100], $this->db->paramsFor('DELETE FROM event_record_pages'));
    }

    public function testUnEventoQueNoExisteNoTieneRegistro()
    {
        $this->assertNull(Clientes::registrarEvento($this->db, 100));
        $this->assertFalse($this->db->ran('INSERT IGNORE INTO event_record_pages'));
    }

    /** Un evento que nunca vendió no necesita registro. */
    public function testActualizarNoCreaRegistrosNuevos()
    {
        Clientes::actualizarEvento($this->db, 100);

        $this->assertFalse($this->db->ran('INSERT INTO event_records'));
    }

    public function testActualizarRefrescaElRegistroQueYaExiste()
    {
        $this->db->onSelect('SELECT id FROM event_records WHERE link_id', [[7]]);
        $this->db->onSelect('SELECT id FROM event_records WHERE link_id', [[7]]);

        Clientes::actualizarEvento($this->db, 100);

        $this->assertTrue($this->db->ran('INSERT INTO event_records'));
    }

    public function testAntesDeBorrarUnGrupoSeRefrescanSusEventos()
    {
        $this->db->onSelect('WHERE l.group_id = ?', [[100], [101]]);

        Clientes::actualizarEventosDelGrupo($this->db, 3);

        $inserts = $this->db->callsFor('INSERT INTO event_records');
        $this->assertCount(2, $inserts);
        $this->assertSame([101], $inserts[1]['params']);
    }

    // -------------------------------------------------------------- listado

    public function testUnaPersonaQueComproDosVecesEsUnSoloCliente()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'record_id' => 7]),
            $this->compra(['codigo' => 'B2', 'record_id' => 8, 'email' => 'ANA@example.com ', 'created_at' => '2026-09-01 10:00:00']),
        ]);

        $r = $this->listar();

        $this->assertCount(1, $r['clientes']);
        $this->assertSame(2, $r['clientes'][0]['eventos']);
        $this->assertSame(2, $r['clientes'][0]['compras']);
        $this->assertSame(4, $r['clientes'][0]['entradas']);
        $this->assertSame(['ARS' => 6000.0], $r['clientes'][0]['gastado']);
        $this->assertSame('2026-09-01 10:00:00', $r['clientes'][0]['ultima_actividad']);
    }

    /** El registro queda aunque el evento se borre: la compra sigue ahí. */
    public function testLasComprasDeUnEventoBorradoSiguenYSeMarcan()
    {
        $this->hayCompras([$this->compra(['link_id' => null])]);

        $r = $this->listar();

        $this->assertTrue($r['clientes'][0]['participaciones'][0]['eliminado']);
        $this->assertSame('Fiesta de primavera', $r['clientes'][0]['participaciones'][0]['evento']);
    }

    public function testDistingueCompraDeReserva()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'total' => '0.00']),
            $this->compra(['codigo' => 'B2', 'email' => 'beto@example.com', 'nombre' => 'Beto']),
        ]);

        $r = $this->listar(['origen' => 'reservo']);

        $this->assertCount(1, $r['clientes']);
        $this->assertSame('ana@example.com', $r['clientes'][0]['email']);
        $this->assertSame(1, $r['clientes'][0]['reservas']);
        $this->assertSame(0, $r['clientes'][0]['compras']);
    }

    /** Una reserva que venció sin pagar no es una compra, pero la persona queda. */
    public function testUnaReservaVencidaNoCuentaComoCompra()
    {
        $this->hayCompras([$this->compra(['estado' => 'reservada', 'vencida' => 1])]);

        $c = $this->listar()['clientes'][0];

        $this->assertSame(0, $c['compras']);
        $this->assertSame(0, $c['entradas']);
        $this->assertSame('vencida', $c['participaciones'][0]['estado']);
    }

    public function testSabeSiTieneCuentaEnRezonar()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'user_id' => 12]),
            $this->compra(['codigo' => 'B2', 'email' => 'beto@example.com']),
        ]);

        $r = $this->listar(['cuenta' => 'no']);

        $this->assertCount(1, $r['clientes']);
        $this->assertSame('beto@example.com', $r['clientes'][0]['email']);
    }

    public function testLosSeguidoresSonClientesAunqueNoHayanComprado()
    {
        $this->haySeguidores([
            ['user_id' => 12, 'email' => 'caro@example.com', 'name' => 'Caro', 'created_at' => '2026-07-01 12:00:00'],
        ]);

        $c = $this->listar()['clientes'][0];

        $this->assertTrue($c['sigue']);
        $this->assertTrue($c['tiene_cuenta']);
        $this->assertSame('Caro', $c['nombre']);
        $this->assertSame(0, $c['eventos']);
    }

    public function testUnSeguidorQueComproEsUnSoloCliente()
    {
        $this->hayCompras([$this->compra()]);
        $this->haySeguidores([
            ['user_id' => 12, 'email' => 'Ana@Example.com', 'name' => 'Ana G.', 'created_at' => '2026-07-01 12:00:00'],
        ]);

        $r = $this->listar();

        $this->assertCount(1, $r['clientes']);
        $this->assertTrue($r['clientes'][0]['sigue']);
        $this->assertSame('Ana Gómez', $r['clientes'][0]['nombre'], 'el nombre de la compra le gana al de la cuenta');
    }

    /** Con un evento elegido, quien sólo sigue la página no tiene qué mostrar. */
    public function testFiltrarPorEventoDejaAfueraAQuienSoloSigue()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'record_id' => 7]),
            $this->compra(['codigo' => 'B2', 'record_id' => 8]),
        ]);
        $this->haySeguidores([
            ['user_id' => 12, 'email' => 'caro@example.com', 'name' => 'Caro', 'created_at' => '2026-07-01 12:00:00'],
        ]);

        $r = $this->listar(['evento' => '8']);

        $this->assertCount(1, $r['clientes']);
        $this->assertCount(1, $r['clientes'][0]['participaciones']);
        $this->assertSame('B2', $r['clientes'][0]['participaciones'][0]['codigo']);
    }

    public function testFiltraPorFechaDelEvento()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'event_date' => '2026-05-01']),
            $this->compra(['codigo' => 'B2', 'email' => 'beto@example.com', 'event_date' => '2026-09-21']),
        ]);

        $r = $this->listar(['desde' => '2026-09-01', 'hasta' => '2026-09-30']);

        $this->assertCount(1, $r['clientes']);
        $this->assertSame('beto@example.com', $r['clientes'][0]['email']);
    }

    public function testFiltraPorQuienVinoYQuienFalto()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'ingresadas' => 2]),
            $this->compra(['codigo' => 'B2', 'email' => 'beto@example.com', 'event_date' => '2020-01-01']),
            // Todavía no pasó: no se puede decir que faltó.
            $this->compra(['codigo' => 'C3', 'email' => 'caro@example.com', 'event_date' => '2099-01-01']),
        ]);

        $this->assertSame(['ana@example.com'], array_column($this->listar(['asistencia' => 'vino'])['clientes'], 'email'));

        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'ingresadas' => 2]),
            $this->compra(['codigo' => 'B2', 'email' => 'beto@example.com', 'event_date' => '2020-01-01']),
            $this->compra(['codigo' => 'C3', 'email' => 'caro@example.com', 'event_date' => '2099-01-01']),
        ]);

        $this->assertSame(['beto@example.com'], array_column($this->listar(['asistencia' => 'no_vino'])['clientes'], 'email'));
    }

    public function testBuscaPorNombreEmailOTelefono()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1']),
            $this->compra(['codigo' => 'B2', 'email' => 'beto@example.com', 'nombre' => 'Beto Pérez', 'telefono' => '351 555']),
        ]);

        $r = $this->listar(['q' => 'PÉREZ']);

        $this->assertSame(['beto@example.com'], array_column($r['clientes'], 'email'));
    }

    public function testUnFiltroQueNoSeEntiendeSeIgnora()
    {
        $this->hayCompras([$this->compra()]);

        $this->assertCount(1, $this->listar(['origen' => 'cualquiera', 'desde' => 'ayer'])['clientes']);
    }

    public function testElColaboradorVeElRolDeSuPaginaEnCadaCompra()
    {
        $this->hayCompras([$this->compra(['rol' => 'colaborador'])]);

        $this->assertSame('colaborador', $this->listar()['clientes'][0]['participaciones'][0]['rol']);
        $this->assertSame([5], $this->db->paramsFor('INNER JOIN ticket_orders o ON o.record_id = r.id'));
    }

    public function testElResumenCuentaCadaGrupo()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'ingresadas' => 1, 'user_id' => 12]),
            $this->compra(['codigo' => 'B2', 'email' => 'beto@example.com']),
        ]);
        $this->haySeguidores([
            ['user_id' => 12, 'email' => 'ana@example.com', 'name' => 'Ana', 'created_at' => '2026-07-01 12:00:00'],
            ['user_id' => 13, 'email' => 'caro@example.com', 'name' => 'Caro', 'created_at' => '2026-07-01 12:00:00'],
        ]);

        $this->assertSame(
            ['clientes' => 3, 'con_cuenta' => 2, 'seguidores' => 2, 'compradores' => 2, 'vinieron' => 1],
            $this->listar()['resumen']
        );
    }

    // ---------------------------------------------------------------- excel

    public function testElExcelTraeUnaHojaDePersonasYOtraDeCompras()
    {
        $this->hayCompras([
            $this->compra(['codigo' => 'A1', 'link_id' => null]),
            $this->compra(['codigo' => 'B2', 'record_id' => 8, 'titulo' => 'Otra fecha']),
        ]);

        $archivo = Clientes::excel($this->listar()['clientes']);

        $ruta = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($ruta, $archivo);
        $zip = new ZipArchive();
        $zip->open($ruta);
        $personas = $zip->getFromName('xl/worksheets/sheet1.xml');
        $compras = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        unlink($ruta);

        $this->assertSame(1, substr_count($personas, 'ana@example.com'));
        $this->assertStringContainsString('Fiesta de primavera', $compras);
        $this->assertStringContainsString('Otra fecha', $compras);
        $this->assertSame(2, substr_count($compras, 'ana@example.com'));
    }
}
