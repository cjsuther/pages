<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use Request;
use Response;

/**
 * Base para los tests de handlers: atajos para construir peticiones y afirmar
 * sobre respuestas, de modo que cada test se lea como el caso que describe.
 */
abstract class HandlerTestCase extends TestCase
{
    /** @var FakePdo */
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakePdo();
    }

    // ------------------------------------------------------------ peticiones

    /** Usuario autenticado de ejemplo, con el shape que emite JWT::decode(). */
    protected function user($id = 1, $email = 'user@test.local')
    {
        return ['user_id' => $id, 'email' => $email];
    }

    protected function get(array $query = [], $user = null)
    {
        return new Request('GET', [], $query, $user, [], $this->headersPara($user));
    }

    protected function post(array $body = [], $user = null, array $query = [])
    {
        return new Request('POST', $body, $query, $user, [], $this->headersPara($user));
    }

    protected function put(array $body = [], $user = null, array $query = [])
    {
        return new Request('PUT', $body, $query, $user, [], $this->headersPara($user));
    }

    protected function delete(array $query = [], $user = null, array $body = [])
    {
        return new Request('DELETE', $body, $query, $user, [], $this->headersPara($user));
    }

    /**
     * Petición con cabecera Authorization pero sin usuario resuelto: modela un
     * token presente pero inválido o expirado.
     */
    protected function conTokenInvalido($method = 'GET', array $query = [], array $body = [])
    {
        return new Request($method, $body, $query, null, [], ['Authorization' => 'Bearer token-invalido']);
    }

    /**
     * Un usuario autenticado siempre llegó con su cabecera Authorization;
     * mantenerlas sincronizadas evita tests que no podrían darse en producción.
     */
    private function headersPara($user)
    {
        return $user === null ? [] : ['Authorization' => 'Bearer token-de-prueba'];
    }

    // ------------------------------------------------------------ respuestas

    protected function assertStatus($expected, Response $response, $message = '')
    {
        $this->assertSame(
            $expected,
            $response->status,
            $message !== '' ? $message : 'Código HTTP inesperado. Cuerpo: ' . json_encode($response->body)
        );
    }

    protected function assertError($expectedStatus, Response $response, $expectedMessageFragment = null)
    {
        $this->assertStatus($expectedStatus, $response);
        $this->assertIsArray($response->body);
        $this->assertArrayHasKey('error', $response->body, 'La respuesta de error no tiene la clave "error"');

        if ($expectedMessageFragment !== null) {
            $this->assertStringContainsString($expectedMessageFragment, $response->body['error']);
        }
    }

    protected function assertJsonKey($key, Response $response)
    {
        $this->assertIsArray($response->body);
        $this->assertArrayHasKey($key, $response->body);
    }

    /** Afirma que no se ejecutó ninguna escritura: útil en los tests de autorización. */
    protected function assertNoWrites()
    {
        foreach ($this->db->log() as $entry) {
            $sql = strtoupper($entry['sql']);
            foreach (['INSERT ', 'UPDATE ', 'DELETE '] as $verb) {
                $this->assertStringNotContainsString(
                    $verb,
                    $sql,
                    'Se ejecutó una escritura que no debería haber ocurrido: ' . $entry['sql']
                );
            }
        }
    }
}
