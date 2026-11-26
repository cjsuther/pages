<?php

namespace Tests\Unit\Lib;

use HttpClient;
use MercadoPago;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;

/**
 * Los reembolsos contra la API de Mercado Pago, con un HttpClient doble: nunca
 * se sale a la red.
 */
class MercadoPagoDevolucionesTest extends TestCase
{
    const TOKEN = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';

    private function mp(FakeHttpClient $http)
    {
        return new MercadoPago(self::TOKEN, $http);
    }

    // ------------------------------------------------------------- cuerpo

    public function testUnReembolsoParcialMandaElMontoYLaClaveDeIdempotencia()
    {
        $http = (new FakeHttpClient())->responde('/v1/payments/123/refunds', 201, [
            'id' => 555, 'payment_id' => 123, 'amount' => 9368.81, 'status' => 'approved',
        ]);

        $r = $this->mp($http)->devolver('123', 9368.81, 'rz-dev-1-abc');

        $this->assertSame('aprobada', $r['resultado']);
        $this->assertSame('555', $r['id']);
        $this->assertSame(9368.81, $r['monto']);
        $this->assertSame(['amount' => 9368.81], $http->jsonDe('/refunds'));
        $cabeceras = $http->cabecerasDe('/refunds');
        $this->assertContains('X-Idempotency-Key: rz-dev-1-abc', $cabeceras);
        $this->assertContains('Authorization: Bearer ' . self::TOKEN, $cabeceras, 'con el token del vendedor');
    }

    /** Un reembolso total va con el cuerpo vacío como objeto: {} y no []. */
    public function testUnReembolsoTotalMandaUnObjetoVacio()
    {
        $http = (new FakeHttpClient())->responde('/refunds', 201, ['id' => 1, 'status' => 'approved']);

        $this->mp($http)->devolver('123', null, 'clave');

        $this->assertSame('{}', HttpClient::json($http->jsonDe('/refunds')));
    }

    public function testElJsonDeUnArrayVacioNoEsUnObjeto()
    {
        $this->assertSame('[]', HttpClient::json([]));
        $this->assertSame('{}', HttpClient::json(new \stdClass()));
        $this->assertSame('{"amount":10.5}', HttpClient::json(['amount' => 10.5]));
    }

    // ------------------------------------------------------------ resultados

    public function testUnRechazoTraeElCodigoYElMensajeDeMercadoPago()
    {
        $http = (new FakeHttpClient())->responde('/refunds', 400, [
            'message' => 'Not valid action', 'error' => 'bad_request', 'status' => 400,
            'cause' => [['code' => 3024, 'description' => 'Not valid action. Partial refund unsupported for this transaction']],
        ]);

        $r = $this->mp($http)->devolver('123', 100, 'clave');

        $this->assertSame('rechazada', $r['resultado']);
        $this->assertSame(400, $r['status_http']);
        $this->assertSame('3024', $r['error_codigo']);
        $this->assertStringContainsString('Partial refund unsupported', $r['error_mensaje']);
    }

    public function testSinCausaSeUsaElCampoError()
    {
        $http = (new FakeHttpClient())->responde('/refunds', 401, ['message' => 'invalid_token', 'error' => 'unauthorized']);

        $r = $this->mp($http)->devolver('123', 100, 'clave');

        $this->assertSame('rechazada', $r['resultado']);
        $this->assertSame('unauthorized', $r['error_codigo']);
    }

    /** Un corte de conexión o un 5xx no dicen si el reembolso se hizo. */
    public function testUnCorteOUnErrorDelServidorQuedaIncierto()
    {
        foreach ([0, 500, 503] as $status) {
            $http = (new FakeHttpClient())->responde('/refunds', $status, '');

            $this->assertSame('incierta', $this->mp($http)->devolver('123', 100, 'clave')['resultado'], "status $status");
        }
    }

    public function testUnaRespuestaSinIdQuedaIncierta()
    {
        $http = (new FakeHttpClient())->responde('/refunds', 201, ['status' => 'approved']);

        $this->assertSame('incierta', $this->mp($http)->devolver('123', 100, 'clave')['resultado']);
    }

    public function testUnReembolsoEnProcesoQuedaInciertoConSuId()
    {
        $http = (new FakeHttpClient())->responde('/refunds', 201, ['id' => 9, 'status' => 'in_process']);

        $r = $this->mp($http)->devolver('123', 100, 'clave');

        $this->assertSame('incierta', $r['resultado']);
        $this->assertSame('9', $r['id']);
    }

    public function testUnReembolsoRechazadoEnElAltaEsRechazado()
    {
        $http = (new FakeHttpClient())->responde('/refunds', 201, ['id' => 9, 'status' => 'rejected']);

        $this->assertSame('rechazada', $this->mp($http)->devolver('123', 100, 'clave')['resultado']);
    }

    // ------------------------------------------------------------ consultas

    public function testLaListaDeReembolsosDeUnPago()
    {
        $http = (new FakeHttpClient())->responde('/v1/payments/123/refunds', 200, [
            ['id' => 1, 'amount' => 50.5, 'status' => 'approved'],
            ['id' => 2, 'amount' => '10', 'status' => 'in_process'],
            'basura',
        ]);

        $r = $this->mp($http)->devoluciones('123');

        $this->assertTrue($r['ok']);
        $this->assertSame([
            ['id' => '1', 'monto' => 50.5, 'estado' => 'approved'],
            ['id' => '2', 'monto' => 10.0, 'estado' => 'in_process'],
        ], $r['devoluciones']);
        $this->assertSame('GET', $http->llamadas[0]['method'], 'consultar nunca pide un reembolso');
    }

    public function testSiNoSePuedeLeerLaListaNoSeSabeNada()
    {
        $http = (new FakeHttpClient())->responde('/refunds', 500, '');

        $this->assertFalse($this->mp($http)->devoluciones('123')['ok']);
    }

    /** El reparto que se guarda no lleva datos de quien pagó. */
    public function testElRepartoDelPagoSeQuedaConLoQueExplicaLaPlata()
    {
        $http = (new FakeHttpClient())->responde('/v1/payments/123', 200, [
            'status' => 'approved', 'status_detail' => 'partially_refunded',
            'transaction_amount' => 10000, 'transaction_amount_refunded' => 9368.81,
            'transaction_details' => ['net_received_amount' => 9368.81],
            'fee_details' => [
                ['type' => 'mercadopago_fee', 'amount' => 531.19, 'fee_payer' => 'collector'],
                ['type' => 'application_fee', 'amount' => 100, 'fee_payer' => 'collector'],
            ],
            'refunds' => [['id' => 7, 'amount' => 9368.81, 'status' => 'approved', 'source' => ['id' => '99', 'name' => 'Dueña', 'type' => 'collector']]],
            'payer' => ['email' => 'ana@example.com', 'identification' => ['number' => '30111222']],
        ]);

        $r = $this->mp($http)->repartoDelPago('123');

        $this->assertSame('partially_refunded', $r['status_detail']);
        $this->assertSame(100.0, $r['application_fee']);
        $this->assertSame(9368.81, $r['transaction_amount_refunded']);
        $this->assertCount(2, $r['fee_details']);
        $this->assertSame(['type' => 'collector'], $r['refunds'][0]['source']);
        $this->assertStringNotContainsString('ana@example.com', json_encode($r));
        $this->assertStringNotContainsString('30111222', json_encode($r));
    }

    public function testConsultarUnPagoDiceCuantoSeDevolvio()
    {
        $http = (new FakeHttpClient())->responde('/v1/payments/123', 200, [
            'status' => 'approved', 'status_detail' => 'partially_refunded',
            'transaction_amount' => 1000, 'transaction_amount_refunded' => 250.5,
        ]);

        $r = $this->mp($http)->consultarPago('123');

        $this->assertSame(250.5, $r['devuelto']);
        $this->assertSame('partially_refunded', $r['estado_detalle']);
    }

    public function testUnPagoSinDevolucionesTieneCeroDevuelto()
    {
        $http = (new FakeHttpClient())->responde('/v1/payments/123', 200, ['status' => 'approved', 'transaction_amount' => 1000]);

        $this->assertSame(0.0, $this->mp($http)->consultarPago('123')['devuelto']);
    }
}
