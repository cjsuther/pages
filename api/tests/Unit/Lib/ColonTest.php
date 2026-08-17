<?php

namespace Tests\Unit\Lib;

use Colon;
use Geocodificador;
use PHPUnit\Framework\TestCase;

class ColonTest extends TestCase
{
    private function calendario()
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/colon-calendario.html');
    }

    /** Geocodificador que no sale a la red y anota qué direcciones le pidieron. */
    private function geoFalso(array &$pedidas, $coords = ['latitud' => -34.601, 'longitud' => -58.383])
    {
        return new class($pedidas, $coords) extends Geocodificador {
            private $pedidas;
            private $coords;

            public function __construct(&$pedidas, $coords)
            {
                $this->pedidas = &$pedidas;
                $this->coords = $coords;
            }

            public function coordenadas($db, $direccion)
            {
                $this->pedidas[] = $direccion;

                return $this->coords;
            }
        };
    }

    private function correr(array $parametros = [], &$pedidos = null, &$pedidas = null)
    {
        $html = $this->calendario();
        $pedidos = [];
        $pedidas = [];

        $lector = function ($url) use (&$pedidos, $html) {
            $pedidos[] = $url;

            return $html;
        };

        return (new Colon($lector, $this->geoFalso($pedidas)))->eventos($parametros, new \stdClass());
    }

    // ----------------------------------------------------------------- items

    public function testEncuentraLasFuncionesDelCalendario()
    {
        $this->assertCount(3, Colon::items($this->calendario()));
    }

    /**
     * El <style> de la página nombra las mismas clases que el calendario:
     * buscar sobre el documento entero devolvería reglas de CSS.
     */
    public function testNoConfundeLasReglasDeCssConFunciones()
    {
        $html = '<style>.calendar-item{color:red}</style><div class="calendar-item">'
            . '<div class="day-number">3</div></div>';

        $this->assertCount(1, Colon::items($html));
    }

    public function testUnCalendarioVacioNoRompe()
    {
        $this->assertSame([], Colon::items('<html><body>sin funciones</body></html>'));
    }

    // --------------------------------------------------------------- horario

    public function testLeeElDiaYLaHora()
    {
        $item = '<div class="day-number">18.</div><div class="day-hour">mar_17.30 hs</div>';

        $this->assertSame(['dia' => 18, 'hora' => '17:30:00'], Colon::horario($item));
    }

    /** El sitio escribe la hora con punto; la columna espera dos puntos. */
    public function testAceptaLaHoraConPuntoOConDosPuntos()
    {
        $conPunto = '<div class="day-number">5</div><div class="day-hour">vie_20.00 hs</div>';
        $conDosPuntos = '<div class="day-number">5</div><div class="day-hour">vie_20:00 hs</div>';

        $this->assertSame('20:00:00', Colon::horario($conPunto)['hora']);
        $this->assertSame('20:00:00', Colon::horario($conDosPuntos)['hora']);
    }

    public function testUnaFuncionSinHorarioNoDevuelveNada()
    {
        $this->assertNull(Colon::horario('<div class="day-number">18.</div>'));
    }

    public function testUnHorarioImposibleSeDescarta()
    {
        $item = '<div class="day-number">18.</div><div class="day-hour">mar_99.99 hs</div>';

        $this->assertNull(Colon::horario($item));
    }

    // ------------------------------------------------------------ normalizar

    public function testNormalizaUnaFuncionCompleta()
    {
        $item = Colon::items($this->calendario())[0];
        $evento = Colon::normalizar($item, 2026, 8);

        $this->assertSame('Entre encantos y revelaciones', $evento['titulo']);
        $this->assertSame('2026-08-18', $evento['fecha']);
        $this->assertSame('17:30:00', $evento['hora']);
        $this->assertSame('Clásica Joven', $evento['descripcion']);
        $this->assertStringStartsWith('https://', $evento['imagen']);
        $this->assertStringContainsString('Cerrito 628', $evento['direccion']);
        $this->assertNull($evento['precio_desde']);
    }

    /** Lo que la persona busca es comprar, no leer la ficha. */
    public function testPrefiereElEnlaceDeLaBoleteria()
    {
        $evento = Colon::normalizar(Colon::items($this->calendario())[0], 2026, 8);

        $this->assertStringContainsString('entradasba', $evento['url']);
    }

    public function testSinBoleteriaQuedaLaFichaDeLaProduccion()
    {
        $item = '<div class="calendar-item"><div class="day-number">4</div>'
            . '<div class="day-hour">vie_20.00 hs</div>'
            . '<h1 class="colon-serif-regular">Un estreno</h1>'
            . '<a href="https://teatrocolon.org.ar/produccion/un-estreno/">+ info</a></div>';

        $evento = Colon::normalizar($item, 2026, 9);

        $this->assertSame('https://teatrocolon.org.ar/produccion/un-estreno/', $evento['url']);
    }

    /**
     * Una producción se da varias veces. Si el identificador fuera sólo la
     * producción, la segunda función pisaría a la primera y la agenda
     * mostraría una sola fecha de todo el ciclo.
     */
    public function testCadaFuncionTieneSuPropioIdentificador()
    {
        $items = Colon::items($this->calendario());

        $primera = Colon::normalizar($items[1], 2026, 8);
        $segunda = Colon::normalizar($items[2], 2026, 8);

        $this->assertSame($primera['titulo'], $segunda['titulo'], 'la fixture repite la producción');
        $this->assertNotSame($primera['id'], $segunda['id']);
    }

    public function testElIdentificadorSaleDeLaProduccion()
    {
        $evento = Colon::normalizar(Colon::items($this->calendario())[1], 2026, 8);

        $this->assertStringStartsWith('otello-2026-08-18', $evento['id']);
    }

    public function testUnaFuncionSinTituloSeDescarta()
    {
        $item = '<div class="day-number">4</div><div class="day-hour">vie_20.00 hs</div>';

        $this->assertNull(Colon::normalizar($item, 2026, 9));
    }

    /** El 31 de un mes de 30 días es un error de lectura, no una función. */
    public function testUnaFechaQueNoExisteSeDescarta()
    {
        $item = '<div class="day-number">31</div><div class="day-hour">vie_20.00 hs</div>'
            . '<h1 class="colon-serif-regular">Imposible</h1>';

        $this->assertNull(Colon::normalizar($item, 2026, 9));
    }

    // ------------------------------------------------------------- los meses

    public function testSumaMesesDentroDelMismoAnio()
    {
        $this->assertSame([2026, 10], Colon::mesSumado([2026, 8], 2));
    }

    public function testSumarMesesCruzaElCambioDeAnio()
    {
        $this->assertSame([2027, 1], Colon::mesSumado([2026, 11], 2));
        $this->assertSame([2027, 3], Colon::mesSumado([2026, 12], 3));
    }

    public function testDiciembreMasCeroSigueSiendoDiciembre()
    {
        $this->assertSame([2026, 12], Colon::mesSumado([2026, 12], 0));
    }

    // ------------------------------------------------------------- recorrido

    public function testPideUnMesPorCadaMesPedido()
    {
        $this->correr(['meses' => 3], $pedidos);

        $this->assertCount(3, $pedidos);

        foreach ($pedidos as $url) {
            $this->assertStringContainsString('/calendario/?a=', $url);
        }
    }

    /** La dirección es una sola: preguntarla por función sería absurdo. */
    public function testGeocodificaUnaSolaVez()
    {
        $this->correr(['meses' => 3], $pedidos, $pedidas);

        $this->assertCount(1, $pedidas);
    }

    /**
     * Todas las funciones comparten dirección: si no se puede geocodificar no
     * entra ninguna, y pedir los meses sería trabajo tirado.
     */
    public function testSinCoordenadasNoPideElCalendario()
    {
        $pedidos = [];
        $pedidas = [];
        $lector = function ($url) use (&$pedidos) {
            $pedidos[] = $url;

            return '';
        };

        $colon = new Colon($lector, $this->geoFalso($pedidas, null));

        $this->assertSame([], $colon->eventos([], new \stdClass()));
        $this->assertSame([], $pedidos);
    }

    public function testElFiltroDejaSoloLoQueInteresa()
    {
        $eventos = $this->correr(['meses' => 1, 'filtro' => 'otello']);

        $this->assertCount(2, $eventos);

        foreach ($eventos as $evento) {
            $this->assertSame('Otello', $evento['titulo']);
        }
    }

    public function testRespetaElTopeDeFunciones()
    {
        $this->assertCount(2, $this->correr(['meses' => 2, 'max_eventos' => 2]));
    }

    /** El mismo mes pedido dos veces no puede duplicar la agenda. */
    public function testNoSeRepitenFunciones()
    {
        $eventos = $this->correr(['meses' => 3]);

        $ids = array_column($eventos, 'id');

        $this->assertSame($ids, array_unique($ids));
    }

    public function testSiElSitioNoRespondeDevuelveVacio()
    {
        $pedidas = [];
        $colon = new Colon(function () { return ''; }, $this->geoFalso($pedidas));

        $this->assertSame([], $colon->eventos([], new \stdClass()));
    }
}
