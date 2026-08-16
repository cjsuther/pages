<?php

namespace Tests\Unit\Lib;

use Cobros;
use Cripto;
use Tests\Support\FakeHttpClient;
use Tests\Support\HandlerTestCase;

class CobrosTest extends HandlerTestCase
{
    const TOKEN = 'APP_USR-token-del-vendedor-123456';
    const REFRESH = 'TG-refresh-del-vendedor-123456';

    private function credenciales(array $overrides = [])
    {
        return array_merge([
            'access_token'  => self::TOKEN,
            'refresh_token' => self::REFRESH,
            'public_key'    => 'APP_USR-publica',
            'user_id'       => '987654321',
            'modo'          => 'produccion',
            'expira_en'     => date('Y-m-d H:i:s', time() + 86400 * 180),
        ], $overrides);
    }

    private function hayCuentaConectada(array $overrides = [])
    {
        $this->db->onSelect('FROM page_payment_settings WHERE page_id', [array_merge([
            'page_id' => 5,
            'mp_user_id' => '987654321',
            'access_token_cifrado' => Cripto::cifrar(self::TOKEN),
            'refresh_token_cifrado' => Cripto::cifrar(self::REFRESH),
            'token_ultimos4' => '3456',
            'public_key' => 'APP_USR-publica',
            'modo' => 'produccion',
            'conectado_por' => 'oauth',
            'token_expira_en' => date('Y-m-d H:i:s', time() + 86400 * 180),
            'verificado_en' => '2026-08-16 20:00:00',
        ], $overrides)]);
    }

    // ---------------------------------------------------------------- estado

    public function testSinCuentaConectadaLoDiceAsi()
    {
        $estado = Cobros::estado($this->db, 5);

        $this->assertFalse($estado['configurado']);
        $this->assertFalse($estado['admite_split']);
    }

    public function testConCuentaConectadaSeInformaLaCuentaYElModo()
    {
        $this->hayCuentaConectada();

        $estado = Cobros::estado($this->db, 5);

        $this->assertTrue($estado['configurado']);
        $this->assertSame('987654321', $estado['cuenta']);
        $this->assertSame('produccion', $estado['modo']);
    }

    /** Ni el access token ni el refresh token pueden volver al frontend. */
    public function testElEstadoNoDevuelveNingunSecreto()
    {
        $this->hayCuentaConectada();

        $serializado = json_encode(Cobros::estado($this->db, 5));

        $this->assertStringNotContainsString(self::TOKEN, $serializado);
        $this->assertStringNotContainsString(self::REFRESH, $serializado);
        $this->assertStringNotContainsString('cifrado', $serializado);
    }

    // ----------------------------------------------------------------- split

    /**
     * Es la distinción que sostiene todo el negocio: con una credencial pegada
     * a mano Mercado Pago ignora la comisión sin dar ningún error.
     */
    public function testUnaCuentaConectadaPorOauthAdmiteSplit()
    {
        $this->hayCuentaConectada(['conectado_por' => 'oauth']);

        $this->assertTrue(Cobros::admiteSplit($this->db, 5));
    }

    public function testUnaCredencialCargadaAManoNoAdmiteSplit()
    {
        $this->hayCuentaConectada(['conectado_por' => 'manual']);

        $this->assertFalse(Cobros::admiteSplit($this->db, 5));
    }

    public function testSinCuentaNoAdmiteSplit()
    {
        $this->assertFalse(Cobros::admiteSplit($this->db, 5));
    }

    // -------------------------------------------------------- guardarDesdeOAuth

    public function testGuardarDejaLaCuentaComoConectadaPorOauth()
    {
        $this->db->onWrite('INSERT INTO page_payment_settings', 1);

        $r = Cobros::guardarDesdeOAuth($this->db, 5, $this->credenciales());

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('"oauth"', $this->db->callsFor('INSERT INTO page_payment_settings')[0]['sql']);
    }

    public function testLosDosSecretosSeGuardanCifrados()
    {
        $this->db->onWrite('INSERT INTO page_payment_settings', 1);

        Cobros::guardarDesdeOAuth($this->db, 5, $this->credenciales());
        $params = $this->db->paramsFor('INSERT INTO page_payment_settings');

        $this->assertStringNotContainsString(self::TOKEN, $params[2]);
        $this->assertStringNotContainsString(self::REFRESH, $params[3]);
        $this->assertSame(self::TOKEN, Cripto::descifrar($params[2]));
        $this->assertSame(self::REFRESH, Cripto::descifrar($params[3]));
    }

    public function testReconectarActualizaEnLugarDeDuplicar()
    {
        $this->db->onWrite('INSERT INTO page_payment_settings', 1);

        Cobros::guardarDesdeOAuth($this->db, 5, $this->credenciales());

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE',
            $this->db->callsFor('INSERT INTO page_payment_settings')[0]['sql']);
    }

    public function testSinAccessTokenNoSeGuardaNada()
    {
        $r = Cobros::guardarDesdeOAuth($this->db, 5, $this->credenciales(['access_token' => '']));

        $this->assertFalse($r['ok']);
        $this->assertNoWrites();
    }

    // --------------------------------------------------------------- tokenDe

    public function testDevuelveElTokenDescifrado()
    {
        $this->hayCuentaConectada();

        $this->assertSame(self::TOKEN, Cobros::tokenDe($this->db, 5));
    }

    public function testSinCuentaNoHayToken()
    {
        $this->assertNull(Cobros::tokenDe($this->db, 5));
    }

    public function testUnTokenVigenteNoSeRenueva()
    {
        $this->hayCuentaConectada();
        $http = new FakeHttpClient();

        Cobros::tokenDe($this->db, 5, $http);

        $this->assertSame([], $http->llamadas, 'no tiene que hablar con Mercado Pago');
    }

    /**
     * El token vence a los seis meses. Renovarlo al usarlo, y no con un proceso
     * aparte, evita que un cobro falle porque el renovador no corrió.
     */
    public function testUnTokenPorVencerSeRenuevaSolo()
    {
        $this->hayCuentaConectada(['token_expira_en' => date('Y-m-d H:i:s', time() + 60)]);
        $this->db->onWrite('INSERT INTO page_payment_settings', 1);

        $http = (new FakeHttpClient())->responde('/oauth/token', 200, [
            'access_token' => 'APP_USR-token-nuevo',
            'refresh_token' => 'TG-refresh-nuevo',
            'public_key' => 'APP_USR-publica',
            'user_id' => 987654321,
            'live_mode' => true,
            'expires_in' => 15552000,
        ]);

        $this->assertSame('APP_USR-token-nuevo', Cobros::tokenDe($this->db, 5, $http));
    }

    public function testElTokenRenovadoSeGuarda()
    {
        $this->hayCuentaConectada(['token_expira_en' => date('Y-m-d H:i:s', time() + 60)]);
        $this->db->onWrite('INSERT INTO page_payment_settings', 1);

        $http = (new FakeHttpClient())->responde('/oauth/token', 200, [
            'access_token' => 'APP_USR-token-nuevo', 'refresh_token' => 'TG-nuevo',
            'public_key' => 'x', 'user_id' => 1, 'live_mode' => true, 'expires_in' => 100,
        ]);

        Cobros::tokenDe($this->db, 5, $http);

        $this->assertTrue($this->db->ran('INSERT INTO page_payment_settings'));
    }

    /**
     * Si la renovación falla se devuelve el token viejo y que Mercado Pago dé
     * un error explícito, en vez de dejar al comprador sin checkout por una
     * decisión nuestra.
     */
    public function testSiLaRenovacionFallaSeSigueConElTokenViejo()
    {
        $this->hayCuentaConectada(['token_expira_en' => date('Y-m-d H:i:s', time() + 60)]);
        $http = (new FakeHttpClient())->responde('/oauth/token', 400, ['message' => 'invalid_grant']);

        $this->assertSame(self::TOKEN, Cobros::tokenDe($this->db, 5, $http));
    }

    public function testSinRefreshTokenNoSeIntentaRenovar()
    {
        $this->hayCuentaConectada([
            'token_expira_en' => date('Y-m-d H:i:s', time() + 60),
            'refresh_token_cifrado' => null,
        ]);
        $http = new FakeHttpClient();

        $this->assertSame(self::TOKEN, Cobros::tokenDe($this->db, 5, $http));
        $this->assertSame([], $http->llamadas);
    }
}
