<?php

namespace Tests\Unit\Lib;

use OAuth;
use Tests\Support\HandlerTestCase;

class OAuthTest extends HandlerTestCase
{
    /** Verificador y desafío de PKCE que se corresponden. */
    private const VERIFICADOR = 'un-verificador-largo-y-aleatorio-de-prueba-1234567890';

    private function desafio()
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::VERIFICADOR, true)), '+/', '-_'), '=');
    }

    private function hayCliente(array $overrides = [])
    {
        $this->db->onSelect('FROM oauth_clients WHERE client_id', [array_merge([
            'id' => 1,
            'client_id' => 'abc123',
            'secreto_hash' => null,
            'nombre' => 'Claude',
            'redirect_uris' => json_encode(['https://claude.ai/callback']),
        ], $overrides)]);
    }

    private function pedido(array $overrides = [])
    {
        return array_merge([
            'client_id' => 'abc123',
            'redirect_uri' => 'https://claude.ai/callback',
            'response_type' => 'code',
            'code_challenge' => $this->desafio(),
            'code_challenge_method' => 'S256',
        ], $overrides);
    }

    // ---------------------------------------------------- registro de clientes

    public function testUnClienteSeRegistraSolo()
    {
        $this->db->onWrite('INSERT INTO oauth_clients', 1);

        $r = OAuth::registrarCliente($this->db, 'Claude', ['https://claude.ai/callback']);

        $this->assertTrue($r['ok']);
        $this->assertNotEmpty($r['cliente']['client_id']);
    }

    public function testUnClienteSinRedireccionNoSeRegistra()
    {
        $r = OAuth::registrarCliente($this->db, 'Claude', []);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO oauth_clients'));
    }

    /**
     * Un destino sin cifrar expondría el código de autorización en la red.
     * Se permite localhost porque ahí corren los clientes de escritorio, que
     * no tienen certificado.
     */
    public function testSoloSeAceptanRedireccionesSeguras()
    {
        $this->assertTrue(OAuth::redirectAceptable('https://claude.ai/callback'));
        $this->assertTrue(OAuth::redirectAceptable('http://localhost:8765/cb'));
        $this->assertTrue(OAuth::redirectAceptable('http://127.0.0.1:1234/cb'));
        $this->assertFalse(OAuth::redirectAceptable('http://ejemplo.com/cb'));
        $this->assertFalse(OAuth::redirectAceptable('no es una url'));
    }

    /** Los clientes de escritorio vuelven por un esquema propio del sistema. */
    public function testSeAceptaUnEsquemaPropioDeAplicacion()
    {
        $this->assertTrue(OAuth::redirectAceptable('claude://oauth/callback'));
    }

    // -------------------------------------------------------- revisar pedido

    public function testUnPedidoCompletoPasa()
    {
        $this->hayCliente();

        $this->assertTrue(OAuth::revisarPedido($this->db, $this->pedido())['ok']);
    }

    public function testUnClienteDesconocidoNoPasa()
    {
        $this->db->onSelect('FROM oauth_clients WHERE client_id', []);

        $r = OAuth::revisarPedido($this->db, $this->pedido());

        $this->assertFalse($r['ok']);
    }

    /**
     * El caso peligroso: si se aceptara una redirección no registrada, el
     * código de una persona terminaría en el servidor de otro.
     */
    public function testUnaRedireccionQueNoEsLaRegistradaNoPasa()
    {
        $this->hayCliente();

        $r = OAuth::revisarPedido($this->db, $this->pedido(['redirect_uri' => 'https://atacante.com/cb']));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('retorno', $r['error']);
    }

    /** OAuth 2.1: sin PKCE no se emite nada. */
    public function testSinPkceNoPasa()
    {
        $this->hayCliente();

        $r = OAuth::revisarPedido($this->db, $this->pedido(['code_challenge' => '']));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('PKCE', $r['error']);
    }

    /** "plain" no protege de nadie que pueda leer el pedido. */
    public function testPkcePlainNoSeAcepta()
    {
        $this->hayCliente();

        $r = OAuth::revisarPedido($this->db, $this->pedido(['code_challenge_method' => 'plain']));

        $this->assertFalse($r['ok']);
    }

    public function testSoloSeAdmiteElFlujoDeCodigo()
    {
        $this->hayCliente();

        $r = OAuth::revisarPedido($this->db, $this->pedido(['response_type' => 'token']));

        $this->assertFalse($r['ok']);
    }

    // ----------------------------------------------------------- el canje

    private function hayCodigo(array $overrides = [])
    {
        $this->db->onSelect('FROM oauth_codes WHERE code_hash', [array_merge([
            'id' => 9,
            'client_id' => 'abc123',
            'user_id' => 7,
            'redirect_uri' => 'https://claude.ai/callback',
            'code_challenge' => $this->desafio(),
            'resource' => 'https://rezon.ar/mcp',
            'expira_en' => date('Y-m-d H:i:s', time() + 120),
            'usado_en' => null,
        ], $overrides)]);
        $this->db->onWrite('UPDATE oauth_codes SET usado_en', 1);
        $this->db->onWrite('INSERT INTO oauth_tokens', 1);
    }

    private function canje(array $overrides = [])
    {
        return array_merge([
            'code' => 'el-codigo',
            'client_id' => 'abc123',
            'redirect_uri' => 'https://claude.ai/callback',
            'code_verifier' => self::VERIFICADOR,
        ], $overrides);
    }

    public function testUnCanjeCorrectoDevuelveToken()
    {
        $this->hayCodigo();

        $r = OAuth::canjear($this->db, $this->canje());

        $this->assertTrue($r['ok']);
        $this->assertNotEmpty($r['token']['access_token']);
        $this->assertNotEmpty($r['token']['refresh_token']);
        $this->assertSame('Bearer', $r['token']['token_type']);
    }

    /** El verificador es lo que prueba que quien canjea es quien pidió. */
    public function testUnVerificadorQueNoCorrespondeNoCanjea()
    {
        $this->hayCodigo();

        $r = OAuth::canjear($this->db, $this->canje(['code_verifier' => 'otro-verificador']));

        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_grant', $r['error']);
    }

    public function testSinVerificadorNoCanjea()
    {
        $this->hayCodigo();

        $this->assertFalse(OAuth::canjear($this->db, $this->canje(['code_verifier' => '']))['ok']);
    }

    public function testUnCodigoQueNoExisteNoCanjea()
    {
        $this->db->onSelect('FROM oauth_codes WHERE code_hash', []);

        $this->assertFalse(OAuth::canjear($this->db, $this->canje())['ok']);
    }

    public function testUnCodigoVencidoNoCanjea()
    {
        $this->hayCodigo(['expira_en' => date('Y-m-d H:i:s', time() - 10)]);

        $this->assertFalse(OAuth::canjear($this->db, $this->canje())['ok']);
    }

    public function testElCodigoNoSirveParaOtroCliente()
    {
        $this->hayCodigo();

        $r = OAuth::canjear($this->db, $this->canje(['client_id' => 'otro']));

        $this->assertSame('invalid_client', $r['error']);
    }

    public function testElCodigoNoSirveConOtraRedireccion()
    {
        $this->hayCodigo();

        $this->assertFalse(OAuth::canjear($this->db, $this->canje(['redirect_uri' => 'https://otra.com/cb']))['ok']);
    }

    /**
     * Un código que ya se usó y vuelve a aparecer significa que alguien lo
     * interceptó: se anulan los tokens que salieron de él.
     */
    public function testUnCodigoReutilizadoDesconectaLaSesion()
    {
        $this->hayCodigo(['usado_en' => date('Y-m-d H:i:s')]);
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 1);

        $r = OAuth::canjear($this->db, $this->canje());

        $this->assertFalse($r['ok']);
        $this->assertSame(1, $this->db->countCalls('UPDATE oauth_tokens SET revocado_en'));
    }

    /** El código se marca usado antes de emitir: no puede canjearse dos veces. */
    public function testElCodigoSeMarcaUsado()
    {
        $this->hayCodigo();

        OAuth::canjear($this->db, $this->canje());

        $this->assertSame(1, $this->db->countCalls('UPDATE oauth_codes SET usado_en'));
    }

    // -------------------------------------------------------------- refresco

    public function testElRefrescoDevuelveUnTokenNuevo()
    {
        $this->db->onSelect('FROM oauth_tokens WHERE refresh_hash', [[
            'id' => 4, 'client_id' => 'abc123', 'user_id' => 7,
            'resource' => null, 'revocado_en' => null,
        ]]);
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 1);
        $this->db->onWrite('INSERT INTO oauth_tokens', 1);

        $r = OAuth::refrescar($this->db, ['refresh_token' => 'viejo', 'client_id' => 'abc123']);

        $this->assertTrue($r['ok']);
        $this->assertNotEmpty($r['token']['access_token']);
    }

    /** El refresco rota: el viejo deja de valer apenas se usa. */
    public function testElRefrescoViejoSeRevoca()
    {
        $this->db->onSelect('FROM oauth_tokens WHERE refresh_hash', [[
            'id' => 4, 'client_id' => 'abc123', 'user_id' => 7,
            'resource' => null, 'revocado_en' => null,
        ]]);
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 1);
        $this->db->onWrite('INSERT INTO oauth_tokens', 1);

        OAuth::refrescar($this->db, ['refresh_token' => 'viejo', 'client_id' => 'abc123']);

        $this->assertSame(1, $this->db->countCalls('UPDATE oauth_tokens SET revocado_en'));
    }

    public function testUnRefrescoRevocadoNoSirve()
    {
        $this->db->onSelect('FROM oauth_tokens WHERE refresh_hash', [[
            'id' => 4, 'client_id' => 'abc123', 'user_id' => 7,
            'resource' => null, 'revocado_en' => date('Y-m-d H:i:s'),
        ]]);

        $this->assertFalse(OAuth::refrescar($this->db, ['refresh_token' => 'x', 'client_id' => 'abc123'])['ok']);
    }

    // ---------------------------------------------------------- usar el token

    public function testUnTokenVigenteIdentificaASuDuenio()
    {
        $this->db->onSelect('FROM oauth_tokens t', [[
            'id' => 4, 'user_id' => 7, 'expira_en' => date('Y-m-d H:i:s', time() + 600),
            'email' => 'ana@example.com', 'name' => 'Ana',
        ]]);
        $this->db->onWrite('UPDATE oauth_tokens SET ultimo_uso_en', 1);

        $u = OAuth::usuario($this->db, 'un-token');

        $this->assertSame(7, $u['user_id']);
        $this->assertTrue($u['por_clave_api']);
    }

    public function testUnTokenVencidoNoIdentificaANadie()
    {
        $this->db->onSelect('FROM oauth_tokens t', [[
            'id' => 4, 'user_id' => 7, 'expira_en' => date('Y-m-d H:i:s', time() - 10),
            'email' => 'ana@example.com', 'name' => 'Ana',
        ]]);

        $this->assertNull(OAuth::usuario($this->db, 'un-token'));
    }

    public function testUnTokenQueNoExisteNoIdentificaANadie()
    {
        $this->db->onSelect('FROM oauth_tokens t', []);

        $this->assertNull(OAuth::usuario($this->db, 'inventado'));
    }

    // ----------------------------------------------------------- desconectar

    public function testDesconectarRevocaLosTokensDeEsaAplicacion()
    {
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 2);

        $this->assertTrue(OAuth::desconectar($this->db, 7, 'abc123'));
    }

    public function testDesconectarAlgoQueNoEstabaConectado()
    {
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 0);

        $this->assertFalse(OAuth::desconectar($this->db, 7, 'abc123'));
    }

    /** Nadie desconecta las aplicaciones de otro. */
    public function testDesconectarFiltraPorUsuario()
    {
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 1);

        OAuth::desconectar($this->db, 7, 'abc123');

        $this->assertContains(7, $this->db->paramsFor('UPDATE oauth_tokens SET revocado_en'));
    }

    // ------------------------------------------------------------------ PKCE

    public function testElCalculoDePkceEsElDelEstandar()
    {
        // Ejemplo del RFC 7636, apéndice B.
        $verificador = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $desafio = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertTrue(OAuth::pkceCoincide($verificador, $desafio));
    }
}
