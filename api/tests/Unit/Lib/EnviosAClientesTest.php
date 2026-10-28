<?php

namespace Tests\Unit\Lib;

use EnviosAClientes;
use Tests\Support\HandlerTestCase;

class EnviosAClientesTest extends HandlerTestCase
{
    private function compra($email, $nombre, array $cambios = [])
    {
        return array_merge([
            'codigo' => 'C' . md5($email), 'nombre' => $nombre, 'email' => $email, 'telefono' => '11 5555-0000',
            'cantidad' => 1, 'ingresadas' => 0, 'ingreso_en' => null, 'total' => '1000.00', 'moneda' => 'ARS',
            'estado' => 'pagada', 'created_at' => '2026-08-01 20:00:00', 'pagada_en' => '2026-08-01 20:01:00',
            'vencida' => 0, 'record_id' => 7, 'titulo' => 'Fiesta', 'event_date' => '2026-09-21',
            'event_time' => '22:00:00', 'link_id' => 100, 'rol' => 'organizador', 'user_id' => null,
        ], $cambios);
    }

    private function clientes(array $compras, array $bajas = [])
    {
        $this->db->onSelect('INNER JOIN ticket_orders o ON o.record_id = r.id', $compras);
        $this->db->onSelect('SELECT email FROM bajas_de_correo', array_map(function ($e) {
            return ['email' => $e];
        }, $bajas));
    }

    private function emails(array $resultado)
    {
        return array_column($resultado['destinatarios'], 'email');
    }

    // ---------------------------------------------------------- selección

    public function testPorFiltrosVanTodosLosQueLosCumplenMenosLosExcluidos()
    {
        $this->clientes([
            $this->compra('ana@example.com', 'Ana'),
            $this->compra('beto@example.com', 'Beto'),
            $this->compra('caro@example.com', 'Caro', ['total' => '0.00']),
        ]);

        $res = EnviosAClientes::destinatarios($this->db, 5, [
            'modo' => 'filtros',
            'filtros' => ['origen' => 'compro'],
            'excluidos' => ['BETO@example.com'],
        ]);

        // Caro sólo reservó gratis: no cumple el filtro. Beto está destildado.
        $this->assertSame(['ana@example.com'], $this->emails($res));
        $this->assertSame(['email' => 'ana@example.com', 'nombre' => 'Ana', 'telefono' => '11 5555-0000'], $res['destinatarios'][0]);
    }

    public function testPorFiltrosLaConsultaEsLaDeClientes()
    {
        $this->clientes([]);

        EnviosAClientes::destinatarios($this->db, 5, ['modo' => 'filtros', 'filtros' => ['evento' => 7]]);

        $this->assertSame([5], $this->db->paramsFor('INNER JOIN ticket_orders o ON o.record_id = r.id'));
        $this->assertSame([5], $this->db->paramsFor('FROM page_followers pf'));
    }

    public function testPorEmailsSoloQuedanLosQueSonClientesDeLaPagina()
    {
        $this->clientes([$this->compra('ana@example.com', 'Ana'), $this->compra('beto@example.com', 'Beto')]);

        $res = EnviosAClientes::destinatarios($this->db, 5, [
            'modo' => 'emails',
            'emails' => ['Ana@example.com', 'intruso@example.com', 'ana@example.com'],
        ]);

        $this->assertSame(['ana@example.com'], $this->emails($res));
        $this->assertSame(1, $res['ignorados']);
    }

    public function testLaBajaExcluye()
    {
        $this->clientes([$this->compra('ana@example.com', 'Ana'), $this->compra('Beto@Example.com', 'Beto')], ['beto@example.com']);

        $porFiltros = EnviosAClientes::destinatarios($this->db, 5, ['modo' => 'filtros']);

        $this->assertSame(['ana@example.com'], $this->emails($porFiltros));
        $this->assertSame(1, $porFiltros['de_baja']);

        $this->clientes([$this->compra('ana@example.com', 'Ana'), $this->compra('Beto@Example.com', 'Beto')], ['beto@example.com']);

        $porEmail = EnviosAClientes::destinatarios($this->db, 5, ['modo' => 'emails', 'emails' => ['beto@example.com']]);

        $this->assertSame([], $porEmail['destinatarios']);
        $this->assertSame(0, $porEmail['ignorados'], 'es cliente, sólo que se dio de baja');
    }

    public function testUnEmailInvalidoNoEntra()
    {
        $this->clientes([$this->compra('ana@example.com', 'Ana'), $this->compra('sin-arroba', 'X')]);

        $this->assertSame(['ana@example.com'], $this->emails(EnviosAClientes::destinatarios($this->db, 5, ['modo' => 'filtros'])));
    }

    public function testSinModoOSinElegidosEsUnError()
    {
        $this->assertArrayHasKey('error', EnviosAClientes::destinatarios($this->db, 5, null));
        $this->assertArrayHasKey('error', EnviosAClientes::destinatarios($this->db, 5, ['modo' => 'todos']));
        $this->assertArrayHasKey('error', EnviosAClientes::destinatarios($this->db, 5, ['modo' => 'emails', 'emails' => []]));
        $this->assertSame(0, $this->db->countCalls('ticket_orders'));
    }

    // ------------------------------------------------------- marcar bajas

    public function testMarcaLasBajasYCuentaLosContactables()
    {
        $this->db->onSelect('SELECT email FROM bajas_de_correo', [['email' => 'beto@example.com']]);

        $listado = EnviosAClientes::marcarBajas($this->db, 5, [
            'clientes' => [['email' => 'ana@example.com'], ['email' => 'Beto@example.com'], ['email' => 'roto']],
            'resumen' => ['clientes' => 3],
        ]);

        $this->assertSame([false, true, false], array_column($listado['clientes'], 'baja'));
        $this->assertSame([true, false, false], array_column($listado['clientes'], 'puede_recibir'));
        $this->assertSame(1, $listado['resumen']['contactables']);
    }

    // -------------------------------------------------------------- crear

    public function testCrearCongelaElMensajeYLaListaEnUnaTransaccion()
    {
        $this->db->onInsert('INSERT INTO envios', 9);
        $destinatarios = [];
        for ($i = 0; $i < 501; $i++) {
            $destinatarios[] = ['email' => "c$i@example.com", 'nombre' => $i ? "C$i" : '', 'telefono' => ''];
        }

        $id = EnviosAClientes::crear($this->db, 5, 1,
            ['asunto' => 'Hola', 'cuerpo' => 'Hola {nombre}', 'reemplazo_nombre' => 'amigo'],
            ['id' => 3, 'nombre' => 'Aviso'], $destinatarios);

        $this->assertSame(9, $id);
        $this->assertSame([5, 3, 'Aviso', 'Hola', 'Hola {nombre}', 'amigo', 1, 501], $this->db->paramsFor('INSERT INTO envios'));

        $tandas = $this->db->callsFor('INSERT INTO envio_destinatarios');
        $this->assertCount(2, $tandas, 'de a 500 por INSERT');
        $this->assertSame([9, 'c0@example.com', null, null], array_slice($tandas[0]['params'], 0, 4));
        $this->assertCount(4, $tandas[1]['params']);
        $this->assertTrue($this->db->committed);
    }

    public function testSiFallaLaListaNoQuedaElEnvio()
    {
        $this->db->failOn('INSERT INTO envio_destinatarios');

        try {
            EnviosAClientes::crear($this->db, 5, 1, ['asunto' => 'a', 'cuerpo' => 'b', 'reemplazo_nombre' => ''], null,
                [['email' => 'a@example.com', 'nombre' => 'A', 'telefono' => '']]);
            $this->fail('Tenía que fallar');
        } catch (\PDOException $e) {
            $this->assertTrue($this->db->rolledBack);
            $this->assertFalse($this->db->committed);
        }
    }

    // ------------------------------------------------------------- estado

    public function acciones()
    {
        return [
            'pausar uno en curso' => ['en_curso', 'pausar', 'pausado'],
            'reanudar uno pausado' => ['pausado', 'reanudar', 'en_curso'],
            'cancelar uno en curso' => ['en_curso', 'cancelar', 'cancelado'],
            'cancelar uno pausado' => ['pausado', 'cancelar', 'cancelado'],
        ];
    }

    /** @dataProvider acciones */
    public function testCambiaElEstado($antes, $accion, $despues)
    {
        $this->assertSame(['estado' => $despues], EnviosAClientes::cambiarEstado($this->db, ['id' => 4, 'estado' => $antes], $accion));
        $this->assertSame([4], $this->db->paramsFor('UPDATE envios SET estado'));
    }

    public function testCancelarOmiteLosPendientes()
    {
        EnviosAClientes::cambiarEstado($this->db, ['id' => 4, 'estado' => 'en_curso'], 'cancelar');

        $this->assertSame([4], $this->db->paramsFor("UPDATE envio_destinatarios SET estado = 'omitido'"));
    }

    public function testNoSeCambiaLoQueYaTermino()
    {
        $this->assertArrayHasKey('error', EnviosAClientes::cambiarEstado($this->db, ['id' => 4, 'estado' => 'terminado'], 'cancelar'));
        $this->assertArrayHasKey('error', EnviosAClientes::cambiarEstado($this->db, ['id' => 4, 'estado' => 'pausado'], 'pausar'));
        $this->assertArrayHasKey('error', EnviosAClientes::cambiarEstado($this->db, ['id' => 4, 'estado' => 'en_curso'], 'reanudar'));
        $this->assertArrayHasKey('error', EnviosAClientes::cambiarEstado($this->db, ['id' => 4, 'estado' => 'en_curso'], 'borrar'));
        $this->assertNoWrites();
    }
}
