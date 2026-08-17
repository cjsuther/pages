<?php

namespace Tests\Unit\Lib;

use ArtMedia;
use Geocodificador;
use PHPUnit\Framework\TestCase;

class ArtMediaTest extends TestCase
{
    private function respuesta()
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/artmedia-eventos.json');
    }

    /** Geocodificador que no sale a la red y anota qué direcciones le pidieron. */
    private function geoFalso(array &$pedidas, $coords = ['latitud' => -34.586, 'longitud' => -58.451])
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
        $json = $this->respuesta();
        $pedidos = [];
        $pedidas = [];

        $lector = function ($url) use (&$pedidos, $json) {
            $pedidos[] = $url;

            return $json;
        };

        return (new ArtMedia($lector, $this->geoFalso($pedidas)))->eventos($parametros, new \stdClass());
    }

    // -------------------------------------------------------------- respuesta

    public function testLeeLosEventosDelSobreDeLaApi()
    {
        $this->assertCount(5, ArtMedia::eventosDeLaRespuesta($this->respuesta()));
    }

    /** El body a veces viaja como objeto y a veces como texto JSON. */
    public function testEntiendeElBodyComoTexto()
    {
        $sobre = json_encode(['statusCode' => 200, 'body' => json_encode(['events' => [['id' => 1]]])]);

        $this->assertCount(1, ArtMedia::eventosDeLaRespuesta($sobre));
    }

    public function testEntiendeElBodyComoObjeto()
    {
        $sobre = json_encode(['statusCode' => 200, 'body' => ['events' => [['id' => 1], ['id' => 2]]]]);

        $this->assertCount(2, ArtMedia::eventosDeLaRespuesta($sobre));
    }

    public function testUnaRespuestaQueNoSeEntiendeNoRompe()
    {
        $this->assertSame([], ArtMedia::eventosDeLaRespuesta('no es json'));
        $this->assertSame([], ArtMedia::eventosDeLaRespuesta('{"statusCode":500}'));
        $this->assertSame([], ArtMedia::eventosDeLaRespuesta(''));
    }

    // ------------------------------------------------------------------ fecha

    /**
     * El día viaja como medianoche UTC. Convertirlo a la zona local correría
     * los shows al día anterior: esa medianoche son las 21 del día previo en
     * Buenos Aires.
     */
    public function testLaFechaSeTomaTalCualSinConvertirLaZona()
    {
        $this->assertSame('2026-08-20', ArtMedia::fecha('2026-08-20T00:00:00.000Z'));
    }

    public function testUnaFechaQueNoExisteSeDescarta()
    {
        $this->assertNull(ArtMedia::fecha('2026-02-30T00:00:00.000Z'));
    }

    public function testSinFechaNoHayEvento()
    {
        $this->assertNull(ArtMedia::fecha(null));
        $this->assertNull(ArtMedia::fecha(''));
        $this->assertNull(ArtMedia::fecha('mañana'));
    }

    // ------------------------------------------------------------------- hora

    public function testLaHoraViajaSolaOConMinutos()
    {
        $this->assertSame('20:00:00', ArtMedia::hora('20'));
        $this->assertSame('23:30:00', ArtMedia::hora('23:30'));
        $this->assertSame('09:00:00', ArtMedia::hora('9'));
    }

    public function testUnaHoraImposibleNoSeInventa()
    {
        $this->assertNull(ArtMedia::hora('99'));
        $this->assertNull(ArtMedia::hora('20:99'));
        $this->assertNull(ArtMedia::hora('a la nochecita'));
        $this->assertNull(ArtMedia::hora(null));
    }

    // ----------------------------------------------------------------- imagen

    /** Sin imagen cargada, la API igual arma la URL y apunta a un "null". */
    public function testDescartaLaImagenQueApuntaANull()
    {
        $evento = ['imageAgendaUrl' => 'https://centroc.s3.amazonaws.com/null', 'imageUrl' => 'https://centroc.s3.amazonaws.com/null'];

        $this->assertNull(ArtMedia::imagen($evento));
    }

    public function testPrefiereLaImagenDeLaAgenda()
    {
        $evento = ['imageAgendaUrl' => 'https://x/agenda.jpg', 'imageUrl' => 'https://x/otra.jpg'];

        $this->assertSame('https://x/agenda.jpg', ArtMedia::imagen($evento));
    }

    public function testSiNoHayImagenDeAgendaUsaLaOtra()
    {
        $evento = ['imageAgendaUrl' => 'https://centroc.s3.amazonaws.com/null', 'imageUrl' => 'https://x/otra.jpg'];

        $this->assertSame('https://x/otra.jpg', ArtMedia::imagen($evento));
    }

    public function testUnEventoSinNingunaImagenNoRompe()
    {
        $this->assertNull(ArtMedia::imagen([]));
    }

    // ------------------------------------------------------------- normalizar

    public function testNormalizaUnEventoCompleto()
    {
        $crudos = ArtMedia::eventosDeLaRespuesta($this->respuesta());
        $evento = ArtMedia::normalizar($crudos[0]);

        $this->assertSame('740', $evento['id']);
        $this->assertSame('SUSHI UNDERGORUND', $evento['titulo']);
        $this->assertSame('2026-08-20', $evento['fecha']);
        $this->assertSame('20:00:00', $evento['hora']);
        $this->assertStringStartsWith('https://', $evento['imagen']);
        $this->assertStringStartsWith('http', $evento['url']);
        $this->assertNull($evento['precio_desde']);
    }

    /** "C Art Media (Sótano) — Av. Corrientes 6271" ubica mejor que la calle sola. */
    public function testLaDireccionLlevaElLugarYLaSalaAdelante()
    {
        $crudos = ArtMedia::eventosDeLaRespuesta($this->respuesta());
        $evento = ArtMedia::normalizar($crudos[0]);

        $this->assertStringStartsWith('C Art Media (Sótano)', $evento['direccion']);
        $this->assertStringContainsString('Av. Corrientes 6271', $evento['direccion']);
    }

    /** La API publica borradores junto con lo que está al aire. */
    public function testUnBorradorNoEntra()
    {
        $this->assertNull(ArtMedia::normalizar(['id' => 1, 'title' => 'X', 'day' => '2026-08-20T00:00:00.000Z', 'Publicado' => 'no']));
    }

    public function testUnEventoSinTituloSeDescarta()
    {
        $this->assertNull(ArtMedia::normalizar(['id' => 1, 'title' => '  ', 'day' => '2026-08-20T00:00:00.000Z', 'Publicado' => 'si']));
    }

    public function testUnEventoSinIdSeDescarta()
    {
        $this->assertNull(ArtMedia::normalizar(['title' => 'X', 'day' => '2026-08-20T00:00:00.000Z', 'Publicado' => 'si']));
    }

    /** El texto viene del editor del sitio, con HTML y espacios duros. */
    public function testLaDescripcionLlegaLimpiaDeHtml()
    {
        $evento = ArtMedia::normalizar([
            'id' => 5, 'title' => 'Show', 'day' => '2026-08-20T00:00:00.000Z', 'Publicado' => 'si',
            'subTitle' => 'EN EL SÓTANO', 'description' => '<p>Un <strong>planazo</strong>&nbsp;para hoy</p>',
        ]);

        $this->assertSame('EN EL SÓTANO. Un planazo para hoy', $evento['descripcion']);
    }

    public function testSinTextosLaDescripcionQuedaVacia()
    {
        $evento = ArtMedia::normalizar(['id' => 5, 'title' => 'Show', 'day' => '2026-08-20T00:00:00.000Z', 'Publicado' => 'si']);

        $this->assertNull($evento['descripcion']);
    }

    // -------------------------------------------------------------- recorrido

    public function testDevuelveLosEventosPublicados()
    {
        $eventos = $this->correr();

        $this->assertCount(4, $eventos, 'el borrador de la fixture no entra');
        $this->assertSame(-34.586, $eventos[0]['latitud']);
        $this->assertArrayNotHasKey('sala', $eventos[0], 'la sala es interna, no parte del evento');
    }

    /** Un pedido por corrida: la cartelera entera viene junta. */
    public function testPideLaCarteleraUnaSolaVez()
    {
        $this->correr([], $pedidos);

        $this->assertCount(1, $pedidos);
        $this->assertStringContainsString('/prod/events', $pedidos[0]);
    }

    public function testGeocodificaUnaSolaVez()
    {
        $this->correr([], $pedidos, $pedidas);

        $this->assertCount(1, $pedidas);
    }

    /**
     * Todos los shows comparten dirección: si no se puede geocodificar no
     * entra ninguno, y pedir la cartelera sería trabajo tirado.
     */
    public function testSinCoordenadasNoPideLaCartelera()
    {
        $pedidos = [];
        $pedidas = [];
        $lector = function ($url) use (&$pedidos) {
            $pedidos[] = $url;

            return '';
        };

        $art = new ArtMedia($lector, $this->geoFalso($pedidas, null));

        $this->assertSame([], $art->eventos([], new \stdClass()));
        $this->assertSame([], $pedidos);
    }

    public function testElFiltroDejaSoloLoQueInteresa()
    {
        $eventos = $this->correr(['filtro' => 'bandalos']);

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('BANDALOS', $eventos[0]['titulo']);
    }

    public function testSePuedeTraerSoloUnaSala()
    {
        $eventos = $this->correr(['sala' => 'sotano']);

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('(Sótano)', $eventos[0]['direccion']);
    }

    public function testRespetaElTopeDeEventos()
    {
        $this->assertCount(2, $this->correr(['max_eventos' => 2]));
    }

    public function testSiLaApiNoRespondeDevuelveVacio()
    {
        $pedidas = [];
        $art = new ArtMedia(function () { return ''; }, $this->geoFalso($pedidas));

        $this->assertSame([], $art->eventos([], new \stdClass()));
    }
}
