<?php

namespace Tests\Unit\Handlers;

use BajasDeCorreo;
use EnviosHandler;
use Tests\Support\HandlerTestCase;

class EnviosHandlerTest extends HandlerTestCase
{
    private function puedeGestionar()
    {
        $this->db->onSelect('FROM pages p', [['1' => 1]]);
    }

    private function conCuenta()
    {
        $this->db->onSelect('FROM page_smtp WHERE page_id', [['page_id' => 5, 'host' => 'smtp.gmail.com']]);
    }

    private function compra($email, $nombre)
    {
        return [
            'codigo' => 'C' . md5($email), 'nombre' => $nombre, 'email' => $email, 'telefono' => '',
            'cantidad' => 1, 'ingresadas' => 0, 'ingreso_en' => null, 'total' => '1000.00', 'moneda' => 'ARS',
            'estado' => 'pagada', 'created_at' => '2026-08-01 20:00:00', 'pagada_en' => null, 'vencida' => 0,
            'record_id' => 7, 'titulo' => 'Fiesta', 'event_date' => '2026-09-21', 'event_time' => null,
            'link_id' => 100, 'rol' => 'organizador', 'user_id' => null,
        ];
    }

    private function envio(array $cambios = [])
    {
        return array_merge([
            'id' => 9, 'mensaje_nombre' => 'Aviso', 'asunto' => 'Hola', 'cuerpo' => 'Hola {nombre}', 'reemplazo_nombre' => '',
            'estado' => 'en_curso', 'motivo_pausa' => null, 'total' => 2, 'creado_en' => '2026-10-22 10:00:00',
            'terminado_en' => null, 'enviados' => 1, 'fallidos' => 0, 'pendientes' => 1, 'omitidos' => 0,
        ], $cambios);
    }

    private function pedidoDeEnvio(array $cambios = [])
    {
        return array_merge([
            'page_id' => 5,
            'mensaje' => ['asunto' => 'Novedades', 'cuerpo' => 'Hola {primer_nombre},', 'reemplazo_nombre' => ''],
            'seleccion' => ['modo' => 'filtros', 'filtros' => [], 'excluidos' => []],
        ], $cambios);
    }

    // ---------------------------------------------------------- permisos

    public function testExigeSesion()
    {
        $this->assertError(401, EnviosHandler::mensajes($this->db, $this->get(['page_id' => 5])));
        $this->assertError(401, EnviosHandler::index($this->db, $this->get(['page_id' => 5])));
    }

    public function testExigeLaPaginaYUnMetodoConocido()
    {
        $this->assertError(400, EnviosHandler::index($this->db, $this->get([], $this->user())));
        $this->assertError(405, EnviosHandler::index($this->db, $this->delete(['page_id' => 5], $this->user())));
        $this->assertError(405, EnviosHandler::mensajes($this->db, new \Request('PATCH', [], ['page_id' => 5], $this->user())));
    }

    /** Ni ver, ni crear, ni mandar, ni pausar sin ser dueño o administrador. */
    public function testSinPermisoSobreLaPaginaDa403()
    {
        $this->assertError(403, EnviosHandler::mensajes($this->db, $this->get(['page_id' => 5], $this->user())));
        $this->assertError(403, EnviosHandler::mensajes($this->db, $this->post(['page_id' => 5, 'nombre' => 'a', 'asunto' => 'b', 'cuerpo' => 'c'], $this->user())));
        $this->assertError(403, EnviosHandler::mensajes($this->db, $this->delete(['page_id' => 5, 'id' => 1], $this->user())));
        $this->assertError(403, EnviosHandler::index($this->db, $this->get(['page_id' => 5], $this->user())));
        $this->assertError(403, EnviosHandler::index($this->db, $this->post($this->pedidoDeEnvio(), $this->user())));
        $this->assertError(403, EnviosHandler::index($this->db, $this->put(['page_id' => 5, 'id' => 9, 'accion' => 'cancelar'], $this->user())));

        $this->assertNoWrites();
        $this->assertSame(0, $this->db->countCalls('ticket_orders'));
    }

    public function testElPermisoIncluyeALosAdministradores()
    {
        EnviosHandler::index($this->db, $this->get(['page_id' => 5], $this->user(3)));

        $consulta = $this->db->callsFor('FROM pages p')[0];
        $this->assertStringContainsString('page_admins', $consulta['sql']);
        $this->assertSame([5, 3, 3], $consulta['params']);
    }

    // ---------------------------------------------------------- mensajes

    public function testListaLosMensajesYSiHayCuenta()
    {
        $this->puedeGestionar();
        $this->db->onSelect('FROM mensajes_guardados WHERE page_id', [[
            'id' => '2', 'nombre' => 'Aviso', 'asunto' => 'Hola', 'cuerpo' => 'Hola {nombre}', 'reemplazo_nombre' => null, 'actualizado_en' => 'x',
        ]]);

        $res = EnviosHandler::mensajes($this->db, $this->get(['page_id' => 5], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(2, $res->body['mensajes'][0]['id']);
        $this->assertSame('', $res->body['mensajes'][0]['reemplazo_nombre']);
        $this->assertFalse($res->body['cuenta_configurada']);
    }

    public function testCreaUnMensaje()
    {
        $this->puedeGestionar();
        $this->db->onInsert('INSERT INTO mensajes_guardados', 4);

        $res = EnviosHandler::mensajes($this->db, $this->post([
            'page_id' => 5, 'nombre' => 'Aviso', 'asunto' => 'Hola', 'cuerpo' => "Hola {primer_nombre},\nTe esperamos", 'reemplazo_nombre' => '',
        ], $this->user()));

        $this->assertStatus(201, $res);
        $this->assertSame(4, $res->body['mensaje']['id']);
        $this->assertSame([5, 'Aviso', 'Hola', "Hola {primer_nombre},\nTe esperamos", ''], $this->db->paramsFor('INSERT INTO mensajes_guardados'));
    }

    public function testUnMensajeIncompletoNoSeGuarda()
    {
        $this->puedeGestionar();

        $this->assertError(400, EnviosHandler::mensajes($this->db, $this->post(['page_id' => 5, 'nombre' => 'Aviso', 'asunto' => 'Hola'], $this->user())), 'texto');
        $this->assertNoWrites();
    }

    public function testEditaSoloUnMensajeDeLaPagina()
    {
        $this->puedeGestionar();

        $res = EnviosHandler::mensajes($this->db, $this->put(['page_id' => 5, 'id' => 4, 'nombre' => 'A', 'asunto' => 'B', 'cuerpo' => 'C'], $this->user()));

        $this->assertError(404, $res);
        $this->assertSame([4, 5], $this->db->paramsFor('SELECT id, nombre FROM mensajes_guardados'));
        $this->assertNoWrites();

        $this->puedeGestionar();
        $this->db->onSelect('SELECT id, nombre FROM mensajes_guardados', [['id' => 4, 'nombre' => 'Viejo']]);

        $res = EnviosHandler::mensajes($this->db, $this->put(['page_id' => 5, 'id' => 4, 'nombre' => 'A', 'asunto' => 'B', 'cuerpo' => 'C'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(['A', 'B', 'C', '', 4, 5], $this->db->paramsFor('UPDATE mensajes_guardados'));
    }

    public function testBorraSoloUnMensajeDeLaPagina()
    {
        $this->puedeGestionar();
        $this->db->onWrite('DELETE FROM mensajes_guardados', 0);

        $this->assertError(404, EnviosHandler::mensajes($this->db, $this->delete(['page_id' => 5, 'id' => 4], $this->user())));
        $this->assertSame([4, 5], $this->db->paramsFor('DELETE FROM mensajes_guardados WHERE id = ? AND page_id = ?'));

        $this->puedeGestionar();
        $this->db->onWrite('DELETE FROM mensajes_guardados', 1);

        $this->assertStatus(200, EnviosHandler::mensajes($this->db, $this->delete(['page_id' => 5, 'id' => 4], $this->user())));
    }

    // ------------------------------------------------------- crear envío

    public function testSinCuentaSmtpNoSeCreaElEnvio()
    {
        $this->puedeGestionar();

        $res = EnviosHandler::index($this->db, $this->post($this->pedidoDeEnvio(), $this->user()));

        $this->assertError(409, $res, 'Configuración → Email');
        $this->assertNoWrites();
    }

    public function testCreaElEnvioConLosClientesQueCumplenLosFiltros()
    {
        $this->puedeGestionar();
        $this->conCuenta();
        $this->db->onSelect('SELECT id, nombre FROM mensajes_guardados', [['id' => 4, 'nombre' => 'Aviso']]);
        $this->db->onSelect('INNER JOIN ticket_orders o ON o.record_id = r.id', [
            $this->compra('ana@example.com', 'Ana'),
            $this->compra('beto@example.com', 'Beto'),
            $this->compra('caro@example.com', 'Caro'),
        ]);
        $this->db->onSelect('SELECT email FROM bajas_de_correo', [['email' => 'caro@example.com']]);
        $this->db->onInsert('INSERT INTO envios', 9);
        $this->db->onSelect('FROM envios e', [$this->envio(['total' => 1])]);

        $res = EnviosHandler::index($this->db, $this->post($this->pedidoDeEnvio([
            'mensaje_id' => 4,
            'seleccion' => ['modo' => 'filtros', 'filtros' => ['q' => 'example'], 'excluidos' => ['beto@example.com']],
        ]), $this->user(1)));

        $this->assertStatus(201, $res);
        $this->assertSame(1, $res->body['total']);
        $this->assertSame(1, $res->body['de_baja']);
        $this->assertSame(9, $res->body['envio']['id']);
        $this->assertSame([5, 4, 'Aviso', 'Novedades', 'Hola {primer_nombre},', '', 1, 1], $this->db->paramsFor('INSERT INTO envios'));
        $this->assertSame([9, 'ana@example.com', 'Ana', null], $this->db->paramsFor('INSERT INTO envio_destinatarios'));
    }

    public function testNuncaAceptaUnEmailQueNoSeaCliente()
    {
        $this->puedeGestionar();
        $this->conCuenta();
        $this->db->onSelect('INNER JOIN ticket_orders o ON o.record_id = r.id', [$this->compra('ana@example.com', 'Ana')]);

        $res = EnviosHandler::index($this->db, $this->post($this->pedidoDeEnvio([
            'seleccion' => ['modo' => 'emails', 'emails' => ['intruso@example.com']],
        ]), $this->user()));

        $this->assertError(400, $res, 'No queda nadie');
        $this->assertNoWrites();
    }

    public function testElTopeEsDe5000PorEnvio()
    {
        $this->puedeGestionar();
        $this->conCuenta();
        $compras = [];
        for ($i = 0; $i <= 5000; $i++) {
            $compras[] = $this->compra("c$i@example.com", "C$i");
        }
        $this->db->onSelect('INNER JOIN ticket_orders o ON o.record_id = r.id', $compras);

        $res = EnviosHandler::index($this->db, $this->post($this->pedidoDeEnvio(), $this->user()));

        $this->assertError(400, $res, 'tope es de 5000');
        $this->assertNoWrites();
    }

    public function testUnMensajeVacioNoSeManda()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $this->assertError(400, EnviosHandler::index($this->db, $this->post($this->pedidoDeEnvio(['mensaje' => ['asunto' => 'Hola']]), $this->user())));
        $this->assertNoWrites();
    }

    public function testSinSeleccionEsUnError()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $this->assertError(400, EnviosHandler::index($this->db, $this->post($this->pedidoDeEnvio(['seleccion' => null]), $this->user())));
    }

    // ---------------------------------------------------- ver y controlar

    public function testListaLosEnviosDeLaPagina()
    {
        $this->puedeGestionar();
        $this->db->onSelect('FROM envios e', [$this->envio()]);

        $res = EnviosHandler::index($this->db, $this->get(['page_id' => 5], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(['id' => 9, 'mensaje_nombre' => 'Aviso', 'asunto' => 'Hola', 'estado' => 'en_curso', 'motivo_pausa' => null,
            'total' => 2, 'enviados' => 1, 'fallidos' => 0, 'pendientes' => 1, 'omitidos' => 0,
            'creado_en' => '2026-10-22 10:00:00', 'terminado_en' => null], $res->body['envios'][0]);
        $this->assertSame([5], $this->db->paramsFor('FROM envios e'));
    }

    public function testElDetalleMuestraQuienesFallaronYPorQue()
    {
        $this->puedeGestionar();
        $this->db->onSelect('FROM envios e', [$this->envio()]);
        $this->db->onSelect("estado IN ('fallido', 'omitido')", [['email' => 'x@example.com', 'nombre' => 'X', 'estado' => 'fallido', 'error' => 'Buzón lleno', 'enviado_en' => null]]);

        $res = EnviosHandler::index($this->db, $this->get(['page_id' => 5, 'id' => 9], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame('Buzón lleno', $res->body['problemas'][0]['error']);
        $this->assertSame([9, 5], $this->db->paramsFor('FROM envios e'), 'el envío tiene que ser de esta página');
    }

    public function testUnEnvioDeOtraPaginaNoSeVeNiSeToca()
    {
        $this->puedeGestionar();
        $this->assertError(404, EnviosHandler::index($this->db, $this->get(['page_id' => 5, 'id' => 9], $this->user())));

        $this->puedeGestionar();
        $this->assertError(404, EnviosHandler::index($this->db, $this->put(['page_id' => 5, 'id' => 9, 'accion' => 'cancelar'], $this->user())));
        $this->assertNoWrites();
    }

    public function testPausaUnEnvio()
    {
        $this->puedeGestionar();
        $this->db->onSelect('FROM envios e', [$this->envio()]);
        $this->db->onSelect('FROM envios e', [$this->envio(['estado' => 'pausado'])]);

        $res = EnviosHandler::index($this->db, $this->put(['page_id' => 5, 'id' => 9, 'accion' => 'pausar'], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame('pausado', $res->body['envio']['estado']);
        $this->assertSame([9], $this->db->paramsFor("UPDATE envios SET estado = 'pausado'"));
    }

    public function testUnaAccionQueNoCorrespondeDa409()
    {
        $this->puedeGestionar();
        $this->db->onSelect('FROM envios e', [$this->envio(['estado' => 'terminado'])]);

        $this->assertError(409, EnviosHandler::index($this->db, $this->put(['page_id' => 5, 'id' => 9, 'accion' => 'reanudar'], $this->user())));
        $this->assertNoWrites();
    }

    // -------------------------------------------------------------- baja

    public function testLaBajaEsPublicaYDiceDeQuePaginaEs()
    {
        $this->db->onSelect('SELECT title FROM pages', [['title' => 'La Sala']]);

        $res = EnviosHandler::baja($this->db, $this->get(['t' => BajasDeCorreo::token(5, 'ana@example.com')]));

        $this->assertStatus(200, $res);
        $this->assertSame(['pagina' => 'La Sala', 'email' => 'ana@example.com', 'de_baja' => false], $res->body);
        $this->assertNoWrites();
    }

    public function testDarseDeBajaConUnClic()
    {
        $this->db->onSelect('SELECT title FROM pages', [['title' => 'La Sala']]);

        // Como lo manda Gmail con List-Unsubscribe-Post: el token en la URL.
        $res = EnviosHandler::baja($this->db, $this->post([], null, ['t' => BajasDeCorreo::token(5, 'ana@example.com')]));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['de_baja']);
        $this->assertSame([5, 'ana@example.com'], $this->db->paramsFor('INSERT IGNORE INTO bajas_de_correo'));
    }

    public function testLaBajaTambienAceptaElTokenEnElCuerpo()
    {
        $this->db->onSelect('SELECT title FROM pages', [['title' => 'La Sala']]);

        $this->assertStatus(200, EnviosHandler::baja($this->db, $this->post(['t' => BajasDeCorreo::token(5, 'ana@example.com')])));
        $this->assertTrue($this->db->ran('INSERT IGNORE INTO bajas_de_correo'));
    }

    public function testUnTokenInvalidoNoDaDeBajaANadie()
    {
        $this->assertError(404, EnviosHandler::baja($this->db, $this->post([], null, ['t' => 'NTpvdHJvQGV4YW1wbGUuY29t.firma'])));
        $this->assertError(404, EnviosHandler::baja($this->db, $this->get([])));
        $this->assertError(405, EnviosHandler::baja($this->db, $this->delete(['t' => 'x'])));
        $this->assertNoWrites();
    }
}
