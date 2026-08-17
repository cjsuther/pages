<?php

namespace Tests\Unit\Handlers;

use McpHandler;
use Request;
use Tests\Support\HandlerTestCase;

class McpHandlerTest extends HandlerTestCase
{
    private const CLAVE = 'rzn_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcd';

    /** La clave es válida y pertenece a alguien. */
    private function claveValida()
    {
        $this->db->onSelect('FROM api_keys k', [[
            'id' => 3, 'user_id' => 7, 'email' => 'ana@example.com', 'name' => 'Ana',
        ]]);
        $this->db->onWrite('UPDATE api_keys SET ultimo_uso_en', 1);
    }

    private function pedir(array $mensaje, $clave = self::CLAVE, $metodo = 'POST')
    {
        return McpHandler::rpc(
            $this->db,
            new Request($metodo, $mensaje, [], null, [], ['Authorization' => 'Bearer ' . $clave])
        );
    }

    // ------------------------------------------------------------ credencial

    public function testSinClaveNoSeAtiende()
    {
        $this->db->onSelect('FROM api_keys k', []);

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], '');

        $this->assertSame(401, $r->status);
    }

    public function testUnaClaveInvalidaNoSeAtiende()
    {
        $this->db->onSelect('FROM api_keys k', []);

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->assertSame(401, $r->status);
    }

    /** Le dice al cliente que el problema es la credencial, no el pedido. */
    public function testEl401ExplicaQueFaltaLaCredencial()
    {
        $this->db->onSelect('FROM api_keys k', []);

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->assertArrayHasKey('WWW-Authenticate', $r->headers);
    }

    // -------------------------------------------------------------- saludo

    public function testElSaludoDevuelveLaVersionDelProtocolo()
    {
        $this->claveValida();

        $r = $this->pedir([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => McpHandler::PROTOCOLO],
        ]);

        $this->assertSame(McpHandler::PROTOCOLO, $r->body['result']['protocolVersion']);
        $this->assertSame('rezonar', $r->body['result']['serverInfo']['name']);
    }

    /** Si el cliente habla una versión anterior que se sabe, se le sigue. */
    public function testSeAceptaUnaVersionAnteriorDelProtocolo()
    {
        $this->claveValida();

        $r = $this->pedir([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ]);

        $this->assertSame('2024-11-05', $r->body['result']['protocolVersion']);
    }

    /** Ante una versión desconocida se contesta la propia y decide el cliente. */
    public function testConUnaVersionDesconocidaSeOfreceLaPropia()
    {
        $this->claveValida();

        $r = $this->pedir([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '1999-01-01'],
        ]);

        $this->assertSame(McpHandler::PROTOCOLO, $r->body['result']['protocolVersion']);
    }

    public function testElSaludoAnunciaQueHayHerramientas()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        $this->assertArrayHasKey('tools', $r->body['result']['capabilities']);
    }

    // ---------------------------------------------------------- herramientas

    public function testElCatalogoListaLasHerramientas()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $nombres = array_column($r->body['result']['tools'], 'name');

        $this->assertContains('crear_evento', $nombres);
        $this->assertContains('configurar_entradas', $nombres);
        $this->assertContains('cancelar_compra', $nombres);
    }

    /** Sin esquema de entrada el modelo no sabe con qué llamarla. */
    public function testCadaHerramientaTraeSuEsquemaYSuDescripcion()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        foreach ($r->body['result']['tools'] as $herramienta) {
            $this->assertNotEmpty($herramienta['description'], $herramienta['name']);
            $this->assertSame('object', $herramienta['inputSchema']['type'], $herramienta['name']);
        }
    }

    public function testLlamarUnaHerramientaQueNoExisteEsErrorDeParametros()
    {
        $this->claveValida();

        $r = $this->pedir([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'volar', 'arguments' => []],
        ]);

        $this->assertSame(McpHandler::ERROR_PARAMETROS, $r->body['error']['code']);
    }

    public function testLlamarSinNombreDeHerramienta()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => []]);

        $this->assertSame(McpHandler::ERROR_PARAMETROS, $r->body['error']['code']);
    }

    /**
     * Un trabajo que sale mal vuelve como resultado con isError y no como
     * error de protocolo: así el modelo lee el motivo y puede corregir.
     */
    public function testUnTrabajoQueFallaVuelveComoResultadoConIsError()
    {
        $this->claveValida();
        $this->db->onSelect('FROM pages WHERE url_slug', []);

        $r = $this->pedir([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'listar_eventos', 'arguments' => ['pagina' => 'no-existe']],
        ]);

        $this->assertArrayNotHasKey('error', $r->body);
        $this->assertTrue($r->body['result']['isError']);
        $this->assertStringContainsString('no-existe', $r->body['result']['content'][0]['text']);
    }

    public function testUnTrabajoQueSaleBienNoVieneMarcadoComoError()
    {
        $this->claveValida();
        $this->db->onSelect('FROM pages WHERE user_id', [['id' => 5, 'titulo' => 'Mi Página', 'pagina' => 'mi-pagina']]);

        $r = $this->pedir([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'listar_paginas', 'arguments' => []],
        ]);

        $this->assertFalse($r->body['result']['isError']);
        $this->assertStringContainsString('mi-pagina', $r->body['result']['content'][0]['text']);
    }

    // ------------------------------------------------------------ protocolo

    public function testUnMetodoDesconocidoSeInforma()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'bailar']);

        $this->assertSame(McpHandler::ERROR_METODO_DESCONOCIDO, $r->body['error']['code']);
    }

    public function testUnMensajeSinMetodoSeRechaza()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 7]);

        $this->assertSame(McpHandler::ERROR_PEDIDO_INVALIDO, $r->body['error']['code']);
    }

    /** El lote de JSON-RPC se sacó del protocolo en la versión 2025-06-18. */
    public function testNoSeAceptanLotesDeMensajes()
    {
        $this->claveValida();

        $r = $this->pedir([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']]);

        $this->assertSame(McpHandler::ERROR_PEDIDO_INVALIDO, $r->body['error']['code']);
    }

    /** Una notificación no lleva id y no espera respuesta. */
    public function testUnaNotificacionNoSeContesta()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->assertSame(202, $r->status);
    }

    /**
     * El error viaja adentro del sobre de JSON-RPC: un estado HTTP de error
     * haría que el cliente lo trate como caída del transporte.
     */
    public function testLosErroresDeProtocoloViajanConEstado200()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'bailar']);

        $this->assertSame(200, $r->status);
        $this->assertSame(8, $r->body['id']);
        $this->assertSame('2.0', $r->body['jsonrpc']);
    }

    public function testResponderAlPing()
    {
        $this->claveValida();

        $r = $this->pedir(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping']);

        $this->assertArrayHasKey('result', $r->body);
    }

    /** No hay nada que empujar por SSE: las herramientas contestan en el acto. */
    public function testElCanalDeSalidaNoEstaDisponible()
    {
        $r = $this->pedir([], self::CLAVE, 'GET');

        $this->assertSame(405, $r->status);
    }
}
