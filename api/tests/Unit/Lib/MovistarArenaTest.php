<?php

namespace Tests\Unit\Lib;

use Geocodificador;
use Importaciones;
use MovistarArena;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Los fixtures son páginas reales del sitio, tal como llegan pedidas con el
 * agente de vistas previas de Facebook: el listado de shows, una ficha de una
 * sola función (Die Toten Hosen) y una de dos (Marc Anthony, 28 y 29 de octubre).
 */
class MovistarArenaTest extends TestCase
{
    const TOTEN_HOSEN = '95bd12b6-b400-4c15-9f05-b9ec2144924d';
    const MARC_ANTHONY = 'fbc721c0-47d7-4e68-a7f5-d7206307675a';

    private function fixture($nombre)
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/' . $nombre);
    }

    /**
     * Lector que sirve el listado y las dos fichas conocidas; cualquier otra
     * ficha llega vacía, como si el sitio no hubiera respondido.
     */
    private function lector(array &$pedidas = [])
    {
        $paginas = [
            MovistarArena::BASE . '/shows'                     => $this->fixture('movistararena-shows.html'),
            MovistarArena::BASE . '/show/' . self::TOTEN_HOSEN  => $this->fixture('movistararena-ficha.html'),
            MovistarArena::BASE . '/show/' . self::MARC_ANTHONY => $this->fixture('movistararena-ficha-varias.html'),
        ];

        return function ($url) use ($paginas, &$pedidas) {
            $pedidas[] = $url;

            return isset($paginas[$url]) ? $paginas[$url] : '';
        };
    }

    /** Geocodificador que no sale a la red y anota qué direcciones le pidieron. */
    private function geoFalso(array &$pedidas, $coords = ['latitud' => -34.594, 'longitud' => -58.447])
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

    private function porId(array $eventos)
    {
        return array_column($eventos, null, 'id');
    }

    // ---------------------------------------------------------------- listado

    public function testElListadoTraeTodosLosShowsConSuPrimeraFecha()
    {
        $shows = MovistarArena::shows($this->fixture('movistararena-shows.html'));

        $this->assertCount(59, $shows);
        $this->assertSame([
            'id'     => self::TOTEN_HOSEN,
            'titulo' => 'Die Toten Hosen',
            'fecha'  => '2026-10-10',
            'imagen' => 'https://www.movistararena.com.ar/static/artistas/784AB_DieTotenHosen_FileFotoFichaListado',
            'url'    => 'https://www.movistararena.com.ar/show/' . self::TOTEN_HOSEN,
        ], $shows[0]);
    }

    public function testElListadoDecodificaLosNombres()
    {
        $titulos = array_column(MovistarArena::shows($this->fixture('movistararena-shows.html')), 'titulo');

        $this->assertContains('SERU GIRAN POR LEBÓN Y AZNAR', $titulos);
        $this->assertContains('Jesse & Joy', $titulos);
        // Sin el espacio que el sitio deja al final.
        $this->assertContains('BERSUIT', $titulos);
    }

    public function testLaFechaDelListadoEsLaPrimera()
    {
        $this->assertSame('2026-10-16', MovistarArena::fechaDelListado('16 octubre 2026 y 1 fecha más'));
        $this->assertSame('2027-03-01', MovistarArena::fechaDelListado('01 Marzo 2027'));
        $this->assertNull(MovistarArena::fechaDelListado('Próximamente'));
        $this->assertNull(MovistarArena::fechaDelListado('31 febrero 2027'));
    }

    // ------------------------------------------------------------------ ficha

    public function testLaFichaTraeLasFuncionesConLaHoraDelShow()
    {
        $ficha = MovistarArena::ficha($this->fixture('movistararena-ficha-varias.html'), '2026-10-28');

        // En la página están 29 y 28, en ese orden: se devuelven ordenadas.
        $this->assertSame([
            ['fecha' => '2026-10-28', 'hora' => '21:00:00'],
            ['fecha' => '2026-10-29', 'hora' => '21:00:00'],
        ], $ficha['funciones']);
        $this->assertSame(90000.0, $ficha['precio']);
    }

    /** La descripción es la del show: termina antes de las condiciones de la sala. */
    public function testLaDescripcionEsSoloLaDelShow()
    {
        $ficha = MovistarArena::ficha($this->fixture('movistararena-ficha.html'), '2026-10-10');

        $this->assertStringStartsWith('Die Toten Hosen se despiden de Argentina. “Futbol Asado', $ficha['descripcion']);
        $this->assertStringEndsWith('No te podes quedar afuera!', $ficha['descripcion']);
        $this->assertSame(50000.0, $ficha['precio']);
    }

    /** Las funciones no dicen el año: una de un mes anterior al de la primera es del año siguiente. */
    public function testUnaFuncionDeEneroDeUnShowDeDiciembreEsDelAnioSiguiente()
    {
        $html = '<div class="evento-row"><div class="fecha"><p>30</p><span>Diciembre</span></div>'
            . '<div class="hora"><p>21:30 hs</p><span>Show</span></div></div>'
            . '<div class="evento-row"><div class="fecha"><p>2</p><span>Enero</span></div>'
            . '<div class="hora"><p>20:00 hs</p><span>Puertas</span></div></div>';

        $this->assertSame([
            ['fecha' => '2026-12-30', 'hora' => '21:30:00'],
            ['fecha' => '2027-01-02', 'hora' => null],
        ], MovistarArena::ficha($html, '2026-12-30')['funciones']);
    }

    public function testElPrecioAceptaCentavosYSinPrecioEsNull()
    {
        $this->assertSame(45000.5, MovistarArena::precio('<p>Entradas desde</p> <span>$ 45.000,50</span>'));
        $this->assertNull(MovistarArena::precio('<p>Agotado</p>'));
    }

    // ---------------------------------------------------------------- eventos

    public function testCadaFuncionEsUnEvento()
    {
        $geoPedidas = [];
        $adaptador = new MovistarArena($this->lector(), $this->geoFalso($geoPedidas));

        $eventos = $this->porId($adaptador->eventos([], new \stdClass()));

        $marc28 = $eventos[self::MARC_ANTHONY . ':2026-10-28'];
        $this->assertSame('Marc Anthony', $marc28['titulo']);
        $this->assertSame('21:00:00', $marc28['hora']);
        $this->assertSame(90000.0, $marc28['precio_desde']);
        $this->assertSame(MovistarArena::DIRECCION, $marc28['direccion']);
        $this->assertSame(-34.594, $marc28['latitud']);
        $this->assertSame('https://www.movistararena.com.ar/show/' . self::MARC_ANTHONY, $marc28['url']);
        $this->assertArrayHasKey(self::MARC_ANTHONY . ':2026-10-29', $eventos);

        // La sala se geocodifica una sola vez por corrida.
        $this->assertSame([MovistarArena::DIRECCION_PARA_MAPA], $geoPedidas);
    }

    /** Si la ficha no responde, el show entra igual con lo del listado. */
    public function testSinFichaElShowEntraConLaFechaDelListado()
    {
        $geoPedidas = [];
        $eventos = $this->porId((new MovistarArena($this->lector(), $this->geoFalso($geoPedidas)))->eventos([], new \stdClass()));

        $bisbal = $eventos['f3b6943e-e25c-486a-8c61-078e7a71c64e:2026-10-12'];
        $this->assertSame('David Bisbal', $bisbal['titulo']);
        $this->assertNull($bisbal['hora']);
        $this->assertNull($bisbal['precio_desde']);
        $this->assertNull($bisbal['descripcion']);
    }

    /** Pasado el tope de fichas, el resto entra sin pedir nada más. */
    public function testRespetaElTopeDeFichas()
    {
        $pedidas = [];
        $geoPedidas = [];
        $adaptador = new MovistarArena($this->lector($pedidas), $this->geoFalso($geoPedidas));

        $eventos = $adaptador->eventos(['max_fichas' => 2], new \stdClass());

        $this->assertCount(3, $pedidas);
        $this->assertCount(59, $eventos);
    }

    public function testElFiltroBuscaEnElTitulo()
    {
        $pedidas = [];
        $geoPedidas = [];
        $adaptador = new MovistarArena($this->lector($pedidas), $this->geoFalso($geoPedidas));

        $eventos = $adaptador->eventos(['filtro' => 'marc anthony'], new \stdClass());

        $this->assertSame([self::MARC_ANTHONY . ':2026-10-28', self::MARC_ANTHONY . ':2026-10-29'], array_column($eventos, 'id'));
        $this->assertCount(2, $pedidas);
    }

    /** Sin el listado no hay nada: el Importador lo informa como "¿cambió el sitio?". */
    public function testSiElSitioNoArmaLaPaginaNoDevuelveNada()
    {
        $geoPedidas = [];
        $adaptador = new MovistarArena(function () {
            return '<html><body>Se perdió la conexión con el servidor.</body></html>';
        }, $this->geoFalso($geoPedidas));

        $this->assertSame([], $adaptador->eventos([], new \stdClass()));
    }

    public function testSinCoordenadasDeLaSalaFallaConUnMotivoClaro()
    {
        $geoPedidas = [];
        $adaptador = new MovistarArena($this->lector(), $this->geoFalso($geoPedidas, null));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no se pudo geocodificar la dirección del Movistar Arena');

        $adaptador->eventos([], new \stdClass());
    }

    public function testEstaRegistradoEnLasImportaciones()
    {
        $this->assertArrayHasKey('movistararena', Importaciones::adaptadores(null));
    }
}
