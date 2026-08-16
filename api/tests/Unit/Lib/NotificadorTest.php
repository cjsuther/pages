<?php

namespace Tests\Unit\Lib;

use Notificador;
use Tests\Support\FakePushSender;
use Tests\Support\HandlerTestCase;

class NotificadorTest extends HandlerTestCase
{
    private function evento(array $overrides = [])
    {
        return array_merge([
            'id' => 100,
            'title' => 'Mi Evento',
            'event_date' => '2026-12-01',
            'event_latitude' => '-34.6037',
            'event_longitude' => '-58.3816',
            'page_id' => 5,
            'page_title' => 'Mi Página',
            'url_slug' => 'mi-pagina',
            'owner_id' => 9,
        ], $overrides);
    }

    private function seguidor(array $overrides = [])
    {
        return array_merge([
            'user_id' => 11,
            'notify_all_events' => 1,
            'max_distance_km' => 50,
            'location_latitude' => '-34.6037',
            'location_longitude' => '-58.3816',
        ], $overrides);
    }

    private function hayEventoConSeguidores(array $seguidores, $insertadas = 1)
    {
        $this->db->onSelect('FROM links l INNER JOIN link_groups lg', [$this->evento()]);
        $this->db->onSelect('FROM page_followers pf INNER JOIN users u', $seguidores);
        $this->db->onWrite('INSERT IGNORE INTO notifications', $insertadas);
    }

    // ------------------------------------------------------ clave de dedupe

    public function testLaClaveDeDeduplicacionIdentificaEventoYUsuario()
    {
        $this->assertSame('evento:100:11', Notificador::claveEvento(100, 11));
    }

    public function testLaClaveNormalizaLosTiposParaQueNoSeDupliquen()
    {
        // La API devuelve los ids como string; si no se normalizaran, "100" y
        // 100 generarían dos claves distintas y dos notificaciones.
        $this->assertSame(Notificador::claveEvento(100, 11), Notificador::claveEvento('100', '11'));
    }

    // ---------------------------------------------------------- crearUnaVez

    public function testCrearUnaVezUsaInsertIgnore()
    {
        $this->db->onWrite('INSERT IGNORE INTO notifications', 1);

        Notificador::crearUnaVez($this->db, [
            'user_id' => 11, 'page_id' => 5, 'link_id' => 100,
            'title' => 'T', 'message' => 'M',
            'type' => Notificador::TIPO_EVENTO, 'dedupe_key' => 'evento:100:11',
        ]);

        $sql = $this->db->callsFor('INSERT IGNORE INTO notifications')[0]['sql'];

        // No debe consultar antes de insertar: entre consulta e inserción se
        // cuela otro proceso y aparecen duplicados.
        $this->assertStringContainsString('INSERT IGNORE', $sql);
        $this->assertSame(0, $this->db->countCalls('SELECT id FROM notifications'));
    }

    public function testCrearUnaVezDevuelveUnoSiInserto()
    {
        $this->db->onWrite('INSERT IGNORE INTO notifications', 1);

        $this->assertSame(1, Notificador::crearUnaVez($this->db, $this->datosDeNotificacion()));
    }

    public function testCrearUnaVezDevuelveCeroSiYaExistia()
    {
        $this->db->onWrite('INSERT IGNORE INTO notifications', 0);

        $this->assertSame(0, Notificador::crearUnaVez($this->db, $this->datosDeNotificacion()));
    }

    // ---------------------------------------------------- avisarEventoNuevo

    public function testNoAvisaSiElLinkNoEsUnEvento()
    {
        $creadas = Notificador::avisarEventoNuevo($this->db, 100);

        $this->assertSame(0, $creadas);
        $this->assertNoWrites();
    }

    public function testAvisaAlSeguidor()
    {
        $this->hayEventoConSeguidores([$this->seguidor()]);

        $this->assertSame(1, Notificador::avisarEventoNuevo($this->db, 100));
    }

    public function testLaNotificacionLlevaLaClaveDeDeduplicacion()
    {
        $this->hayEventoConSeguidores([$this->seguidor(['user_id' => 11])]);

        Notificador::avisarEventoNuevo($this->db, 100);

        $params = $this->db->paramsFor('INSERT IGNORE INTO notifications');

        $this->assertSame(11, $params[0]);
        $this->assertSame(Notificador::TIPO_EVENTO, $params[5]);
        $this->assertSame('evento:100:11', $params[6]);
    }

    public function testNoSeAvisaAlDuenoDeLaPagina()
    {
        // El dueño suele seguir su propia página; no tiene sentido avisarle de
        // lo que acaba de publicar él.
        $this->hayEventoConSeguidores([$this->seguidor(['user_id' => 9])]);

        $this->assertSame(0, Notificador::avisarEventoNuevo($this->db, 100));
        $this->assertSame(0, $this->db->countCalls('INSERT IGNORE INTO notifications'));
    }

    public function testRespetaLaPreferenciaDeDistancia()
    {
        // Seguidor en Córdoba, evento en Buenos Aires, radio de 50 km.
        $this->hayEventoConSeguidores([$this->seguidor([
            'notify_all_events' => 0,
            'max_distance_km' => 50,
            'location_latitude' => '-31.4201',
            'location_longitude' => '-64.1888',
        ])]);

        $this->assertSame(0, Notificador::avisarEventoNuevo($this->db, 100));
    }

    public function testAvisaAlSeguidorCercano()
    {
        $this->hayEventoConSeguidores([$this->seguidor([
            'notify_all_events' => 0,
            'max_distance_km' => 50,
            'location_latitude' => '-34.5875',
            'location_longitude' => '-58.3974',
        ])]);

        $this->assertSame(1, Notificador::avisarEventoNuevo($this->db, 100));
    }

    public function testVolverALlamarNoDuplica()
    {
        // Segunda pasada: el índice único rechaza la inserción y rowCount es 0.
        $this->hayEventoConSeguidores([$this->seguidor()], 0);

        $this->assertSame(0, Notificador::avisarEventoNuevo($this->db, 100));
    }

    // -------------------------------------------------------- cola de envío

    public function testEncolarPendientesInsertaUnEnvioPorParPendiente()
    {
        $this->db->onSelect('LEFT JOIN push_deliveries d', [
            ['notification_id' => 7, 'subscription_id' => 3, 'platform' => 'Android'],
            ['notification_id' => 7, 'subscription_id' => 4, 'platform' => 'iOS'],
        ]);
        $this->db->onWrite('INSERT IGNORE INTO push_deliveries', 1);
        $this->db->onWrite('INSERT IGNORE INTO push_deliveries', 1);

        $this->assertSame(2, Notificador::encolarPendientes($this->db));
    }

    public function testEncolarPendientesNoConsultaSiNoHayNadaPendiente()
    {
        $this->assertSame(0, Notificador::encolarPendientes($this->db));
        $this->assertSame(0, $this->db->countCalls('INSERT IGNORE INTO push_deliveries'));
    }

    /** El índice único es lo que garantiza el "una vez", no el SELECT previo. */
    public function testEncolarPendientesUsaInsertIgnore()
    {
        $this->db->onSelect('LEFT JOIN push_deliveries d', [
            ['notification_id' => 7, 'subscription_id' => 3, 'platform' => 'Android'],
        ]);
        $this->db->onWrite('INSERT IGNORE INTO push_deliveries', 1);

        Notificador::encolarPendientes($this->db);

        $sql = $this->db->callsFor('INSERT IGNORE INTO push_deliveries')[0]['sql'];
        $this->assertStringContainsString('INSERT IGNORE', $sql);
    }

    public function testElIdentificadorDeEnvioTieneDiezCaracteres()
    {
        // La columna envio_id es CHAR(10).
        $this->db->onSelect('LEFT JOIN push_deliveries d', [
            ['notification_id' => 7, 'subscription_id' => 3, 'platform' => 'Android'],
        ]);
        $this->db->onWrite('INSERT IGNORE INTO push_deliveries', 1);

        Notificador::encolarPendientes($this->db);

        $envioId = $this->db->paramsFor('INSERT IGNORE INTO push_deliveries')[2];
        $this->assertSame(10, strlen($envioId));
    }

    public function testProcesarColaSinPendientesNoEnvia()
    {
        $sender = new FakePushSender();

        $resumen = Notificador::procesarCola($this->db, $sender);

        $this->assertSame(0, $resumen['total']);
        $this->assertSame([], $sender->encolados);
    }

    public function testProcesarColaEnviaYMarcaComoEnviado()
    {
        $this->hayUnEnvioPendiente();
        $sender = new FakePushSender();

        $resumen = Notificador::procesarCola($this->db, $sender);

        $this->assertSame(1, $resumen['enviados']);
        $this->assertSame(0, $resumen['fallidos']);
        $this->assertTrue($this->db->ran("UPDATE push_deliveries SET estado='enviado'"));
    }

    public function testElPayloadLlevaLoQueNecesitaElServiceWorker()
    {
        $this->hayUnEnvioPendiente();
        $sender = new FakePushSender();

        Notificador::procesarCola($this->db, $sender);

        $payload = $sender->payloadDe('https://push.example/abc');

        $this->assertSame('Nuevo evento: Mi Evento', $payload['titulo']);
        $this->assertSame('abc1234567', $payload['id'], 'id de correlación para el ack');
        $this->assertIsInt($payload['enviadoEn'], 'marca de tiempo para calcular latencia');
        $this->assertSame('/evento/100', $payload['url']);
    }

    public function testUnaSuscripcionExpiradaSeBorra()
    {
        $this->hayUnEnvioPendiente();
        $sender = (new FakePushSender())
            ->resultado('https://push.example/abc', false, true, '410 Gone');

        $resumen = Notificador::procesarCola($this->db, $sender);

        $this->assertSame(1, $resumen['expiradas']);
        $this->assertSame(
            ['https://push.example/abc'],
            $this->db->paramsFor('DELETE FROM push_subscriptions')
        );
    }

    public function testUnFalloNoBorraLaSuscripcion()
    {
        $this->hayUnEnvioPendiente();
        $sender = (new FakePushSender())
            ->resultado('https://push.example/abc', false, false, '500 Internal Server Error');

        $resumen = Notificador::procesarCola($this->db, $sender);

        $this->assertSame(1, $resumen['fallidos']);
        $this->assertSame(0, $this->db->countCalls('DELETE FROM push_subscriptions'));
        $this->assertTrue($this->db->ran("UPDATE push_deliveries SET estado='fallido'"));
    }

    public function testSoloTomaEnviosPendientesYConReintentosDisponibles()
    {
        Notificador::procesarCola($this->db, new FakePushSender());

        $sql = $this->db->callsFor('FROM push_deliveries d')[0]['sql'];

        $this->assertStringContainsString("d.estado = 'pendiente'", $sql);
        $this->assertStringContainsString('d.intentos < 3', $sql);
    }

    // ------------------------------------------------------------ ayudantes

    private function datosDeNotificacion()
    {
        return [
            'user_id' => 11, 'page_id' => 5, 'link_id' => 100,
            'title' => 'T', 'message' => 'M',
            'type' => Notificador::TIPO_EVENTO, 'dedupe_key' => 'evento:100:11',
        ];
    }

    private function hayUnEnvioPendiente()
    {
        $this->db->onSelect('FROM push_deliveries d', [[
            'id' => 1,
            'envio_id' => 'abc1234567',
            'notification_id' => 7,
            'subscription_id' => 3,
            'endpoint' => 'https://push.example/abc',
            'p256dh_key' => 'clave-p',
            'auth_key' => 'clave-a',
            'platform' => 'Android',
            'title' => 'Nuevo evento: Mi Evento',
            'message' => 'La página Mi Página publicó un evento',
            'page_id' => 5,
            'link_id' => 100,
            'url_slug' => 'mi-pagina',
        ]]);
    }
}
