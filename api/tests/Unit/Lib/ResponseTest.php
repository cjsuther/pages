<?php

namespace Tests\Unit\Lib;

use Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testJsonGuardaEstadoYCuerpo()
    {
        $res = Response::json(418, ['a' => 1]);

        $this->assertSame(418, $res->status);
        $this->assertSame(['a' => 1], $res->body);
        $this->assertFalse($res->isRedirect());
    }

    public function testOkEs200()
    {
        $this->assertSame(200, Response::ok(['x' => 1])->status);
    }

    public function testCreatedEs201()
    {
        $this->assertSame(201, Response::created(['x' => 1])->status);
    }

    public function testErrorUsaLaClaveError()
    {
        $res = Response::error(400, 'Faltan datos');

        $this->assertSame(400, $res->status);
        $this->assertSame(['error' => 'Faltan datos'], $res->body);
    }

    public function testUnauthorizedReplicaElFormatoHeredado()
    {
        $res = Response::unauthorized();

        $this->assertSame(401, $res->status);
        $this->assertSame(['error' => 'Unauthorized'], $res->body);
    }

    public function testNotFoundAceptaMensajePropio()
    {
        $res = Response::notFound('Page not found');

        $this->assertSame(404, $res->status);
        $this->assertSame(['error' => 'Page not found'], $res->body);
    }

    public function testMethodNotAllowedReplicaElFormatoHeredado()
    {
        $res = Response::methodNotAllowed();

        $this->assertSame(405, $res->status);
        $this->assertSame(['error' => 'Method not allowed'], $res->body);
    }

    public function testServerErrorMantieneElPrefijoHeredado()
    {
        // El frontend no lo parsea, pero cambiar el formato rompería cualquier
        // consumidor externo que sí lo haga.
        $res = Response::serverError('algo explotó');

        $this->assertSame(500, $res->status);
        $this->assertSame(['error' => 'Server error: algo explotó'], $res->body);
    }

    public function testRedirectMarcaLaUrl()
    {
        $res = Response::redirect('https://destino.test/x');

        $this->assertTrue($res->isRedirect());
        $this->assertSame('https://destino.test/x', $res->redirectUrl);
        $this->assertSame(302, $res->status);
    }

    public function testRawGuardaContenidoYCabeceras()
    {
        $res = Response::raw(200, '<html></html>', ['Content-Type' => 'text/html']);

        $this->assertSame('<html></html>', $res->raw);
        $this->assertSame(['Content-Type' => 'text/html'], $res->headers);
        $this->assertNull($res->body);
    }

    public function testElCuerpoSeSerializaComoJsonValido()
    {
        $res = Response::ok(['acentos' => 'ñandú', 'nulo' => null, 'num' => 3]);

        $json = json_encode($res->body);

        $this->assertSame(['acentos' => 'ñandú', 'nulo' => null, 'num' => 3], json_decode($json, true));
    }
}
