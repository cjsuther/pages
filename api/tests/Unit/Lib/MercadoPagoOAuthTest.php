<?php

namespace Tests\Unit\Lib;

use JWT;
use MercadoPagoOAuth;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttpClient;

class MercadoPagoOAuthTest extends TestCase
{
    private function respuestaDeToken(array $overrides = [])
    {
        return array_merge([
            'access_token'  => 'APP_USR-token-del-vendedor-123456',
            'refresh_token' => 'TG-refresh-del-vendedor-123456',
            'public_key'    => 'APP_USR-clave-publica-123456',
            'user_id'       => 987654321,
            'live_mode'     => true,
            'expires_in'    => 15552000,
        ], $overrides);
    }

    // ------------------------------------------------------------ configurado

    public function testEstaConfiguradoConLasConstantesDePrueba()
    {
        $this->assertTrue(MercadoPagoOAuth::configurado());
    }

    // ---------------------------------------------------------------- estado

    /**
     * Sin firmar, cualquiera podría armar el link con el page_id de otro y
     * terminar conectando una cuenta a una página que no le pertenece.
     */
    public function testElEstadoViajaFirmadoYSePuedeLeerDeVuelta()
    {
        $firmado = MercadoPagoOAuth::firmarEstado(5, 7);
        $leido = MercadoPagoOAuth::leerEstado($firmado);

        $this->assertSame(5, $leido['page_id']);
        $this->assertSame(7, $leido['user_id']);
    }

    public function testUnEstadoManipuladoNoSeAcepta()
    {
        $firmado = MercadoPagoOAuth::firmarEstado(5, 7);
        $manipulado = substr($firmado, 0, -4) . 'AAAA';

        $this->assertNull(MercadoPagoOAuth::leerEstado($manipulado));
    }

    public function testUnEstadoInventadoNoSeAcepta()
    {
        $this->assertNull(MercadoPagoOAuth::leerEstado('cualquier cosa'));
        $this->assertNull(MercadoPagoOAuth::leerEstado(''));
        $this->assertNull(MercadoPagoOAuth::leerEstado(null));
    }

    /** Un token de sesión no puede servir para conectar cuentas. */
    public function testUnJwtDeOtroUsoNoSirveComoEstado()
    {
        $deSesion = JWT::encode(['user_id' => 7, 'page_id' => 5, 'exp' => time() + 900], JWT_SECRET);

        $this->assertNull(MercadoPagoOAuth::leerEstado($deSesion));
    }

    public function testElEstadoVence()
    {
        $vencido = JWT::encode([
            'page_id' => 5, 'user_id' => 7, 'uso' => 'mp_oauth', 'exp' => time() - 10,
        ], JWT_SECRET);

        $this->assertNull(MercadoPagoOAuth::leerEstado($vencido));
    }

    // ------------------------------------------------------------------- url

    public function testLaUrlDeAutorizacionLlevaLaAplicacionYElEstado()
    {
        $url = MercadoPagoOAuth::urlDeAutorizacion('el-estado-firmado');

        $this->assertStringContainsString('client_id=' . MP_APP_ID, $url);
        $this->assertStringContainsString('state=el-estado-firmado', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString(urlencode(MP_OAUTH_REDIRECT_URI), $url);
    }

    // -------------------------------------------------------- canjearCodigo

    public function testCanjearElCodigoDevuelveLasCredenciales()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 200, $this->respuestaDeToken());

        $r = (new MercadoPagoOAuth($http))->canjearCodigo('CODIGO-123');

        $this->assertTrue($r['ok']);
        $this->assertSame('APP_USR-token-del-vendedor-123456', $r['credenciales']['access_token']);
        $this->assertSame('TG-refresh-del-vendedor-123456', $r['credenciales']['refresh_token']);
        $this->assertSame('987654321', $r['credenciales']['user_id']);
    }

    public function testElCanjeMandaElSecretoDeLaAplicacion()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 200, $this->respuestaDeToken());

        (new MercadoPagoOAuth($http))->canjearCodigo('CODIGO-123');
        $enviado = $http->jsonDe('/oauth/token');

        $this->assertSame(MP_APP_ID, $enviado['client_id']);
        $this->assertSame(MP_APP_SECRET, $enviado['client_secret']);
        $this->assertSame('authorization_code', $enviado['grant_type']);
        $this->assertSame('CODIGO-123', $enviado['code']);
    }

    /**
     * live_mode es más confiable que el prefijo del token: en OAuth el token no
     * siempre viene con TEST-, y cobrar de verdad creyendo que se prueba (o al
     * revés) es un error caro y silencioso.
     */
    public function testElModoSaleDeLiveModeYNoDelPrefijoDelToken()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 200,
            $this->respuestaDeToken(['live_mode' => false]));

        $r = (new MercadoPagoOAuth($http))->canjearCodigo('CODIGO-123');

        $this->assertSame('prueba', $r['credenciales']['modo']);
    }

    public function testUnaCuentaRealQuedaComoProduccion()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 200, $this->respuestaDeToken());

        $this->assertSame('produccion',
            (new MercadoPagoOAuth($http))->canjearCodigo('CODIGO-123')['credenciales']['modo']);
    }

    public function testSeCalculaCuandoVenceElToken()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 200, $this->respuestaDeToken());

        $r = (new MercadoPagoOAuth($http))->canjearCodigo('CODIGO-123');

        $this->assertGreaterThan(time(), strtotime($r['credenciales']['expira_en']));
    }

    public function testUnCodigoRechazadoDevuelveElMotivo()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 400, ['message' => 'invalid_grant']);

        $r = (new MercadoPagoOAuth($http))->canjearCodigo('CODIGO-VIEJO');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('invalid_grant', $r['error']);
    }

    public function testUnaRespuestaSinTokenSeTrataComoError()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 200, ['algo' => 'raro']);

        $this->assertFalse((new MercadoPagoOAuth($http))->canjearCodigo('X')['ok']);
    }

    // ------------------------------------------------------------- refrescar

    public function testRefrescarPideUnTokenNuevo()
    {
        $http = (new FakeHttpClient())->responde('/oauth/token', 200, $this->respuestaDeToken());

        $r = (new MercadoPagoOAuth($http))->refrescar('TG-refresh-viejo');
        $enviado = $http->jsonDe('/oauth/token');

        $this->assertTrue($r['ok']);
        $this->assertSame('refresh_token', $enviado['grant_type']);
        $this->assertSame('TG-refresh-viejo', $enviado['refresh_token']);
    }

    // --------------------------------------------------------- vencimiento

    public function testUnTokenQueVenceEnMesesNoNecesitaRenovarse()
    {
        $this->assertFalse(MercadoPagoOAuth::estaPorVencer(date('Y-m-d H:i:s', time() + 86400 * 30)));
    }

    public function testUnTokenQueVenceEnMinutosSeRenueva()
    {
        $this->assertTrue(MercadoPagoOAuth::estaPorVencer(date('Y-m-d H:i:s', time() + 60)));
    }

    public function testUnTokenYaVencidoSeRenueva()
    {
        $this->assertTrue(MercadoPagoOAuth::estaPorVencer(date('Y-m-d H:i:s', time() - 3600)));
    }

    /** Sin fecha de vencimiento no hay nada que renovar. */
    public function testSinFechaNoSeIntentaRenovar()
    {
        $this->assertFalse(MercadoPagoOAuth::estaPorVencer(null));
        $this->assertFalse(MercadoPagoOAuth::estaPorVencer(''));
    }
}
