<?php

namespace Tests\Unit\Handlers;

use PushHandler;
use Request;
use Tests\Support\HandlerTestCase;

class PushHandlerTest extends HandlerTestCase
{
    const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) Chrome/126.0 Mobile Safari/537.36';
    const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1';

    /** Petición con User-Agent, que es de donde salen plataforma y marca. */
    private function conUA($method, array $body, $ua, $user = null)
    {
        return new Request($method, $body, [], $user, [], [
            'Authorization' => 'Bearer tok',
            'User-Agent' => $ua,
        ]);
    }

    private function suscripcion(array $overrides = [])
    {
        return array_merge([
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'clave-p', 'auth' => 'clave-a'],
        ], $overrides);
    }

    // ================================================================= vapid

    public function testVapidDevuelveLaClavePublica()
    {
        $res = PushHandler::vapid($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertSame(VAPID_PUBLIC_KEY, $res->body['public_key']);
    }

    public function testVapidNoExigeSesion()
    {
        // El service worker la pide al renovar la suscripción, cuando no tiene
        // el token del usuario.
        $res = PushHandler::vapid($this->db, new Request('GET'));

        $this->assertStatus(200, $res);
    }

    public function testVapidInformaSiElServidorPuedeEnviar()
    {
        $res = PushHandler::vapid($this->db, $this->get());

        $this->assertArrayHasKey('disponible', $res->body);
        $this->assertIsBool($res->body['disponible']);
    }

    public function testVapidRechazaOtrosMetodos()
    {
        $this->assertError(405, PushHandler::vapid($this->db, $this->post()), 'Method not allowed');
    }

    // ============================================================= subscribe

    public function testSubscribeExigeSesion()
    {
        $res = PushHandler::subscribe($this->db, $this->post(['endpoint' => 'x']));

        $this->assertError(401, $res, 'Unauthorized');
        $this->assertNoWrites();
    }

    /**
     * @dataProvider suscripcionesIncompletas
     */
    public function testSubscribeExigeEndpointYClaves($cuerpo)
    {
        $res = PushHandler::subscribe($this->db, $this->post($cuerpo, $this->user()));

        $this->assertError(400, $res, 'Datos de suscripción incompletos');
        $this->assertNoWrites();
    }

    public function suscripcionesIncompletas()
    {
        return [
            'vacío' => [[]],
            'sin endpoint' => [['keys' => ['p256dh' => 'a', 'auth' => 'b']]],
            'sin claves' => [['endpoint' => 'https://push.example/abc']],
            'sin p256dh' => [['endpoint' => 'https://push.example/abc', 'keys' => ['auth' => 'b']]],
            'sin auth' => [['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'a']]],
        ];
    }

    public function testSubscribeGuardaLaSuscripcion()
    {
        $req = $this->conUA('POST', $this->suscripcion(), self::ANDROID, $this->user(9));

        $res = PushHandler::subscribe($this->db, $req);

        $this->assertStatus(200, $res);
        $params = $this->db->paramsFor('INSERT INTO push_subscriptions');

        $this->assertSame(9, $params[0], 'user_id');
        $this->assertSame('https://push.example/abc', $params[1]);
        $this->assertSame('clave-p', $params[2]);
        $this->assertSame('clave-a', $params[3]);
    }

    public function testSubscribeAceptaLaSuscripcionEnvueltaComoLaSerializaElNavegador()
    {
        $req = $this->conUA('POST', ['suscripcion' => $this->suscripcion()], self::ANDROID, $this->user(9));

        $res = PushHandler::subscribe($this->db, $req);

        $this->assertStatus(200, $res);
        $this->assertSame('https://push.example/abc', $this->db->paramsFor('INSERT INTO push_subscriptions')[1]);
    }

    public function testSubscribeDeduceLaPlataformaYLaMarca()
    {
        $req = $this->conUA('POST', $this->suscripcion(), self::ANDROID, $this->user(9));

        $res = PushHandler::subscribe($this->db, $req);

        $params = $this->db->paramsFor('INSERT INTO push_subscriptions');

        $this->assertSame('Android', $params[4]);
        $this->assertSame('Samsung', $params[5], 'para poder dar la guía de batería que corresponde');
        $this->assertSame('Android', $res->body['platform']);
    }

    public function testSubscribeReconoceIOS()
    {
        $req = $this->conUA('POST', $this->suscripcion(), self::IPHONE, $this->user(9));

        PushHandler::subscribe($this->db, $req);

        $this->assertSame('iOS', $this->db->paramsFor('INSERT INTO push_subscriptions')[4]);
    }

    public function testSubscribeGuardaSiEstabaInstaladaComoApp()
    {
        $cuerpo = $this->suscripcion();
        $cuerpo['standalone'] = true;
        $req = $this->conUA('POST', $cuerpo, self::IPHONE, $this->user(9));

        PushHandler::subscribe($this->db, $req);

        $this->assertSame(1, $this->db->paramsFor('INSERT INTO push_subscriptions')[6]);
    }

    /** Reinstalar o recargar no debe dejar dos filas del mismo dispositivo. */
    public function testSubscribeActualizaEnLugarDeDuplicar()
    {
        $req = $this->conUA('POST', $this->suscripcion(), self::ANDROID, $this->user(9));

        PushHandler::subscribe($this->db, $req);

        $sql = $this->db->callsFor('INSERT INTO push_subscriptions')[0]['sql'];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
    }

    public function testUnsubscribeExigeEndpoint()
    {
        $req = new Request('DELETE', [], [], $this->user(), [], ['Authorization' => 'Bearer x']);

        $this->assertError(400, PushHandler::subscribe($this->db, $req), 'Endpoint requerido');
    }

    public function testUnsubscribeBorraSoloLaDelUsuario()
    {
        $req = new Request('DELETE', ['endpoint' => 'https://push.example/abc'], [], $this->user(9), [], []);

        $res = PushHandler::subscribe($this->db, $req);

        $this->assertStatus(200, $res);
        $this->assertSame([9, 'https://push.example/abc'], $this->db->paramsFor('DELETE FROM push_subscriptions'));
    }

    public function testSubscribeRechazaMetodosNoSoportados()
    {
        $req = new Request('PUT', [], [], $this->user(), [], []);

        $this->assertError(405, PushHandler::subscribe($this->db, $req), 'Method not allowed');
    }

    // =================================================================== ack

    public function testAckExigeId()
    {
        $this->assertError(400, PushHandler::ack($this->db, $this->post([])), 'id requerido');
    }

    public function testAckNoExigeSesion()
    {
        // El worker puede despertarse sin token; el envio_id hace de credencial.
        $this->db->onWrite('UPDATE push_deliveries', 1);

        $res = PushHandler::ack($this->db, new Request('POST', ['id' => 'abc1234567']));

        $this->assertStatus(200, $res);
    }

    public function testAckRegistraLatenciaYRecepcion()
    {
        $this->db->onWrite('UPDATE push_deliveries', 1);

        PushHandler::ack($this->db, $this->post([
            'id' => 'abc1234567',
            'recibidoEn' => 1760000002300,
            'latenciaMs' => 2300,
        ]));

        $this->assertSame([1760000002300, 2300, 'abc1234567'], $this->db->paramsFor('UPDATE push_deliveries'));
    }

    public function testAckSoloAvanzaDeEnviadoAConfirmado()
    {
        $this->db->onWrite('UPDATE push_deliveries', 1);

        PushHandler::ack($this->db, $this->post(['id' => 'abc1234567']));

        $sql = $this->db->callsFor('UPDATE push_deliveries')[0]['sql'];

        // Un ack repetido no debe pisar la latencia ni resucitar un fallido.
        $this->assertStringContainsString("estado = 'enviado'", $sql);
        $this->assertStringContainsString('COALESCE', $sql);
    }

    public function testAckToleraValoresNoNumericos()
    {
        $this->db->onWrite('UPDATE push_deliveries', 1);

        PushHandler::ack($this->db, $this->post([
            'id' => 'abc1234567',
            'recibidoEn' => 'no-es-un-numero',
            'latenciaMs' => null,
        ]));

        $params = $this->db->paramsFor('UPDATE push_deliveries');
        $this->assertNull($params[0]);
        $this->assertNull($params[1]);
    }

    public function testAckRechazaOtrosMetodos()
    {
        $this->assertError(405, PushHandler::ack($this->db, $this->get()), 'Method not allowed');
    }

    // ============================================================== métricas

    public function testMetricasExigeSesion()
    {
        $this->assertError(401, PushHandler::metricas($this->db, $this->get()), 'Unauthorized');
    }

    public function testMetricasSegmentaPorPlataforma()
    {
        // Un 100% global puede esconder un 0% en iOS: por eso se segmenta.
        $this->db->onSelect('FROM push_deliveries', [
            ['plataforma' => 'Android', 'total' => '10', 'enviadas' => '10', 'confirmadas' => '10', 'fallidas' => '0', 'latencia_media_ms' => '2300'],
            ['plataforma' => 'iOS', 'total' => '5', 'enviadas' => '5', 'confirmadas' => '0', 'fallidas' => '0', 'latencia_media_ms' => null],
        ]);

        $res = PushHandler::metricas($this->db, $this->get([], $this->user()));

        $this->assertStatus(200, $res);
        $android = $res->body['plataformas'][0];
        $ios = $res->body['plataformas'][1];

        $this->assertSame(1.0, $android['tasa_entrega']);
        $this->assertSame(2300, $android['latencia_media_ms']);
        $this->assertSame(0.0, $ios['tasa_entrega'], 'iOS al 0% queda a la vista');
        $this->assertNull($ios['latencia_media_ms']);
    }

    public function testMetricasNoDivideSiNoHuboEnvios()
    {
        $this->db->onSelect('FROM push_deliveries', [
            ['plataforma' => 'iOS', 'total' => '0', 'enviadas' => '0', 'confirmadas' => '0', 'fallidas' => '0', 'latencia_media_ms' => null],
        ]);

        $res = PushHandler::metricas($this->db, $this->get([], $this->user()));

        $this->assertNull($res->body['plataformas'][0]['tasa_entrega']);
    }
}
