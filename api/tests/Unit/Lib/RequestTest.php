<?php

namespace Tests\Unit\Lib;

use Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testNormalizaElMetodoAMayusculas()
    {
        $this->assertSame('POST', (new Request('post'))->method);
        $this->assertSame('DELETE', (new Request('Delete'))->method);
    }

    public function testValoresPorDefecto()
    {
        $req = new Request();

        $this->assertSame('GET', $req->method);
        $this->assertSame([], $req->body);
        $this->assertSame([], $req->query);
        $this->assertNull($req->user);
        $this->assertSame([], $req->files);
    }

    public function testInputDevuelveElValorDelCuerpo()
    {
        $req = new Request('POST', ['titulo' => 'Hola']);

        $this->assertSame('Hola', $req->input('titulo'));
    }

    public function testInputDevuelveElDefaultSiFalta()
    {
        $req = new Request('POST', []);

        $this->assertNull($req->input('titulo'));
        $this->assertSame('fallback', $req->input('titulo', 'fallback'));
    }

    public function testInputTrataNullComoAusente()
    {
        // Coherente con isset(), que es lo que usaban los endpoints originales.
        $req = new Request('POST', ['titulo' => null]);

        $this->assertSame('fallback', $req->input('titulo', 'fallback'));
    }

    public function testParamLeeDelQueryString()
    {
        $req = new Request('GET', [], ['slug' => 'mi-pagina']);

        $this->assertSame('mi-pagina', $req->param('slug'));
        $this->assertSame('x', $req->param('otro', 'x'));
    }

    public function testHasReflejaPresenciaEnElCuerpo()
    {
        $req = new Request('POST', ['a' => 0, 'b' => '', 'c' => null]);

        $this->assertTrue($req->has('a'), '0 está presente aunque sea falsy');
        $this->assertTrue($req->has('b'), 'string vacío está presente');
        $this->assertFalse($req->has('c'), 'null cuenta como ausente, igual que isset()');
        $this->assertFalse($req->has('d'));
    }

    public function testUserIdDevuelveElIdDelPayload()
    {
        $req = new Request('GET', [], [], ['user_id' => 42, 'email' => 'a@b.com']);

        $this->assertSame(42, $req->userId());
    }

    public function testUserIdEsNullSinSesion()
    {
        $this->assertNull((new Request())->userId());
    }

    public function testUserIdEsNullSiElPayloadNoTieneUserId()
    {
        $req = new Request('GET', [], [], ['email' => 'a@b.com']);

        $this->assertNull($req->userId());
    }

    public function testMissingListaLasClavesAusentes()
    {
        $req = new Request('POST', ['url' => 'https://x.com', 'text' => 'X']);

        $this->assertSame([], $req->missing(['url', 'text']));
        $this->assertSame(['group_id'], $req->missing(['group_id', 'url', 'text']));
        $this->assertSame(['a', 'b'], $req->missing(['a', 'b']));
    }

    public function testMissingConsideraAusenteUnValorNull()
    {
        $req = new Request('POST', ['url' => null]);

        $this->assertSame(['url'], $req->missing(['url']));
    }

    public function testMissingNoConsideraAusenteUnStringVacio()
    {
        // Igual que isset(): '' está presente. Las validaciones de contenido
        // vacío son responsabilidad de cada handler.
        $req = new Request('POST', ['url' => '']);

        $this->assertSame([], $req->missing(['url']));
    }
}
