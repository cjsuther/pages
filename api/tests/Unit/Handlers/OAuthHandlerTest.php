<?php

namespace Tests\Unit\Handlers;

use OAuth;
use OAuthHandler;
use Request;
use Tests\Support\HandlerTestCase;

class OAuthHandlerTest extends HandlerTestCase
{
    private const VERIFICADOR = 'un-verificador-largo-y-aleatorio-de-prueba-1234567890';

    private function desafio()
    {
        return rtrim(strtr(base64_encode(hash('sha256', self::VERIFICADOR, true)), '+/', '-_'), '=');
    }

    private function hayCliente()
    {
        $this->db->onSelect('FROM oauth_clients WHERE client_id', [[
            'id' => 1, 'client_id' => 'abc123', 'secreto_hash' => null,
            'nombre' => 'Claude', 'redirect_uris' => json_encode(['https://claude.ai/callback']),
        ]]);
    }

    private function pedido(array $overrides = [])
    {
        return array_merge([
            'client_id' => 'abc123',
            'redirect_uri' => 'https://claude.ai/callback',
            'response_type' => 'code',
            'code_challenge' => $this->desafio(),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ], $overrides);
    }

    private function sesion($porClaveApi = false)
    {
        return ['user_id' => 7, 'email' => 'ana@example.com'] + ($porClaveApi ? ['por_clave_api' => true] : []);
    }

    // ------------------------------------------------------------- metadata

    /**
     * Es lo primero que busca un cliente: sin esto no sabe a dónde hablarle y
     * el circuito no arranca.
     */
    public function testLaMetadataDelServidorDiceDondeEstaTodo()
    {
        $r = OAuthHandler::metadataServidor($this->db, new Request('GET'));

        $this->assertStringContainsString('/oauth/authorize', $r->body['authorization_endpoint']);
        $this->assertStringContainsString('/oauth/token', $r->body['token_endpoint']);
        $this->assertStringContainsString('/oauth/registrar', $r->body['registration_endpoint']);
    }

    /** Sin PKCE no se emite nada, y sólo S256. */
    public function testLaMetadataAnunciaQueExigePkceS256()
    {
        $r = OAuthHandler::metadataServidor($this->db, new Request('GET'));

        $this->assertSame(['S256'], $r->body['code_challenge_methods_supported']);
    }

    public function testLaMetadataDelRecursoApuntaAlServidorDeAutorizacion()
    {
        $r = OAuthHandler::metadataRecurso($this->db, new Request('GET'));

        $this->assertStringContainsString('/mcp', $r->body['resource']);
        $this->assertNotEmpty($r->body['authorization_servers']);
    }

    // -------------------------------------------------------------- registro

    public function testUnClienteSeRegistraYRecibeSuIdentificador()
    {
        $this->db->onWrite('INSERT INTO oauth_clients', 1);

        $r = OAuthHandler::registrar($this->db, new Request('POST', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/callback'],
        ]));

        $this->assertSame(201, $r->status);
        $this->assertNotEmpty($r->body['client_id']);
    }

    public function testUnRegistroSinRedireccionValidaSeRechaza()
    {
        $r = OAuthHandler::registrar($this->db, new Request('POST', [
            'client_name' => 'Claude',
            'redirect_uris' => ['http://ejemplo.com/cb'],
        ]));

        $this->assertSame(400, $r->status);
        $this->assertSame('invalid_redirect_uri', $r->body['error']);
    }

    // -------------------------------------------------------------- revisar

    /** Todavía no se autorizó nada: no hace falta sesión para mirar el pedido. */
    public function testRevisarNoExigeSesion()
    {
        $this->hayCliente();

        $r = OAuthHandler::revisar($this->db, new Request('GET', [], $this->pedido()));

        $this->assertSame(200, $r->status);
        $this->assertSame('Claude', $r->body['aplicacion']);
    }

    public function testRevisarRechazaUnPedidoInvalido()
    {
        $this->db->onSelect('FROM oauth_clients WHERE client_id', []);

        $r = OAuthHandler::revisar($this->db, new Request('GET', [], $this->pedido()));

        $this->assertSame(400, $r->status);
    }

    // -------------------------------------------------------------- aprobar

    public function testAprobarExigeSesion()
    {
        $r = OAuthHandler::aprobar($this->db, new Request('POST', $this->pedido()));

        $this->assertSame(401, $r->status);
    }

    /** Un permiso no puede concederse a sí mismo. */
    public function testUnaSesionAbiertaPorElPropioMcpNoPuedeAutorizar()
    {
        $r = OAuthHandler::aprobar($this->db, new Request('POST', $this->pedido(), [], $this->sesion(true)));

        $this->assertSame(403, $r->status);
    }

    /**
     * Entre que se mostró la pantalla y se apretó el botón, lo único confiable
     * es lo que llega ahora: se revisa todo de nuevo.
     */
    public function testAprobarVuelveARevisarElPedido()
    {
        $this->db->onSelect('FROM oauth_clients WHERE client_id', []);

        $r = OAuthHandler::aprobar($this->db, new Request('POST', $this->pedido(), [], $this->sesion()));

        $this->assertSame(400, $r->status);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO oauth_codes'));
    }

    public function testAprobarDevuelveElDestinoConElCodigoYElEstado()
    {
        $this->hayCliente();
        $this->db->onWrite('INSERT INTO oauth_codes', 1);

        $r = OAuthHandler::aprobar($this->db, new Request('POST', $this->pedido(), [], $this->sesion()));

        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith('https://claude.ai/callback?', $r->body['redirect_to']);
        $this->assertStringContainsString('code=', $r->body['redirect_to']);
        $this->assertStringContainsString('state=xyz', $r->body['redirect_to']);
    }

    /** El código se emite a nombre de quien está autorizando, no de otro. */
    public function testElCodigoSeEmiteANombreDeQuienAutoriza()
    {
        $this->hayCliente();
        $this->db->onWrite('INSERT INTO oauth_codes', 1);

        OAuthHandler::aprobar($this->db, new Request('POST', $this->pedido(), [], $this->sesion()));

        $this->assertContains(7, $this->db->paramsFor('INSERT INTO oauth_codes'));
    }

    /** Una redirección que ya trae parámetros no puede quedar rota. */
    public function testElDestinoRespetaLosParametrosQueYaTenia()
    {
        $this->db->onSelect('FROM oauth_clients WHERE client_id', [[
            'id' => 1, 'client_id' => 'abc123', 'secreto_hash' => null, 'nombre' => 'Claude',
            'redirect_uris' => json_encode(['https://claude.ai/cb?origen=app']),
        ]]);
        $this->db->onWrite('INSERT INTO oauth_codes', 1);

        $r = OAuthHandler::aprobar($this->db, new Request('POST', $this->pedido([
            'redirect_uri' => 'https://claude.ai/cb?origen=app',
        ]), [], $this->sesion()));

        $this->assertStringContainsString('origen=app&code=', $r->body['redirect_to']);
    }

    // ---------------------------------------------------------------- token

    public function testUnGrantDesconocidoSeRechazaEnElFormatoDeOauth()
    {
        $r = OAuthHandler::token($this->db, new Request('POST', ['grant_type' => 'password']));

        $this->assertSame(400, $r->status);
        $this->assertSame('unsupported_grant_type', $r->body['error']);
    }

    /** El endpoint de token se llama con formulario, no con JSON. */
    public function testElTokenSeCanjeaTambienConFormulario()
    {
        $this->db->onSelect('FROM oauth_codes WHERE code_hash', []);

        $r = OAuthHandler::token($this->db, new Request(
            'POST', [], [], null, [], [],
            ['grant_type' => 'authorization_code', 'code' => 'x', 'client_id' => 'abc123']
        ));

        $this->assertSame('invalid_grant', $r->body['error']);
    }

    // ----------------------------------------------------------- conexiones

    public function testLasConexionesExigenSesion()
    {
        $this->assertSame(401, OAuthHandler::conexiones($this->db, new Request('GET'))->status);
    }

    public function testSePuedeDesconectarUnaAplicacion()
    {
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 1);

        $r = OAuthHandler::conexiones($this->db, new Request('DELETE', [], ['client_id' => 'abc123'], $this->sesion()));

        $this->assertSame(200, $r->status);
        $this->assertTrue($r->body['desconectada']);
    }

    public function testDesconectarAlgoQueNoEstabaConectadoDaNotFound()
    {
        $this->db->onWrite('UPDATE oauth_tokens SET revocado_en', 0);

        $r = OAuthHandler::conexiones($this->db, new Request('DELETE', [], ['client_id' => 'abc123'], $this->sesion()));

        $this->assertSame(404, $r->status);
    }
}
