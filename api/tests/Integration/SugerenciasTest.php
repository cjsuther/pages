<?php

namespace Tests\Integration;

use Entradas;
use ProductosDigitales;
use SugerenciasHandler;
use Tests\Support\IntegracionTestCase;

/**
 * "También te puede interesar" contra una MariaDB de verdad (#30): el orden
 * de prioridad entre la página donde se ve X (V), su dueña (O) y sus
 * colaboradoras, lo que nunca se sugiere y el fallback por cercanía.
 */
class SugerenciasTest extends IntegracionTestCase
{
    /** El Obelisco: el punto de referencia de los tests de cercanía. */
    const LAT = -34.6037;
    const LNG = -58.3816;

    /** Grados de latitud por kilómetro, aproximado. */
    const GRADOS_POR_KM = 0.009;

    private $duena;
    private $vista;
    private $otra;

    /** @var array<int, array{eventos: int, productos: int}> grupos por página */
    private $grupos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->duena = $this->paginaNueva('la-sala', 'La Sala');
        $this->vista = $this->paginaNueva('la-banda', 'La Banda');
        $this->otra = $this->paginaNueva('el-club', 'El Club');
    }

    // ------------------------------------------------------------- datos

    private function paginaNueva($slug, $titulo)
    {
        $pageId = $this->pagina($this->usuario("$slug@test.local", $titulo), $slug, $titulo);
        $this->grupos[$pageId] = [
            'productos' => $this->grupo($pageId, 'productos', 'Tienda'),
            'eventos'   => $this->grupo($pageId, 'eventos', 'Fechas'),
        ];

        return $pageId;
    }

    private function eventoDe($pageId, $titulo, $dias = 10, array $datos = [])
    {
        return $this->evento($this->grupos[$pageId]['eventos'], array_merge([
            'text' => $titulo,
            'event_date' => date('Y-m-d', strtotime("+$dias days")),
        ], $datos));
    }

    /** Un evento a $km kilómetros al norte del Obelisco. */
    private function eventoA($pageId, $titulo, $km, $dias = 10)
    {
        return $this->eventoDe($pageId, $titulo, $dias, [
            'event_latitude' => self::LAT + $km * self::GRADOS_POR_KM,
            'event_longitude' => self::LNG,
        ]);
    }

    private function productoDe($pageId, $titulo, $posicion = 0, array $datos = [])
    {
        $linkId = $this->insertar('links', [
            'group_id' => $this->grupos[$pageId]['productos'], 'url' => '', 'text' => $titulo,
            'position' => $posicion, 'image_url' => 'https://rezonar.test/api/uploads/tapa.jpg',
        ]);
        $this->insertar('digital_products', array_merge([
            'link_id' => $linkId, 'titulo' => $titulo, 'page_id' => $pageId, 'precio' => '11900.00',
            'archivo_ruta' => ProductosDigitales::rutaNueva(), 'archivo_nombre' => 'guia.pdf',
            'archivo_bytes' => 2048, 'archivo_tipo' => 'application/pdf',
        ], $datos));

        return $linkId;
    }

    /** La página $pageId muestra el evento $linkId como colaboradora. */
    private function colabora($linkId, $pageId, $estado = 'accepted')
    {
        $this->insertar('event_collaborations', [
            'link_id' => $linkId,
            'collaborator_page_id' => $pageId,
            'requester_page_id' => $this->duena,
            'status' => $estado,
            'collaborator_group_id' => $this->grupos[$pageId]['eventos'],
        ]);
    }

    /** Venta gratis con lugar para una persona, ya tomado. */
    private function agotar($linkId)
    {
        $this->insertar('event_ticketing', ['link_id' => $linkId, 'activo' => 1, 'capacidad' => 1, 'precio' => 0]);
        $orden = Entradas::crearOrden($this->db, $linkId, [
            'nombre' => 'Ana Compradora', 'email' => 'ana@test.local', 'telefono' => '1122334455', 'cantidad' => 1,
        ]);
        $this->assertTrue($orden['ok'], json_encode($orden));
        $this->assertTrue(Entradas::disponibilidad($this->db, $linkId)['agotado']);
    }

    private function sugerencias($tipo, $id, $pagina = null)
    {
        $query = ['tipo' => $tipo, 'id' => (string) $id];
        if ($pagina !== null) {
            $query['pagina'] = (string) $pagina;
        }

        $res = SugerenciasHandler::listar($this->db, $this->get($query));
        $this->assertStatus(200, $res);

        return $res->body['sugerencias'];
    }

    private function titulos(array $sugerencias)
    {
        return array_column($sugerencias, 'text');
    }

    // --------------------------------------------------------- prioridad

    public function testPrimeroLosProductosDeVDespuesSusEventosYDespuesLaDuena()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->vista);

        $this->productoDe($this->vista, 'Producto de V');
        $this->eventoDe($this->vista, 'Evento de V');
        $this->productoDe($this->duena, 'Producto de O');
        $this->eventoDe($this->duena, 'Evento de O');

        $this->assertSame(
            ['Producto de V', 'Evento de V', 'Producto de O'],
            $this->titulos($this->sugerencias('evento', $x, $this->vista))
        );
    }

    public function testLosProductosVanEnElOrdenDeLaPagina()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->productoDe($this->duena, 'Tercero', 2);
        $this->productoDe($this->duena, 'Primero', 0);
        $this->productoDe($this->duena, 'Segundo', 1);
        $this->productoDe($this->duena, 'Cuarto', 3);

        $this->assertSame(['Primero', 'Segundo', 'Tercero'], $this->titulos($this->sugerencias('evento', $x)));
    }

    public function testLosEventosVanPorFechaElMasProximoPrimero()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->eventoDe($this->duena, 'En un mes', 30);
        $this->eventoDe($this->duena, 'Mañana', 1);
        $this->eventoDe($this->duena, 'En una semana', 7);
        $this->eventoDe($this->duena, 'En dos meses', 60);

        $this->assertSame(['Mañana', 'En una semana', 'En un mes'], $this->titulos($this->sugerencias('evento', $x)));
    }

    /** Los eventos de V incluyen los colaborados que muestra, no sólo los propios. */
    public function testLosEventosDeVIncluyenLosColaboradosQueMuestra()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->vista);

        $deLaOtra = $this->eventoDe($this->otra, 'De El Club que muestra V', 3);
        $this->colabora($deLaOtra, $this->vista);
        $this->eventoDe($this->vista, 'Propio de V', 5);

        $sugerencias = $this->sugerencias('evento', $x, $this->vista);

        $this->assertSame(['De El Club que muestra V', 'Propio de V'], $this->titulos($sugerencias));
        $this->assertSame('El Club', $sugerencias[0]['page_title'], 'el título es el de la página dueña del evento');
    }

    /** V sin nada: se pasa a la dueña, y de la dueña a las colaboradoras. */
    public function testSaltaAlNivelSiguienteCuandoElAnteriorNoAlcanza()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->vista);
        $this->colabora($x, $this->otra);

        $this->productoDe($this->duena, 'Producto de O');
        $this->eventoDe($this->duena, 'Evento de O');
        $this->eventoDe($this->otra, 'Evento de El Club', 1);
        $this->productoDe($this->otra, 'Producto de El Club');

        $this->assertSame(
            ['Producto de O', 'Evento de O', 'Producto de El Club'],
            $this->titulos($this->sugerencias('evento', $x, $this->vista))
        );
    }

    public function testLasColaboradorasDeXCompletanConProductosYDespuesEventos()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->otra);
        $this->eventoDe($this->otra, 'Evento de El Club', 1);
        $this->productoDe($this->otra, 'Producto de El Club');

        $this->assertSame(
            ['Producto de El Club', 'Evento de El Club'],
            $this->titulos($this->sugerencias('evento', $x))
        );
    }

    public function testUnaColaboracionPendienteNoCuenta()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->otra, 'pending');
        $this->productoDe($this->otra, 'Producto de El Club');
        $this->eventoDe($this->duena, 'Evento de O');

        $this->assertSame(['Evento de O'], $this->titulos($this->sugerencias('evento', $x)));
    }

    // ------------------------------------------------------------ pagina

    /** Con una página que no muestra X, V es la dueña: no se sacan cosas de otra página. */
    public function testUnaPaginaAjenaAXSeIgnora()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->productoDe($this->otra, 'Producto de El Club');
        $this->eventoDe($this->otra, 'Evento de El Club');
        $this->eventoDe($this->duena, 'Evento de O');

        $this->assertSame(['Evento de O'], $this->titulos($this->sugerencias('evento', $x, $this->otra)));
    }

    public function testUnaPaginaConLaColaboracionPendienteSeIgnora()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->otra, 'pending');
        $this->productoDe($this->otra, 'Producto de El Club');
        $this->eventoDe($this->duena, 'Evento de O');

        $this->assertSame(['Evento de O'], $this->titulos($this->sugerencias('evento', $x, $this->otra)));
    }

    /** Un producto no tiene colaboradoras: pagina sólo puede ser la dueña. */
    public function testEnUnProductoLaPaginaSiempreEsLaDuena()
    {
        $x = $this->productoDe($this->duena, 'X');
        $this->productoDe($this->otra, 'Producto de El Club');
        $this->productoDe($this->duena, 'Otro producto de O');

        $this->assertSame(['Otro producto de O'], $this->titulos($this->sugerencias('producto', $x, $this->otra)));
    }

    // ------------------------------------------------- lo que no se sugiere

    public function testNoSalenPasadosAgotadosNiXNiRepetidos()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->vista);

        $this->eventoDe($this->duena, 'Ayer', -1);
        $this->agotar($this->eventoDe($this->duena, 'Agotado', 2));
        $this->eventoDe($this->duena, 'Hoy', 0);

        // Uno de la dueña que V también muestra: es nivel 2 y nivel 4.
        $compartido = $this->eventoDe($this->duena, 'Compartido', 3);
        $this->colabora($compartido, $this->vista);

        // Con venta y lugar libre: no está agotado.
        $conLugar = $this->eventoDe($this->duena, 'Con lugar', 4);
        $this->insertar('event_ticketing', ['link_id' => $conLugar, 'activo' => 1, 'capacidad' => 10, 'precio' => 0]);

        $this->assertSame(
            ['Compartido', 'Hoy', 'Con lugar'],
            $this->titulos($this->sugerencias('evento', $x, $this->vista))
        );
    }

    public function testNoSalenProductosOcultosBorradosNiSinArchivo()
    {
        $x = $this->productoDe($this->duena, 'X', 0);
        $this->productoDe($this->duena, 'Oculto', 1, ['oculto' => 1]);
        $this->productoDe($this->duena, 'Borrado', 2, ['borrado_en' => '2026-01-01 00:00:00']);
        $this->productoDe($this->duena, 'Sin archivo', 3, [
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null, 'archivo_tipo' => null,
        ]);
        $this->productoDe($this->duena, 'En venta', 4);

        $this->assertSame(['En venta'], $this->titulos($this->sugerencias('producto', $x)));
    }

    public function testLaRespuestaTieneSoloLaListaCerradaDeCampos()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $producto = $this->productoDe($this->duena, 'Guía');
        $evento = $this->eventoDe($this->duena, 'Otro show', 5, ['precio_desde' => '8000.00']);
        $ruta = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$producto]);

        $sugerencias = $this->sugerencias('evento', $x);

        $this->assertSame([
            [
                'tipo' => 'producto', 'id' => $producto, 'text' => 'Guía',
                'image_url' => 'https://rezonar.test/api/uploads/tapa.jpg', 'precio' => 11900.0, 'page_title' => 'La Sala',
            ],
            [
                'tipo' => 'evento', 'id' => $evento, 'text' => 'Otro show', 'image_url' => null,
                'event_date' => date('Y-m-d', strtotime('+5 days')), 'event_time' => '21:00:00', 'page_title' => 'La Sala',
                'precio_referencia' => 8000.0,
            ],
        ], $sugerencias);
        $this->assertStringNotContainsString(substr($ruta, strlen('productos/')), json_encode($sugerencias));
    }

    /** Un evento que vende por Rezonar sugiere el precio de su venta, no el cargado a mano. */
    public function testLaSugerenciaDeUnEventoQueVendeTraeElPrecioDeLaVenta()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $evento = $this->eventoDe($this->duena, 'Con venta', 5, ['precio_desde' => '99999.00']);
        $this->insertar('event_ticketing', ['link_id' => $evento, 'activo' => 1, 'capacidad' => 10, 'precio' => '12000.00']);

        $sugerencias = $this->sugerencias('evento', $x);

        $this->assertSame(12000.0, $sugerencias[0]['precio_referencia']);
    }

    public function testUnXQueNoExisteDa404()
    {
        $this->assertStatus(404, SugerenciasHandler::listar($this->db, $this->get(['tipo' => 'evento', 'id' => '999'])));
    }

    // ---------------------------------------------------------- cercanía

    /**
     * Sin nada en las páginas: primero los de menos de 30 km por fecha, y
     * después el resto por distancia.
     */
    public function testElFallbackOrdenaPorCercaniaYFecha()
    {
        $x = $this->eventoA($this->duena, 'X', 0);
        $this->eventoA($this->otra, 'A 5 km en 20 días', 5, 20);
        $this->eventoA($this->otra, 'A 10 km en 5 días', 10, 5);
        $this->eventoA($this->otra, 'A 100 km mañana', 100, 1);
        $this->eventoA($this->otra, 'A 50 km pasado', 50, 2);
        $this->eventoDe($this->otra, 'Sin coordenadas', 1);

        $this->assertSame(
            ['A 10 km en 5 días', 'A 5 km en 20 días', 'A 50 km pasado'],
            $this->titulos($this->sugerencias('evento', $x))
        );
    }

    public function testElFallbackNoSugiereAgotadosNiPasados()
    {
        $x = $this->eventoA($this->duena, 'X', 0);
        $this->agotar($this->eventoA($this->otra, 'Agotado cerca', 1, 1));
        $this->eventoA($this->otra, 'Pasado cerca', 1, -2);
        $this->eventoA($this->otra, 'Lejos', 200, 3);

        $this->assertSame(['Lejos'], $this->titulos($this->sugerencias('evento', $x)));
    }

    /**
     * X es un producto: el punto es el próximo evento de su página con
     * coordenadas, aunque esté agotado y no se pueda sugerir.
     */
    public function testUnProductoUsaElProximoEventoDeSuPaginaComoReferencia()
    {
        $x = $this->productoDe($this->duena, 'X');
        $this->agotar($this->eventoA($this->duena, 'Agotado de O', 0, 1));
        $this->eventoA($this->otra, 'Lejos y pronto', 300, 1);
        $this->eventoA($this->otra, 'Cerca y en un mes', 2, 30);

        $this->assertSame(
            ['Cerca y en un mes', 'Lejos y pronto'],
            $this->titulos($this->sugerencias('producto', $x))
        );
    }

    /** Si V no tiene un evento con coordenadas, se usa el próximo de O. */
    public function testSinReferenciaEnVSeUsaLaDeO()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->colabora($x, $this->vista);
        $this->agotar($this->eventoA($this->duena, 'Agotado de O', 300, 1));
        $this->eventoA($this->otra, 'Cerca del de O', 301, 20);
        $this->eventoA($this->otra, 'Cerca del Obelisco', 0, 2);

        $this->assertSame(
            ['Cerca del de O', 'Cerca del Obelisco'],
            $this->titulos($this->sugerencias('evento', $x, $this->vista))
        );
    }

    public function testSinNingunaReferenciaVanLosProximosDeLaPlataforma()
    {
        $x = $this->eventoDe($this->duena, 'X');
        $this->eventoDe($this->otra, 'En una semana', 7);
        $this->eventoA($this->otra, 'Mañana', 500, 1);
        $this->eventoDe($this->otra, 'En un mes', 30);
        $this->eventoDe($this->otra, 'En dos meses', 60);

        $this->assertSame(
            ['Mañana', 'En una semana', 'En un mes'],
            $this->titulos($this->sugerencias('evento', $x))
        );
    }

    /** El fallback es sólo para cuando no hay ninguna: con una, no se completa. */
    public function testConUnaSugerenciaNoSeBuscanCercanos()
    {
        $x = $this->eventoA($this->duena, 'X', 0);
        $this->eventoDe($this->duena, 'Evento de O');
        $this->eventoA($this->otra, 'Cerca', 1);

        $this->assertSame(['Evento de O'], $this->titulos($this->sugerencias('evento', $x)));
    }

    public function testSinNadaEnLaPlataformaNoHaySugerencias()
    {
        $x = $this->eventoDe($this->duena, 'X');

        $this->assertSame([], $this->sugerencias('evento', $x));
    }
}
