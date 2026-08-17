<?php

namespace Tests\Unit\Lib;

use Geocodificador;
use Niceto;
use PHPUnit\Framework\TestCase;

class NicetoTest extends TestCase
{
    private function agenda()
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/niceto-agenda.html');
    }

    private function lector()
    {
        $html = $this->agenda();

        return function () use ($html) {
            return $html;
        };
    }

    /** Geocodificador que no sale a la red y anota qué direcciones le pidieron. */
    private function geoFalso(array &$pedidas, $coords = ['latitud' => -34.58, 'longitud' => -58.44])
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

    private function correr(array $parametros = [], &$pedidas = null)
    {
        $pedidas = [];

        return (new Niceto($this->lector(), $this->geoFalso($pedidas)))
            ->eventos($parametros, new \stdClass());
    }

    // -------------------------------------------------------------- tarjetas

    public function testEncuentraLasTarjetasDeLaAgenda()
    {
        $this->assertCount(4, Niceto::tarjetas($this->agenda()));
    }

    public function testUnaAgendaSinEventosNoRompe()
    {
        $this->assertSame([], Niceto::tarjetas('<html><body>nada</body></html>'));
    }

    // ------------------------------------------------------------ fecha y hora

    public function testLeeLaFechaYLaHoraDelBloqueDeNumeros()
    {
        $tarjeta = '<div>16</div><div>AGO<br>2026</div><span>|</span><div>19</div><div>00<br>hs</div>';

        $this->assertSame(
            ['fecha' => '2026-08-16', 'hora' => '19:00:00'],
            Niceto::fechaYHora($tarjeta)
        );
    }

    public function testEntiendeLosMesesEnCastellano()
    {
        $conMes = function ($mes) {
            return Niceto::fechaYHora("<div>3</div><div>$mes<br>2026</div><span>|</span><div>21</div><div>30<br>hs</div>");
        };

        $this->assertSame('2026-01-03', $conMes('ENE')['fecha']);
        $this->assertSame('2026-09-03', $conMes('SEP')['fecha']);
        $this->assertSame('2026-12-03', $conMes('DIC')['fecha']);
    }

    /** Un mes que no se reconoce daría una fecha inventada. */
    public function testUnMesDesconocidoNoDevuelveFecha()
    {
        $this->assertNull(Niceto::fechaYHora('<div>3</div><div>XXX<br>2026</div><span>|</span><div>21</div><div>30<br>hs</div>'));
    }

    public function testUnaTarjetaSinFechaNoDevuelveNada()
    {
        $this->assertNull(Niceto::fechaYHora('<div>un show</div>'));
    }

    // ----------------------------------------------------------------- salas

    public function testDistingueLasDosDirecciones()
    {
        $this->assertSame('humboldt', Niceto::sala('Humboldt | Niceto Club', 'X'));
        $this->assertSame('niceto bar', Niceto::sala('Niceto Bar', 'X'));
        $this->assertSame('niceto club', Niceto::sala('Niceto Club', 'X'));
    }

    /**
     * Los shows de Humboldt se anuncian como "en Humboldt | Niceto Club": si
     * ganara "niceto club" se mandaría a la gente a la dirección equivocada.
     */
    public function testHumboldtGanaAunqueElNombreMencioneAlClub()
    {
        $this->assertSame('humboldt', Niceto::sala('', 'Corapa en Humboldt | Niceto Club (+18)'));
    }

    /** El sitio a veces publica la tarjeta sin el atributo de la sala. */
    public function testSinSalaDeclaradaLaDeduceDelTitulo()
    {
        $this->assertSame('humboldt', Niceto::sala('', 'JONI BOIS en Humboldt| Niceto Club'));
    }

    public function testSiNoSePuedeSaberLaSalaCaeEnLaPrincipal()
    {
        $this->assertSame('niceto club', Niceto::sala('', 'Un show sin sala'));
    }

    // ------------------------------------------------------------ normalizar

    public function testNormalizaUnaTarjetaCompleta()
    {
        $tarjetas = Niceto::tarjetas($this->agenda());
        $evento = Niceto::normalizar($tarjetas[0]);

        $this->assertSame('festipez-2026-en-niceto-club', $evento['id']);
        $this->assertStringContainsString('Festipez', $evento['titulo']);
        $this->assertStringStartsWith('https://venti', $evento['url']);
        $this->assertStringStartsWith('https://', $evento['imagen']);
        $this->assertSame('2026-08-16', $evento['fecha']);
        $this->assertStringContainsString('Niceto Vega 5510', $evento['direccion']);
        $this->assertNull($evento['precio_desde']);
    }

    /** El orden de las tarjetas cambia a diario: la posición duplicaría todo. */
    public function testElIdSaleDeLaUrlDeLaTicketera()
    {
        $tarjeta = '<div class="event-card" data-name="Show" data-lugar="Niceto Bar">'
            . '<div>16</div><div>AGO<br>2026</div><span>|</span><div>19</div><div>00<br>hs</div>'
            . '<a href="https://venti.live/evento/el-show-de-hoy">COMPRAR</a></div>';

        $this->assertSame('el-show-de-hoy', Niceto::normalizar($tarjeta)['id']);
    }

    public function testUnaTarjetaSinEnlaceSeDescarta()
    {
        $tarjeta = '<div class="event-card" data-name="Show" data-lugar="Niceto Bar">'
            . '<div>16</div><div>AGO<br>2026</div><span>|</span><div>19</div><div>00<br>hs</div></div>';

        $this->assertNull(Niceto::normalizar($tarjeta));
    }

    public function testUnaTarjetaSinNombreSeDescarta()
    {
        $this->assertNull(Niceto::normalizar('<div class="event-card"><a href="https://venti.live/evento/x">c</a></div>'));
    }

    // -------------------------------------------------------------- recorrido

    public function testDevuelveLosEventosDeLaAgenda()
    {
        $eventos = $this->correr();

        $this->assertCount(4, $eventos);
        $this->assertSame(-34.58, $eventos[0]['latitud']);
        $this->assertArrayNotHasKey('sala', $eventos[0], 'la sala es interna, no parte del evento');
    }

    /**
     * Son dos direcciones para toda la agenda: preguntar una vez por evento
     * sería castigar al geocodificador sin necesidad.
     */
    public function testGeocodificaCadaDireccionUnaSolaVez()
    {
        $this->correr([], $pedidas);

        $this->assertCount(2, $pedidas);
        $this->assertCount(2, array_unique($pedidas));
    }

    public function testElFiltroDejaSoloLoQueInteresa()
    {
        $eventos = $this->correr(['filtro' => 'festipez']);

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('Festipez', $eventos[0]['titulo']);
    }

    public function testSePuedeTraerSoloUnaSala()
    {
        $eventos = $this->correr(['sala' => 'humboldt']);

        $this->assertCount(2, $eventos);

        foreach ($eventos as $evento) {
            $this->assertStringContainsString('Humboldt 1574', $evento['direccion']);
        }
    }

    public function testRespetaElTopeDeEventos()
    {
        $this->assertCount(2, $this->correr(['max_eventos' => 2]));
    }

    /** Sin coordenadas el evento no se puede ubicar en el mapa. */
    public function testDescartaLosEventosQueNoSePuedenGeocodificar()
    {
        $pedidas = [];
        $niceto = new Niceto($this->lector(), $this->geoFalso($pedidas, null));

        $this->assertSame([], $niceto->eventos([], new \stdClass()));
    }

    public function testSiElSitioNoRespondeDevuelveVacio()
    {
        $niceto = new Niceto(function () { return ''; });

        $this->assertSame([], $niceto->eventos([], new \stdClass()));
    }

    /** El mismo evento repetido en la grilla no puede entrar dos veces. */
    public function testNoSeRepitenEventos()
    {
        $tarjetas = Niceto::tarjetas($this->agenda());
        $repetida = $tarjetas[0] . $tarjetas[0] . $tarjetas[0];
        $pedidas = [];

        $niceto = new Niceto(function () use ($repetida) {
            return '<section>' . $repetida . '</section>';
        }, $this->geoFalso($pedidas));

        $this->assertCount(1, $niceto->eventos([], new \stdClass()));
    }
}
