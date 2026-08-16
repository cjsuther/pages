<?php

namespace Tests\Unit\Lib;

use MercadoPago;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;

class MercadoPagoTest extends TestCase
{
    const TOKEN_PROD = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';
    const TOKEN_TEST = 'TEST-1234567890123456-081612-abcdef0123456789abcdef01234567-123';

    private function compra(array $overrides = [])
    {
        return array_merge([
            'titulo'     => 'Fiesta de fin de año',
            'cantidad'   => 2,
            'precio'     => 1500.0,
            'moneda'     => 'ARS',
            'referencia' => 'ABC123DEF456',
            'urlRetorno' => 'https://rezon.ar/entrada/ABC123DEF456',
            'urlAviso'   => 'https://rezon.ar/api/public/aviso-pago.php?orden=ABC123DEF456',
            'comprador'  => ['nombre' => 'Ana Gómez', 'email' => 'ana@example.com', 'telefono' => '1122334455'],
        ], $overrides);
    }

    // ------------------------------------------------------ modo del token

    /**
     * Cobrar de verdad creyendo que se está probando —o mostrar un checkout de
     * prueba a compradores reales— es un error caro y silencioso.
     */
    public function testUnTokenDePruebaSeReconocePorElPrefijo()
    {
        $this->assertSame('prueba', MercadoPago::modoDelToken(self::TOKEN_TEST));
        $this->assertSame('produccion', MercadoPago::modoDelToken(self::TOKEN_PROD));
    }

    public function testSeReconoceLaFormaDeUnAccessToken()
    {
        $this->assertTrue(MercadoPago::pareceToken(self::TOKEN_PROD));
        $this->assertTrue(MercadoPago::pareceToken(self::TOKEN_TEST));
    }

    public function testSeRechazaCualquierCosaQueNoSeaUnToken()
    {
        $this->assertFalse(MercadoPago::pareceToken('mi-token'));
        $this->assertFalse(MercadoPago::pareceToken('APP_USR-corto'));
        $this->assertFalse(MercadoPago::pareceToken(''));
        $this->assertFalse(MercadoPago::pareceToken(null));
    }

    // ------------------------------------------------------------ verificar

    public function testUnaCredencialValidaDevuelveLaCuenta()
    {
        $http = (new FakeHttpClient())->responde('/users/me', 200, ['nickname' => 'MIBANDA']);

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->verificar();

        $this->assertTrue($r['ok']);
        $this->assertSame('MIBANDA', $r['cuenta']);
    }

    public function testLaCredencialViajaEnLaCabeceraDeAutorizacion()
    {
        $http = (new FakeHttpClient())->responde('/users/me', 200, ['nickname' => 'X']);

        (new MercadoPago(self::TOKEN_PROD, $http))->verificar();

        $this->assertContains('Authorization: Bearer ' . self::TOKEN_PROD, $http->cabecerasDe('/users/me'));
    }

    public function testUnaCredencialRechazadaLoDiceEnCastellano()
    {
        $http = (new FakeHttpClient())->responde('/users/me', 401, ['message' => 'invalid_token']);

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->verificar();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('rechazó la credencial', $r['error']);
    }

    public function testUnaCaidaDeMercadoPagoNoSeConfundeConCredencialInvalida()
    {
        $http = (new FakeHttpClient())->responde('/users/me', 500, '');

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->verificar();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('500', $r['error']);
    }

    public function testUnaRespuestaIlegibleNoRompe()
    {
        $http = (new FakeHttpClient())->responde('/users/me', 200, 'esto no es json');

        $this->assertFalse((new MercadoPago(self::TOKEN_PROD, $http))->verificar()['ok']);
    }

    // ---------------------------------------------------- crearPreferencia

    private function respuestaDePreferencia(array $overrides = [])
    {
        return array_merge([
            'id' => 'PREF-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123',
        ], $overrides);
    }

    public function testCrearPreferenciaDevuelveElLinkDePago()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, $this->respuestaDePreferencia());

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra());

        $this->assertTrue($r['ok']);
        $this->assertSame('PREF-123', $r['id']);
        $this->assertStringContainsString('mercadopago.com.ar', $r['url']);
    }

    /** Con credenciales de prueba el checkout real es el de sandbox. */
    public function testEnModoPruebaSeUsaElCheckoutDeSandbox()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, $this->respuestaDePreferencia());

        $r = (new MercadoPago(self::TOKEN_TEST, $http))->crearPreferencia($this->compra());

        $this->assertStringContainsString('sandbox', $r['url']);
    }

    public function testEnProduccionNoSeUsaSandbox()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, $this->respuestaDePreferencia());

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra());

        $this->assertStringNotContainsString('sandbox', $r['url']);
    }

    public function testLaPreferenciaLlevaElItemConPrecioYCantidad()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, $this->respuestaDePreferencia());

        (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra(['cantidad' => 3, 'precio' => 1500.0]));

        $item = $http->jsonDe('/checkout/preferences')['items'][0];

        $this->assertSame('Fiesta de fin de año', $item['title']);
        $this->assertSame(3, $item['quantity']);
        $this->assertSame(1500.0, $item['unit_price']);
        $this->assertSame('ARS', $item['currency_id']);
    }

    /**
     * La referencia es lo que después permite saber a qué orden corresponde un
     * pago, sin confiar en nada que venga del navegador.
     */
    public function testLaPreferenciaLlevaElCodigoDeLaOrdenComoReferencia()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, $this->respuestaDePreferencia());

        (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra());

        $this->assertSame('ABC123DEF456', $http->jsonDe('/checkout/preferences')['external_reference']);
    }

    public function testLaPreferenciaDiceADondeAvisarElPago()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, $this->respuestaDePreferencia());

        (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra());

        $enviado = $http->jsonDe('/checkout/preferences');

        $this->assertStringContainsString('aviso-pago.php', $enviado['notification_url']);
        $this->assertStringContainsString('/entrada/ABC123DEF456', $enviado['back_urls']['success']);
    }

    /**
     * Un cupón de pago fácil se acredita días después, cuando el cupo de la
     * reserva ya se liberó y la entrada pudo venderse a otro.
     */
    public function testSeExcluyenLosMediosQueSeAcreditanTarde()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, $this->respuestaDePreferencia());

        (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra());

        $excluidos = $http->jsonDe('/checkout/preferences')['payment_methods']['excluded_payment_types'];

        $this->assertContains(['id' => 'ticket'], $excluidos);
        $this->assertContains(['id' => 'atm'], $excluidos);
    }

    public function testUnErrorDeMercadoPagoSeDevuelveConSuMensaje()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 400, ['message' => 'invalid currency_id']);

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra());

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('invalid currency_id', $r['error']);
    }

    /** Sin link de pago la preferencia no sirve, aunque haya respondido 201. */
    public function testUnaPreferenciaSinLinkSeTrataComoError()
    {
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 201, ['id' => 'PREF-123']);

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->crearPreferencia($this->compra());

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('link de pago', $r['error']);
    }

    // ------------------------------------------------------- consultarPago

    public function testConsultarPagoDevuelveEstadoReferenciaYMonto()
    {
        $http = (new FakeHttpClient())->responde('/v1/payments/99', 200, [
            'status' => 'approved',
            'external_reference' => 'ABC123DEF456',
            'transaction_amount' => 3000.0,
        ]);

        $r = (new MercadoPago(self::TOKEN_PROD, $http))->consultarPago('99');

        $this->assertTrue($r['ok']);
        $this->assertSame('approved', $r['estado']);
        $this->assertSame('ABC123DEF456', $r['referencia']);
        $this->assertSame(3000.0, $r['monto']);
    }

    public function testUnPagoQueNoSePudoConsultarNoSeDaPorBueno()
    {
        $http = (new FakeHttpClient())->responde('/v1/payments/99', 404, '');

        $this->assertFalse((new MercadoPago(self::TOKEN_PROD, $http))->consultarPago('99')['ok']);
    }
}
