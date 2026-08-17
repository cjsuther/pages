<?php

namespace Tests\Unit\Lib;

use Boleteria;
use PHPUnit\Framework\TestCase;

class BoleteriaTest extends TestCase
{
    private function listado()
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/boleteria-listado.html');
    }

    private function ficha()
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/boleteria-ficha.html');
    }

    // ------------------------------------------------------------- listado

    public function testEncuentraLosEventosDelListado()
    {
        $candidatos = Boleteria::candidatos($this->listado());

        $this->assertNotEmpty($candidatos);
        $this->assertStringStartsWith('https://www.boleteria.com.ar/p/', $candidatos[0]['url']);
    }

    /** El id de la URL es lo que reconoce el mismo evento entre corridas. */
    public function testElIdSaleDeLaUrl()
    {
        $uno = Boleteria::candidatos('<a href="/p/chiste-stand-up/evento/casi-presente-en-cordoba-e2912">x</a>');

        $this->assertSame('2912', $uno[0]['id']);
        $this->assertSame('chiste-stand-up', $uno[0]['productor']);
    }

    /** El listado repite tarjetas: el mismo evento no puede entrar dos veces. */
    public function testNoSeRepitenEventos()
    {
        $html = str_repeat('<a href="/p/x/evento/show-e1">a</a>', 5);

        $this->assertCount(1, Boleteria::candidatos($html));
    }

    public function testUnListadoSinEventosNoRompe()
    {
        $this->assertSame([], Boleteria::candidatos('<html><body>nada</body></html>'));
    }

    /** Se filtra antes de entrar a cada ficha: cada una es un pedido más. */
    public function testElTituloAproximadoPermiteFiltrarSinPedirLaFicha()
    {
        $uno = Boleteria::candidatos('<a href="/p/chiste-stand-up/evento/casi-presente-en-cordoba-e2912">x</a>');

        $this->assertStringContainsString('casi presente', $uno[0]['titulo']);
    }

    // --------------------------------------------------------------- ficha

    public function testLeeElEventoDelSchema()
    {
        $e = Boleteria::deLaFicha($this->ficha(), '2912');

        $this->assertSame('2912', $e['id']);
        $this->assertSame('Casi Presente en Córdoba', $e['titulo']);
        $this->assertSame('2026-11-27', $e['fecha']);
        $this->assertSame('21:00:00', $e['hora']);
    }

    public function testLeeElLugarYLaDireccion()
    {
        $e = Boleteria::deLaFicha($this->ficha(), '2912');

        $this->assertStringContainsString('Studio Theater', $e['direccion']);
        $this->assertStringContainsString('Rosario de Santa Fe 272', $e['direccion']);
    }

    /** El precio es lo que pidió el usuario que se muestre como "desde". */
    public function testLeeElPrecio()
    {
        $this->assertSame(30000.0, Boleteria::deLaFicha($this->ficha(), '2912')['precio_desde']);
    }

    public function testLeeLaImagenYLaDescripcion()
    {
        $e = Boleteria::deLaFicha($this->ficha(), '2912');

        $this->assertStringContainsString('cdn.boleteria.com.ar', $e['imagen']);
        $this->assertStringContainsString('Agustina Aguilar', $e['descripcion']);
    }

    /** Boletería no publica coordenadas: las completa el geocodificador. */
    public function testLaFichaNoTraeCoordenadas()
    {
        $e = Boleteria::deLaFicha($this->ficha(), '2912');

        $this->assertNull($e['latitud']);
        $this->assertNull($e['longitud']);
    }

    public function testUnaFichaSinSchemaSeDescarta()
    {
        $this->assertNull(Boleteria::deLaFicha('<html><body>nada</body></html>', '1'));
    }

    public function testUnaFichaSinFechaSeDescarta()
    {
        $html = '<script type="application/ld+json">{"@type":"Event","name":"X"}</script>';

        $this->assertNull(Boleteria::deLaFicha($html, '1'));
    }

    // -------------------------------------------------------------- detalles

    /**
     * La hora publicada es la local del show. Convertirla a UTC mostraría al
     * público un horario que no es el de la puerta.
     */
    public function testLaHoraSeTomaTalComoLaPublicanSinConvertir()
    {
        $this->assertSame(
            ['fecha' => '2026-11-27', 'hora' => '21:00:00'],
            Boleteria::fechaHora('2026-11-27T21:00-03:00')
        );
    }

    public function testUnaFechaSinHoraNoInventaUna()
    {
        $this->assertSame(['fecha' => '2026-11-27', 'hora' => null], Boleteria::fechaHora('2026-11-27'));
    }

    public function testUnaFechaIlegibleSeDescarta()
    {
        $this->assertNull(Boleteria::fechaHora('el viernes'));
        $this->assertNull(Boleteria::fechaHora(null));
    }

    /** Un cero de Boletería es "gratis"; que falte el dato es distinto. */
    public function testElCeroEsGratisYLaAusenciaEsSinDato()
    {
        $this->assertSame(0.0, Boleteria::precio(['offers' => ['price' => '0.00']]));
        $this->assertNull(Boleteria::precio(['offers' => []]));
        $this->assertNull(Boleteria::precio([]));
    }

    public function testSeAceptanVariasOfertas()
    {
        $this->assertSame(15000.0, Boleteria::precio(['offers' => [['price' => '15000.00']]]));
    }

    // -------------------------------------------------------------- recorrido

    public function testRecorreElListadoYPideLasFichas()
    {
        $pedidos = [];
        $lector = function ($url) use (&$pedidos) {
            $pedidos[] = $url;

            return strpos($url, '/evento/') !== false
                ? file_get_contents(__DIR__ . '/../../Fixtures/boleteria-ficha.html')
                : file_get_contents(__DIR__ . '/../../Fixtures/boleteria-listado.html');
        };

        $eventos = (new Boleteria($lector))->eventos(['max_eventos' => 3]);

        $this->assertNotEmpty($eventos);
        $this->assertSame('https://www.boleteria.com.ar/', $pedidos[0]);
    }

    /** Sin tope, una corrida entraría a las 108 fichas del listado. */
    public function testHayUnTopeDeFichas()
    {
        $fichas = 0;
        $lector = function ($url) use (&$fichas) {
            if (strpos($url, '/evento/') !== false) {
                $fichas++;
                return file_get_contents(__DIR__ . '/../../Fixtures/boleteria-ficha.html');
            }
            return file_get_contents(__DIR__ . '/../../Fixtures/boleteria-listado.html');
        };

        (new Boleteria($lector))->eventos(['max_eventos' => 2]);

        $this->assertSame(2, $fichas);
    }

    public function testElFiltroEvitaPedirFichasQueNoInteresan()
    {
        $fichas = 0;
        $lector = function ($url) use (&$fichas) {
            if (strpos($url, '/evento/') !== false) {
                $fichas++;
                return file_get_contents(__DIR__ . '/../../Fixtures/boleteria-ficha.html');
            }
            return '<a href="/p/rock/evento/recital-e1">x</a><a href="/p/chiste-stand-up/evento/show-e2">y</a>';
        };

        (new Boleteria($lector))->eventos(['filtro' => 'stand up']);

        $this->assertSame(1, $fichas, 'sólo la que matchea el filtro');
    }

    /** Los slugs usan guiones y uno escribe el filtro con espacios. */
    public function testElFiltroIgnoraGuionesYMayusculas()
    {
        $this->assertSame('chiste stand up', Boleteria::paraComparar('Chiste-Stand-Up'));
        $this->assertSame('stand up', Boleteria::paraComparar('  STAND_UP  '));
    }

    public function testSiElSitioNoRespondeDevuelveVacio()
    {
        $this->assertSame([], (new Boleteria(function () { return ''; }))->eventos());
    }
}
