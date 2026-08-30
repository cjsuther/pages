<?php

namespace Tests\Unit\Lib;

use Dominio;
use PHPUnit\Framework\TestCase;

/**
 * Lo que se guarda tiene que coincidir exactamente con el Host de la visita.
 * Si no coincide, la página no aparece y en el administrador se ve escrita
 * igual: no hay forma de darse cuenta mirando.
 */
class DominioTest extends TestCase
{
    /** @dataProvider formasDeEscribirlo */
    public function testNormalizaLoQueLaGenteTieneAMano($escrito)
    {
        $this->assertSame('maxipeque.com', Dominio::normalizar($escrito));
    }

    public static function formasDeEscribirlo()
    {
        return [
            'pelado'            => ['maxipeque.com'],
            'con www'           => ['www.maxipeque.com'],
            'con https'         => ['https://maxipeque.com'],
            'con http y www'    => ['http://www.maxipeque.com'],
            'con barra final'   => ['https://maxipeque.com/'],
            'con una ruta'      => ['https://maxipeque.com/eventos/123'],
            'con parámetros'    => ['maxipeque.com?utm_source=x'],
            'con puerto'        => ['maxipeque.com:443'],
            'en mayúsculas'     => ['MaxiPeque.COM'],
            'con espacios'      => ['  maxipeque.com  '],
        ];
    }

    public function testConservaLosSubdominios()
    {
        $this->assertSame('eventos.maxipeque.com', Dominio::normalizar('eventos.maxipeque.com'));
    }

    /** www es la excepción: es la misma página, no un subdominio distinto. */
    public function testSacaElWwwPeroNoOtrosSubdominios()
    {
        $this->assertSame('maxipeque.com', Dominio::normalizar('www.maxipeque.com'));
        $this->assertSame('www2.maxipeque.com', Dominio::normalizar('www2.maxipeque.com'));
    }

    /** @dataProvider loQueNoEsUnDominio */
    public function testRechazaLoQueNuncaVaAResolver($valor)
    {
        $this->assertNull(Dominio::normalizar($valor));
    }

    public static function loQueNoEsUnDominio()
    {
        return [
            'vacío'          => [''],
            'sólo espacios'  => ['   '],
            'nulo'           => [null],
            'un número'      => [42],
            'sin punto'      => ['maxipeque'],
            'con espacio'    => ['maxi peque.com'],
            'con acento'     => ['maxipequé.com'],
            'termina en punto' => ['maxipeque.'],
            'tld de una letra' => ['maxipeque.c'],
            'sólo el punto'  => ['.'],
            'guion al final' => ['maxipeque-.com'],
        ];
    }

    public function testElDominioDeRezonarNoSePuedeReclamar()
    {
        $this->assertTrue(Dominio::esPropio('rezon.ar'));
        $this->assertTrue(Dominio::esPropio(Dominio::normalizar('https://www.rezon.ar')));
        $this->assertTrue(Dominio::esPropio('cualquiera.rezon.ar'));
        $this->assertFalse(Dominio::esPropio('maxipeque.com'));
        $this->assertFalse(Dominio::esPropio(null));
    }

    /** No alcanza con que termine parecido: norezon.ar es de otro. */
    public function testNoConfundeUnDominioQueTerminaParecido()
    {
        $this->assertFalse(Dominio::esPropio('norezon.ar'));
    }

    public function testElHostDeLaVisitaSeLeeIgualQueLoGuardado()
    {
        $this->assertSame('maxipeque.com', Dominio::deLaVisita('www.maxipeque.com'));
        $this->assertSame('maxipeque.com', Dominio::deLaVisita('maxipeque.com:443'));
    }
}
