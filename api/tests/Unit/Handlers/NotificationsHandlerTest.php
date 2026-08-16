<?php

namespace Tests\Unit\Handlers;

use NotificationsHandler;
use Request;
use Tests\Support\HandlerTestCase;

class NotificationsHandlerTest extends HandlerTestCase
{
    // =========================================================== autenticación

    public function testIndexExigeCabeceraAuthorization()
    {
        $res = NotificationsHandler::index($this->db, new Request('GET'));

        $this->assertError(401, $res, 'Token no proporcionado');
    }

    public function testIndexUsaSuPropioMensajeParaTokenInvalido()
    {
        // Este endpoint dice "Token inválido o expirado" en lugar de
        // "Unauthorized"; el frontend lo muestra tal cual.
        $res = NotificationsHandler::index($this->db, $this->conTokenInvalido('GET'));

        $this->assertError(401, $res, 'Token inválido o expirado');
    }

    public function testIndexRechazaMetodoNoSoportado()
    {
        $res = NotificationsHandler::index($this->db, $this->post([], $this->user()));

        $this->assertError(405, $res, 'Método no permitido');
    }

    // ================================================================= listar

    public function testListarDevuelveLasNotificacionesConTiposReales()
    {
        $this->db->onSelect('FROM notifications n INNER JOIN pages p', [[
            'id' => '5', 'title' => 'Nuevo evento', 'is_read' => '0',
            'page_id' => '3', 'link_id' => '100',
        ]]);
        $this->db->onSelect('COUNT(*) as unread_count', [['unread_count' => '4']]);

        $res = NotificationsHandler::index($this->db, $this->get([], $this->user(9)));

        $this->assertStatus(200, $res);

        $n = $res->body['notifications'][0];
        $this->assertSame(5, $n['id']);
        $this->assertFalse($n['is_read']);
        $this->assertSame(3, $n['page_id']);
        $this->assertSame(100, $n['link_id']);
        $this->assertSame(4, $res->body['unread_count']);
        $this->assertSame(1, $res->body['total']);
    }

    public function testListarUsaLimitYOffsetPorDefecto()
    {
        NotificationsHandler::index($this->db, $this->get([], $this->user(9)));

        $this->assertSame([9, 50, 0], $this->db->paramsFor('FROM notifications n INNER JOIN pages p'));
    }

    public function testListarRespetaLimitYOffset()
    {
        NotificationsHandler::index($this->db, $this->get([
            'limit' => '10', 'offset' => '20',
        ], $this->user(9)));

        $this->assertSame([9, 10, 20], $this->db->paramsFor('FROM notifications n INNER JOIN pages p'));
    }

    public function testListarConvierteLimitYOffsetAEntero()
    {
        // Llegan del query string como texto: si se pasaran tal cual a un
        // LIMIT sin conversión sería una vía de inyección.
        NotificationsHandler::index($this->db, $this->get([
            'limit' => '10; DROP TABLE notifications', 'offset' => 'abc',
        ], $this->user(9)));

        $params = $this->db->paramsFor('FROM notifications n INNER JOIN pages p');

        $this->assertSame(10, $params[1]);
        $this->assertSame(0, $params[2]);
    }

    public function testListarFiltraNoLeidasCuandoSePide()
    {
        NotificationsHandler::index($this->db, $this->get(['unread_only' => 'true'], $this->user(9)));

        $sql = $this->db->callsFor('FROM notifications n INNER JOIN pages p')[0]['sql'];

        $this->assertStringContainsString('n.is_read = 0', $sql);
    }

    public function testListarNoFiltraSiUnreadOnlyNoEsExactamenteTrue()
    {
        NotificationsHandler::index($this->db, $this->get(['unread_only' => '1'], $this->user(9)));

        $sql = $this->db->callsFor('FROM notifications n INNER JOIN pages p')[0]['sql'];

        $this->assertStringNotContainsString('n.is_read = 0', $sql);
    }

    public function testListarDevuelveCeroNoLeidasSiNoHayFila()
    {
        $res = NotificationsHandler::index($this->db, $this->get([], $this->user(9)));

        $this->assertSame(0, $res->body['unread_count']);
    }

    // ========================================================== marcar leídas

    public function testMarcarTodasComoLeidas()
    {
        $this->db->onWrite('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', 7);

        $res = NotificationsHandler::index($this->db, $this->put(
            ['mark_all_as_read' => true],
            $this->user(9)
        ));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['success']);
        $this->assertSame(7, $res->body['updated_count']);
        $this->assertSame([9], $this->db->paramsFor('AND is_read = 0'));
    }

    public function testMarkAllDebeSerExactamenteTrue()
    {
        // Con "true" (string) cae a la rama de IDs, que exige la lista.
        $res = NotificationsHandler::index($this->db, $this->put(
            ['mark_all_as_read' => 'true'],
            $this->user(9)
        ));

        $this->assertError(400, $res, 'IDs de notificaciones requeridos');
    }

    /**
     * @dataProvider idsInvalidos
     */
    public function testMarcarPorIdsExigeUnArray($cuerpo)
    {
        $res = NotificationsHandler::index($this->db, $this->put($cuerpo, $this->user(9)));

        $this->assertError(400, $res, 'IDs de notificaciones requeridos');
        $this->assertNoWrites();
    }

    public function idsInvalidos()
    {
        return [
            'sin campo' => [[]],
            'array vacío' => [['notification_ids' => []]],
            'string' => [['notification_ids' => '1,2']],
            'null' => [['notification_ids' => null]],
        ];
    }

    /**
     * El endpoint original preparaba esta consulta con una variable $conn que
     * no existía, así que marcar notificaciones puntuales siempre fallaba.
     */
    public function testMarcarPorIdsFunciona()
    {
        $this->db->onWrite('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN', 2);

        $res = NotificationsHandler::index($this->db, $this->put(
            ['notification_ids' => [5, 8]],
            $this->user(9)
        ));

        $this->assertStatus(200, $res);
        $this->assertSame(2, $res->body['updated_count']);
        $this->assertSame([9, 5, 8], $this->db->paramsFor('AND id IN'));
    }

    public function testMarcarPorIdsGeneraUnPlaceholderPorId()
    {
        $this->db->onWrite('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN', 3);

        NotificationsHandler::index($this->db, $this->put(
            ['notification_ids' => [1, 2, 3]],
            $this->user(9)
        ));

        $sql = $this->db->callsFor('AND id IN')[0]['sql'];

        $this->assertStringContainsString('id IN (?,?,?)', $sql);
    }

    public function testMarcarPorIdsSoloAfectaAlUsuarioDeLaSesion()
    {
        $this->db->onWrite('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN', 1);

        NotificationsHandler::index($this->db, $this->put(
            ['notification_ids' => [5]],
            $this->user(9)
        ));

        $sql = $this->db->callsFor('AND id IN')[0]['sql'];

        $this->assertStringContainsString('user_id = ?', $sql);
        $this->assertSame(9, $this->db->paramsFor('AND id IN')[0]);
    }

    // =============================================================== eliminar

    public function testEliminarExigeIds()
    {
        $res = NotificationsHandler::index($this->db, $this->delete([], $this->user(9)));

        $this->assertError(400, $res, 'IDs de notificaciones requeridos');
        $this->assertNoWrites();
    }

    /** Misma corrección de $conn que en el marcado por IDs. */
    public function testEliminarFunciona()
    {
        $this->db->onWrite('DELETE FROM notifications WHERE user_id = ? AND id IN', 2);

        $res = NotificationsHandler::index($this->db, $this->delete(['ids' => '5,8'], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertSame(2, $res->body['deleted_count']);
        $this->assertSame([9, '5', '8'], $this->db->paramsFor('DELETE FROM notifications'));
    }

    public function testEliminarSoloAfectaAlUsuarioDeLaSesion()
    {
        $this->db->onWrite('DELETE FROM notifications WHERE user_id = ? AND id IN', 1);

        NotificationsHandler::index($this->db, $this->delete(['ids' => '5'], $this->user(9)));

        $this->assertStringContainsString('user_id = ?', $this->db->callsFor('DELETE FROM notifications')[0]['sql']);
    }

    // ============================================================== subscribe

    public function testSubscribeExigeCabeceraAuthorization()
    {
        $res = NotificationsHandler::subscribe($this->db, new Request('GET'));

        $this->assertError(401, $res, 'Token no proporcionado');
    }

    public function testSubscribeDevuelveLaClaveVapidPublica()
    {
        $res = NotificationsHandler::subscribe($this->db, $this->get([], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(VAPID_PUBLIC_KEY, $res->body['public_key']);
    }

    /**
     * @dataProvider suscripcionesIncompletas
     */
    public function testRegistrarSuscripcionExigeTodosLosDatos($cuerpo)
    {
        $res = NotificationsHandler::subscribe($this->db, $this->post($cuerpo, $this->user()));

        $this->assertError(400, $res, 'Datos de suscripción incompletos');
        $this->assertNoWrites();
    }

    public function suscripcionesIncompletas()
    {
        return [
            'vacío' => [[]],
            'sin endpoint' => [['keys' => ['p256dh' => 'a', 'auth' => 'b']]],
            'sin claves' => [['endpoint' => 'https://push']],
            'sin p256dh' => [['endpoint' => 'https://push', 'keys' => ['auth' => 'b']]],
            'sin auth' => [['endpoint' => 'https://push', 'keys' => ['p256dh' => 'a']]],
        ];
    }

    public function testRegistrarSuscripcion()
    {
        $res = NotificationsHandler::subscribe($this->db, $this->post([
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'clave-p', 'auth' => 'clave-a'],
        ], $this->user(9)));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['success']);
        $this->assertSame(
            [9, 'https://push.example/abc', 'clave-p', 'clave-a'],
            $this->db->paramsFor('INSERT INTO push_subscriptions')
        );
    }

    public function testRegistrarSuscripcionDosVecesActualiza()
    {
        NotificationsHandler::subscribe($this->db, $this->post([
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'a', 'auth' => 'b'],
        ], $this->user(9)));

        $sql = $this->db->callsFor('INSERT INTO push_subscriptions')[0]['sql'];

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    public function testBorrarSuscripcionExigeEndpoint()
    {
        $res = NotificationsHandler::subscribe($this->db, new Request(
            'DELETE', [], [], $this->user(), [], ['Authorization' => 'Bearer x']
        ));

        $this->assertError(400, $res, 'Endpoint requerido');
    }

    public function testBorrarSuscripcion()
    {
        $req = new Request(
            'DELETE',
            ['endpoint' => 'https://push.example/abc'],
            [],
            $this->user(9),
            [],
            ['Authorization' => 'Bearer x']
        );

        $res = NotificationsHandler::subscribe($this->db, $req);

        $this->assertStatus(200, $res);
        $this->assertSame(
            [9, 'https://push.example/abc'],
            $this->db->paramsFor('DELETE FROM push_subscriptions')
        );
    }

    public function testSubscribeRechazaMetodoNoSoportado()
    {
        $res = NotificationsHandler::subscribe($this->db, $this->put([], $this->user()));

        $this->assertError(405, $res, 'Método no permitido');
    }

    // =========================================================== processDaily

    public function testProcessDailyExigeLaClaveDeCron()
    {
        $res = NotificationsHandler::processDaily($this->db, $this->get());

        $this->assertError(403, $res, 'Acceso denegado');
    }

    public function testProcessDailyRechazaClaveIncorrecta()
    {
        $res = NotificationsHandler::processDaily($this->db, $this->get(['cron_key' => 'incorrecta']));

        $this->assertError(403, $res, 'Acceso denegado');
    }

    public function testProcessDailyAceptaLaClaveCorrecta()
    {
        $res = NotificationsHandler::processDaily($this->db, $this->get(['cron_key' => CRON_SECRET_KEY]));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['success']);
    }

    public function testProcesarSinEventosNoHaceNada()
    {
        $resumen = NotificationsHandler::procesarEventosNuevos($this->db);

        $this->assertSame(0, $resumen['events_processed']);
        $this->assertSame(0, $resumen['notifications_sent']);
        $this->assertNoWrites();
    }

    public function testProcesarSoloMiraEventosFuturosYRecientes()
    {
        NotificationsHandler::procesarEventosNuevos($this->db);

        $sql = $this->db->callsFor('FROM links l INNER JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString('lg.type = "eventos"', $sql);
        $this->assertStringContainsString('l.event_date > NOW()', $sql);
        $this->assertStringContainsString('INTERVAL 24 HOUR', $sql);
    }

    public function testProcesarCreaNotificacionParaElSeguidor()
    {
        $this->unEventoConUnSeguidor(['notify_all_events' => 1]);

        $resumen = NotificationsHandler::procesarEventosNuevos($this->db);

        $this->assertSame(1, $resumen['events_processed']);
        $this->assertSame(1, $resumen['notifications_sent']);

        $params = $this->db->paramsFor('INSERT INTO notifications');
        $this->assertSame(11, $params[0], 'user_id del seguidor');
        $this->assertSame(3, $params[1], 'page_id');
        $this->assertSame(100, $params[2], 'link_id');
        $this->assertStringContainsString('Mi evento', $params[3]);
    }

    public function testNoDuplicaNotificacionesYaExistentes()
    {
        $this->unEventoConUnSeguidor(['notify_all_events' => 1]);
        $this->db->onSelect('SELECT id FROM notifications WHERE user_id = ? AND link_id = ?', [['id' => 1]]);

        $resumen = NotificationsHandler::procesarEventosNuevos($this->db);

        $this->assertSame(0, $resumen['notifications_sent']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO notifications'));
    }

    public function testNoNotificaEventoLejanoSiElSeguidorNoQuiereTodo()
    {
        $this->unEventoConUnSeguidor([
            'notify_all_events' => 0,
            'max_distance_km' => 50,
            'location_latitude' => '-31.4201',
            'location_longitude' => '-64.1888',
        ]);

        $resumen = NotificationsHandler::procesarEventosNuevos($this->db);

        $this->assertSame(0, $resumen['notifications_sent']);
    }

    public function testNotificaEventoCercano()
    {
        $this->unEventoConUnSeguidor([
            'notify_all_events' => 0,
            'max_distance_km' => 50,
            'location_latitude' => '-34.5875',
            'location_longitude' => '-58.3974',
        ]);

        $resumen = NotificationsHandler::procesarEventosNuevos($this->db);

        $this->assertSame(1, $resumen['notifications_sent']);
    }

    // ------------------------------------------------------- debeNotificar

    public function testDebeNotificarSiempreConNotifyAll()
    {
        $this->assertTrue(NotificationsHandler::debeNotificar(
            $this->evento(),
            $this->seguidor(['notify_all_events' => 1, 'max_distance_km' => 0])
        ));
    }

    public function testNoNotificaSinCoordenadasDelEvento()
    {
        $this->assertFalse(NotificationsHandler::debeNotificar(
            $this->evento(['event_latitude' => null, 'event_longitude' => null]),
            $this->seguidor(['notify_all_events' => 0])
        ));
    }

    public function testNoNotificaSinUbicacionDelSeguidor()
    {
        $this->assertFalse(NotificationsHandler::debeNotificar(
            $this->evento(),
            $this->seguidor(['notify_all_events' => 0, 'location_latitude' => null, 'location_longitude' => null])
        ));
    }

    public function testNotificaJustoEnElLimiteDelRadio()
    {
        // ~2 km de distancia, radio de 3 km.
        $this->assertTrue(NotificationsHandler::debeNotificar(
            $this->evento(),
            $this->seguidor([
                'notify_all_events' => 0,
                'max_distance_km' => 3,
                'location_latitude' => '-34.5875',
                'location_longitude' => '-58.3974',
            ])
        ));
    }

    // ---------------------------------------------------- textos del aviso

    public function testTituloDeAviso()
    {
        $this->assertSame('Nuevo evento: Mi evento', NotificationsHandler::tituloDeAviso($this->evento()));
    }

    public function testMensajeIncluyeLaFecha()
    {
        $mensaje = NotificationsHandler::mensajeDeAviso($this->evento(['event_date' => '2026-12-25']));

        $this->assertStringContainsString('Mi página', $mensaje);
        $this->assertStringContainsString('25/12/2026', $mensaje);
    }

    public function testMensajeSinFecha()
    {
        $mensaje = NotificationsHandler::mensajeDeAviso($this->evento(['event_date' => null]));

        $this->assertStringNotContainsString('para el', $mensaje);
    }

    // ------------------------------------------------------------- ayudantes

    private function evento(array $overrides = [])
    {
        return array_merge([
            'id' => 100,
            'page_id' => 3,
            'title' => 'Mi evento',
            'page_title' => 'Mi página',
            'url_slug' => 'mi-pagina',
            'event_date' => '2026-12-01',
            'event_address' => 'Alguna dirección',
            'event_latitude' => '-34.6037',
            'event_longitude' => '-58.3816',
        ], $overrides);
    }

    private function seguidor(array $overrides = [])
    {
        return array_merge([
            'user_id' => 11,
            'notify_all_events' => 0,
            'max_distance_km' => 50,
            'location_latitude' => '-34.6037',
            'location_longitude' => '-58.3816',
            'email' => 'seguidor@b.com',
        ], $overrides);
    }

    private function unEventoConUnSeguidor(array $seguidor)
    {
        $this->db->onSelect('FROM links l INNER JOIN link_groups lg', [$this->evento()]);
        $this->db->onSelect('FROM page_followers pf INNER JOIN users u', [$this->seguidor($seguidor)]);
    }
}
