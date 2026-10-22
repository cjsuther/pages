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

    /** Una tarjeta que no cierra en </section> igual tiene que entrar. */
    public function testEncuentraLaUltimaTarjetaAunqueLaGrillaNoCierre()
    {
        $suelta = '<div class="event-card" data-name="Show" data-lugar="Niceto Bar">'
            . '<a href="https://venti.live/evento/x">COMPRAR</a>';

        $this->assertCount(1, Niceto::tarjetas($suelta));
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

    // ----------------------------------------------------------------- precio

    private function muestra($archivo)
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/precios/niceto/' . $archivo);
    }

    /**
     * Corre la agenda de muestra sin precios, con una ficha de Venti por URL.
     * Lo que no está en $fichas responde vacío, como una ficha que falló.
     */
    private function correrConFichas(array $fichas, &$pedidos = null)
    {
        $agenda = $this->muestra('agenda-tarjetas-sin-precio.html');
        $pedidos = [];
        $pedidas = [];

        $lector = function ($url) use ($agenda, $fichas, &$pedidos) {
            $pedidos[] = $url;

            if (strpos($url, 'nicetoclub.com/agenda') !== false) {
                return $agenda;
            }

            return isset($fichas[$url]) ? $fichas[$url] : '';
        };

        $eventos = (new Niceto($lector, $this->geoFalso($pedidas)))->eventos([], new \stdClass());
        $porId = [];

        foreach ($eventos as $evento) {
            $porId[$evento['id']] = $evento;
        }

        return $porId;
    }

    public function testElPrecioSaleDeLaFichaDeVenti()
    {
        $eventos = $this->correrConFichas([
            'https://venti.live/evento/antamarta-en-niceto-club-18-a-os' => $this->muestra('venti-pago-15000.html'),
        ], $pedidos);

        $this->assertSame(15000.0, $eventos['antamarta-en-niceto-club-18-a-os']['precio_desde']);
        $this->assertContains('https://venti.live/evento/antamarta-en-niceto-club-18-a-os', $pedidos);
    }

    /** "Desde $ 65.000" en la ficha: offers.price ya es el mínimo. */
    public function testElPrecioDeVentiEsElDesde()
    {
        $this->assertSame(65000.0, Niceto::precioDeVenti($this->muestra('venti-pago-65000-desde.html')));
    }

    /**
     * Un 0 de Venti es una reserva con pago en puerta, con otras entradas
     * pagas en el mismo show. Anunciarlo como gratis sería mentir.
     */
    public function testUnCeroDeVentiQuedaSinPrecioYNoGratis()
    {
        $this->assertNull(Niceto::precioDeVenti($this->muestra('venti-precio-0-reserva-pago-en-puerta.html')));

        $eventos = $this->correrConFichas([
            'https://venti.live/evento/la-m-quina-camale-n-solito-world-tour-22-oct-en-niceto-bar' => $this->muestra('venti-precio-0-reserva-pago-en-puerta.html'),
        ]);

        $this->assertNull($eventos['la-m-quina-camale-n-solito-world-tour-22-oct-en-niceto-bar']['precio_desde']);
    }

    /** Una ficha que falla o viene sin JSON-LD no saca al evento de la agenda. */
    public function testUnaFichaQueFallaDejaElEventoSinPrecio()
    {
        $eventos = $this->correrConFichas([
            'https://venti.live/evento/antamarta-en-niceto-club-18-a-os' => '<html><body>sin schema</body></html>',
        ]);

        $this->assertCount(2, $eventos);
        $this->assertNull($eventos['antamarta-en-niceto-club-18-a-os']['precio_desde']);
        $this->assertNull($eventos['la-m-quina-camale-n-solito-world-tour-22-oct-en-niceto-bar']['precio_desde']);
    }

    public function testSePideUnaFichaPorEvento()
    {
        $this->correrConFichas([], $pedidos);

        $this->assertCount(3, $pedidos, 'la agenda y una ficha por cada uno de los dos eventos');
    }

    public function testSoloSePidenFichasDeVenti()
    {
        $this->assertTrue(Niceto::esDeVenti('https://venti.live/evento/x'));
        $this->assertTrue(Niceto::esDeVenti('https://www.venti.live/evento/x'));
        $this->assertFalse(Niceto::esDeVenti('https://venti.live.otro.com/evento/x'));
        $this->assertFalse(Niceto::esDeVenti('https://ventiladores.com/x'));
        $this->assertFalse(Niceto::esDeVenti(''));
    }

    /** La ficha de un evento repetido en la grilla se pide una sola vez. */
    public function testUnEventoRepetidoNoPideDosVecesLaFicha()
    {
        $tarjetas = Niceto::tarjetas($this->agenda());
        $repetida = '<section>' . $tarjetas[0] . $tarjetas[0] . '</section>';
        $pedidos = [];
        $pedidas = [];

        $niceto = new Niceto(function ($url) use ($repetida, &$pedidos) {
            $pedidos[] = $url;

            return $repetida;
        }, $this->geoFalso($pedidas));

        $niceto->eventos([], new \stdClass());

        $this->assertCount(2, $pedidos, 'la agenda y una sola ficha');
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
