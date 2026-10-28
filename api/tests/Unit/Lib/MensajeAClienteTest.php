<?php

namespace Tests\Unit\Lib;

use MensajeACliente;
use PHPUnit\Framework\TestCase;

class MensajeAClienteTest extends TestCase
{
    // --------------------------------------------------------- variables

    public function testConNombreReemplazaElCompletoYElPrimero()
    {
        $this->assertSame(
            'Hola Ana, gracias Ana María Gómez',
            MensajeACliente::reemplazar('Hola {primer_nombre}, gracias {nombre}', '  Ana   María Gómez ')
        );
    }

    public function testSinNombreUsaElTextoDeReemplazo()
    {
        $this->assertSame('Hola amiga, gracias amiga', MensajeACliente::reemplazar('Hola {primer_nombre}, gracias {nombre}', '', 'amiga'));
    }

    public function sinNombreNiReemplazo()
    {
        return [
            'antes de una coma' => ['Hola {primer_nombre},', 'Hola,'],
            'antes de un signo' => ['¡Hola {primer_nombre}!', '¡Hola!'],
            'al final de la línea' => ["Gracias {nombre}\nVolvé pronto", "Gracias\nVolvé pronto"],
            'entre palabras' => ['Hola {primer_nombre} y bienvenido', 'Hola y bienvenido'],
            'al principio' => ['{primer_nombre} te esperamos', 'te esperamos'],
            'sola' => ['{nombre}', ''],
        ];
    }

    /** @dataProvider sinNombreNiReemplazo */
    public function testSinNombreNiReemplazoNoQuedaElEspacioColgando($plantilla, $esperado)
    {
        $this->assertSame($esperado, MensajeACliente::reemplazar($plantilla, '   ', ''));
    }

    public function testElTextoSinVariablesQuedaIgual()
    {
        $this->assertSame('Hola , todos', MensajeACliente::reemplazar('Hola , todos', ''));
    }

    // -------------------------------------------------------------- html

    public function testParrafosSaltosYLinks()
    {
        $html = MensajeACliente::html("Hola\nMirá https://rezon.ar/la-sala.\n\nChau");

        $this->assertSame(
            '<p style="margin:0 0 1em">Hola<br>' . "\n"
            . 'Mirá <a href="https://rezon.ar/la-sala">https://rezon.ar/la-sala</a>.</p>'
            . '<p style="margin:0 0 1em">Chau</p>',
            $html
        );
    }

    public function testEscapaAntesDeConvertir()
    {
        $html = MensajeACliente::html('<b>x</b> javascript:alert(1) https://x.com/?a=1&b="2"');

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('<a href="https://x.com/?a=1&amp;b=">', $html);
    }

    public function testUnNombreConScriptNoInyectaNada()
    {
        $mail = MensajeACliente::armar(
            ['asunto' => 'Hola {primer_nombre}', 'cuerpo' => 'Hola {nombre}', 'reemplazo_nombre' => ''],
            ['email' => 'x@example.com', 'nombre' => '<script>alert(1)</script> Pérez'],
            'La Sala',
            'https://frontend.test/baja?t=abc'
        );

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Pérez', $mail['html']);
        // El texto plano no es HTML: va tal cual.
        $this->assertStringContainsString('Hola <script>alert(1)</script> Pérez', $mail['texto']);
    }

    public function testElMailLlevaElPieDeBajaEnHtmlYEnTexto()
    {
        $mail = MensajeACliente::armar(
            ['asunto' => "Novedades\npara {primer_nombre}", 'cuerpo' => 'Hola {primer_nombre},', 'reemplazo_nombre' => ''],
            ['email' => 'ana@example.com', 'nombre' => 'Ana Gómez'],
            'La Sala',
            'https://frontend.test/baja?t=a&b'
        );

        $this->assertSame('Novedades para Ana', $mail['asunto']);
        $this->assertStringContainsString('<a href="https://frontend.test/baja?t=a&amp;b" style="color:#777">No quiero recibir más mails de La Sala</a>', $mail['html']);
        $this->assertSame("Hola Ana,\n\n--\nNo quiero recibir más mails de La Sala: https://frontend.test/baja?t=a&b", $mail['texto']);
    }

    // --------------------------------------------------------- validación

    public function testValidaYNormaliza()
    {
        $res = MensajeACliente::validar([
            'nombre' => ' Aviso ', 'asunto' => "Hola\r\n{nombre}", 'cuerpo' => "  a\r\nb  ", 'reemplazo_nombre' => ' amigo ',
        ]);

        $this->assertSame(['nombre' => 'Aviso', 'asunto' => 'Hola {nombre}', 'cuerpo' => "a\nb", 'reemplazo_nombre' => 'amigo'], $res['datos']);
    }

    public function invalidos()
    {
        return [
            'sin nombre' => [['asunto' => 'a', 'cuerpo' => 'b'], 'nombre'],
            'sin asunto' => [['nombre' => 'n', 'cuerpo' => 'b'], 'asunto'],
            'sin cuerpo' => [['nombre' => 'n', 'asunto' => 'a', 'cuerpo' => '  '], 'texto'],
            'cuerpo enorme' => [['nombre' => 'n', 'asunto' => 'a', 'cuerpo' => str_repeat('x', 20001)], 'largo'],
            'reemplazo largo' => [['nombre' => 'n', 'asunto' => 'a', 'cuerpo' => 'b', 'reemplazo_nombre' => str_repeat('x', 101)], 'largo'],
        ];
    }

    /** @dataProvider invalidos */
    public function testRechazaLoIncompleto(array $entrada, $fragmento)
    {
        $res = MensajeACliente::validar($entrada);

        $this->assertArrayHasKey('error', $res);
        $this->assertStringContainsString($fragmento, $res['error']);
    }

    public function testEnUnEnvioElNombreInternoNoHaceFalta()
    {
        $this->assertArrayHasKey('datos', MensajeACliente::validar(['asunto' => 'a', 'cuerpo' => 'b'], false));
        $this->assertArrayHasKey('error', MensajeACliente::validar('texto suelto', false));
    }
}
