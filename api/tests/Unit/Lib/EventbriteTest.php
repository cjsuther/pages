<?php

namespace Tests\Unit\Lib;

use Eventbrite;
use PHPUnit\Framework\TestCase;

class EventbriteTest extends TestCase
{
    /** HTML real de una búsqueda, recortado a cinco eventos. */
    private function htmlReal()
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/eventbrite-busqueda.html');
    }

    private function unEvento(array $overrides = [])
    {
        return array_merge([
            'id' => '1997055647200',
            'name' => 'MICROFONO MALDITO Show de Stand Up',
            'summary' => 'Un formato especial',
            'start_date' => '2026-08-19',
            'start_time' => '21:00',
            'url' => 'https://www.eventbrite.com.ar/e/microfono-maldito-1997055647200',
            'image' => ['url' => 'https://img.evbuc.com/foto.jpg'],
            'is_online_event' => false,
            'is_cancelled' => null,
            'primary_venue' => [
                'name' => 'Blue Velvet',
                'address' => [
                    'latitude' => '-34.6151736',
                    'longitude' => '-58.3730221',
                    'localized_address_display' => 'Bolívar 624, San Telmo',
                ],
            ],
        ], $overrides);
    }

    // ------------------------------------------------------- lectura del HTML

    public function testEncuentraLosEventosEnElJsonEmbebido()
    {
        $eventos = Eventbrite::eventosDelHtml($this->htmlReal());

        $this->assertCount(5, $eventos);
        $this->assertArrayHasKey('name', $eventos[0]);
    }

    /**
     * Si Eventbrite cambia la página, esto devuelve vacío y el importador lo
     * reporta como "¿cambió el sitio?" en vez de dar la corrida por buena.
     */
    public function testUnHtmlSinElJsonNoRompe()
    {
        $this->assertSame([], Eventbrite::eventosDelHtml('<html><body>nada</body></html>'));
        $this->assertSame([], Eventbrite::eventosDelHtml(''));
    }

    public function testUnJsonRotoNoRompe()
    {
        $this->assertSame([], Eventbrite::eventosDelHtml('<script>window.__SERVER_DATA__ = {roto;</script>'));
    }

    // --------------------------------------------------------- normalización

    public function testNormalizaUnEventoCompleto()
    {
        $e = Eventbrite::normalizar($this->unEvento());

        $this->assertSame('1997055647200', $e['id']);
        $this->assertSame('MICROFONO MALDITO Show de Stand Up', $e['titulo']);
        $this->assertSame('2026-08-19', $e['fecha']);
        $this->assertSame('21:00:00', $e['hora']);
        $this->assertSame('-34.6151736', $e['latitud']);
    }

    /** El nombre del lugar ubica mejor que la calle sola. */
    public function testLaDireccionLlevaElLugarAdelante()
    {
        $e = Eventbrite::normalizar($this->unEvento());

        $this->assertStringContainsString('Blue Velvet', $e['direccion']);
        $this->assertStringContainsString('Bolívar 624', $e['direccion']);
    }

    /** Rezonar necesita coordenadas: sin ellas el evento no se puede ubicar. */
    public function testSeDescartaUnEventoSinCoordenadas()
    {
        $sinCoords = $this->unEvento(['primary_venue' => ['name' => 'X', 'address' => []]]);

        $this->assertNull(Eventbrite::normalizar($sinCoords));
    }

    public function testSeDescartaUnEventoOnline()
    {
        $this->assertNull(Eventbrite::normalizar($this->unEvento(['is_online_event' => true])));
    }

    public function testSeDescartaUnEventoCancelado()
    {
        $this->assertNull(Eventbrite::normalizar($this->unEvento(['is_cancelled' => true])));
    }

    public function testSeDescartaUnEventoSinFecha()
    {
        $this->assertNull(Eventbrite::normalizar($this->unEvento(['start_date' => null])));
    }

    /**
     * El listado no trae precio. Dejarlo sin dato es lo honesto: ponerle cero
     * lo anunciaría como gratis, que es afirmar algo que no sabemos.
     */
    public function testElPrecioQuedaSinDatoYNoEnCero()
    {
        $this->assertNull(Eventbrite::normalizar($this->unEvento())['precio_desde']);
    }

    /** La columna es TIME: "9:30" tiene que llegar como "09:30:00". */
    public function testLaHoraSeNormalizaAlFormatoDeLaColumna()
    {
        $this->assertSame('09:30:00', Eventbrite::normalizar($this->unEvento(['start_time' => '9:30']))['hora']);
        $this->assertSame('21:00:00', Eventbrite::normalizar($this->unEvento(['start_time' => '21:00']))['hora']);
    }

    public function testSinHoraNoSeInventaUna()
    {
        $this->assertNull(Eventbrite::normalizar($this->unEvento(['start_time' => null]))['hora']);
    }

    public function testElTituloSeLimpiaDeEspaciosYEtiquetas()
    {
        $e = Eventbrite::normalizar($this->unEvento(['name' => "  Show   <b>de</b>\n  Stand Up  "]));

        $this->assertSame('Show de Stand Up', $e['titulo']);
    }

    // -------------------------------------------------------------- recorrido

    public function testRecorreLasPaginasYDevuelveLosEventos()
    {
        $pedidas = [];
        $lector = function ($url) use (&$pedidas) {
            $pedidas[] = $url;
            return file_get_contents(__DIR__ . '/../../Fixtures/eventbrite-busqueda.html');
        };

        $eventos = (new Eventbrite($lector))->eventos(['busqueda' => 'stand-up', 'paginas' => 1]);

        $this->assertNotEmpty($eventos);
        $this->assertStringContainsString('stand-up', $pedidas[0]);
    }

    /** Sin tope, una corrida podría traer la agenda de todo el año. */
    public function testHayUnTopeDePaginas()
    {
        $pedidas = 0;
        $lector = function ($url) use (&$pedidas) {
            $pedidas++;
            return file_get_contents(__DIR__ . '/../../Fixtures/eventbrite-busqueda.html');
        };

        (new Eventbrite($lector))->eventos(['paginas' => 99]);

        $this->assertLessThanOrEqual(Eventbrite::MAX_PAGINAS, $pedidas);
    }

    public function testSiElSitioNoRespondeSeCortaSinRomper()
    {
        $eventos = (new Eventbrite(function () { return ''; }))->eventos(['paginas' => 3]);

        $this->assertSame([], $eventos);
    }

    /** El mismo evento en dos páginas no puede entrar dos veces. */
    public function testNoSeRepitenEventosEntrePaginas()
    {
        $lector = function () {
            return file_get_contents(__DIR__ . '/../../Fixtures/eventbrite-busqueda.html');
        };

        $eventos = (new Eventbrite($lector))->eventos(['paginas' => 2]);
        $ids = array_column($eventos, 'id');

        $this->assertSame(count($ids), count(array_unique($ids)));
    }
}
