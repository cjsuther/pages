<?php

namespace Tests\Unit\Handlers;

use FeedHandler;
use Request;
use Tests\Support\HandlerTestCase;

class FeedHandlerTest extends HandlerTestCase
{
    // =========================================================== autenticación

    public function testExigeCabeceraAuthorization()
    {
        $res = FeedHandler::events($this->db, new Request('GET'));

        $this->assertError(401, $res, 'Token no proporcionado');
    }

    public function testDistingueTokenInvalido()
    {
        $res = FeedHandler::events($this->db, $this->conTokenInvalido('GET'));

        $this->assertError(401, $res, 'Unauthorized');
    }

    public function testRechazaMetodoNoSoportado()
    {
        $res = FeedHandler::events($this->db, $this->post([], $this->user()));

        $this->assertError(405, $res, 'Método no permitido');
    }

    // ================================================================ consulta

    public function testConsultaSoloGruposDeEventosConFecha()
    {
        FeedHandler::events($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor('FROM page_followers pf')[0]['sql'];

        $this->assertStringContainsString('g.type = "eventos"', $sql);
        $this->assertStringContainsString('l.event_date IS NOT NULL', $sql);
        $this->assertSame([9, 9, \Fechas::hoy()], $this->db->paramsFor('FROM page_followers pf'));
    }

    /**
     * Un evento que ya pasó no es agenda: es historia. El resto de las vistas
     * públicas ya cortaba por hoy y ésta no, así que el feed de quien seguía
     * páginas se iba llenando de shows vencidos.
     */
    public function testNoTraeEventosQueYaPasaron()
    {
        FeedHandler::events($this->db, $this->get([], $this->user(9)));

        $sql = $this->db->callsFor('FROM page_followers pf')[0]['sql'];
        $params = $this->db->paramsFor('FROM page_followers pf');

        $this->assertStringContainsString('l.event_date >= ?', $sql);
        $this->assertSame(\Fechas::hoy(), end($params));
    }

    public function testDevuelveFeedVacio()
    {
        $res = FeedHandler::events($this->db, $this->get([], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame(['events' => [], 'total' => 0], $res->body);
    }

    // ================================================================ filtrado

    public function testIncluyeTodoSiLaPreferenciaEsNotificarTodo()
    {
        $eventos = FeedHandler::filtrar([
            $this->evento(['notify_all_events' => 1, 'max_distance_km' => 1]),
        ]);

        $this->assertCount(1, $eventos);
    }

    public function testExcluyeEventoLejanoCuandoNoNotificaTodo()
    {
        // Usuario en Buenos Aires, evento en Córdoba (~640 km), radio 50 km.
        $eventos = FeedHandler::filtrar([
            $this->evento([
                'notify_all_events' => 0,
                'max_distance_km' => 50,
                'user_latitude' => '-34.6037', 'user_longitude' => '-58.3816',
                'event_latitude' => '-31.4201', 'event_longitude' => '-64.1888',
            ]),
        ]);

        $this->assertCount(0, $eventos);
    }

    public function testIncluyeEventoCercanoCuandoNoNotificaTodo()
    {
        // Dos puntos de Buenos Aires, a pocos km.
        $eventos = FeedHandler::filtrar([
            $this->evento([
                'notify_all_events' => 0,
                'max_distance_km' => 50,
                'user_latitude' => '-34.6037', 'user_longitude' => '-58.3816',
                'event_latitude' => '-34.5875', 'event_longitude' => '-58.3974',
            ]),
        ]);

        $this->assertCount(1, $eventos);
    }

    public function testExcluyeEventoSinCoordenadasCuandoNoNotificaTodo()
    {
        $eventos = FeedHandler::filtrar([
            $this->evento([
                'notify_all_events' => 0,
                'max_distance_km' => 50,
                'event_latitude' => null, 'event_longitude' => null,
            ]),
        ]);

        $this->assertCount(0, $eventos);
    }

    public function testExcluyeSiElUsuarioNoTieneUbicacion()
    {
        $eventos = FeedHandler::filtrar([
            $this->evento([
                'notify_all_events' => 0,
                'max_distance_km' => 50,
                'user_latitude' => null, 'user_longitude' => null,
            ]),
        ]);

        $this->assertCount(0, $eventos);
    }

    public function testNoExponeLasPreferenciasInternas()
    {
        $eventos = FeedHandler::filtrar([$this->evento(['notify_all_events' => 1])]);

        foreach (['notify_all_events', 'max_distance_km', 'user_latitude', 'user_longitude'] as $campo) {
            $this->assertArrayNotHasKey($campo, $eventos[0], $campo . ' no debe llegar al cliente');
        }
    }

    public function testConvierteLosTiposParaElCliente()
    {
        $eventos = FeedHandler::filtrar([
            $this->evento([
                'id' => '55',
                'page_id' => '7',
                'is_event' => '1',
                'notify_all_events' => 1,
                'event_latitude' => '-34.6037',
                'event_longitude' => '-58.3816',
            ]),
        ]);

        $this->assertSame(55, $eventos[0]['id']);
        $this->assertSame(7, $eventos[0]['page_id']);
        $this->assertTrue($eventos[0]['is_event']);
        $this->assertSame(-34.6037, $eventos[0]['event_latitude']);
        $this->assertSame(-58.3816, $eventos[0]['event_longitude']);
    }

    public function testAgregaLaDistanciaCalculada()
    {
        $eventos = FeedHandler::filtrar([
            $this->evento([
                'notify_all_events' => 1,
                'user_latitude' => '-34.6037', 'user_longitude' => '-58.3816',
                'event_latitude' => '-34.5875', 'event_longitude' => '-58.3974',
            ]),
        ]);

        $this->assertIsFloat($eventos[0]['distance']);
        $this->assertGreaterThan(0, $eventos[0]['distance']);
        $this->assertLessThan(10, $eventos[0]['distance']);
    }

    public function testLaDistanciaEsNullSinCoordenadas()
    {
        $eventos = FeedHandler::filtrar([
            $this->evento(['notify_all_events' => 1, 'event_latitude' => null, 'event_longitude' => null]),
        ]);

        $this->assertNull($eventos[0]['distance']);
    }

    // ============================================================== ordenamiento

    public function testOrdenaPorFechaAscendentePorDefecto()
    {
        $eventos = FeedHandler::ordenar([
            ['event_date' => '2026-03-01', 'event_time' => '20:00:00', 'distance' => null],
            ['event_date' => '2026-01-15', 'event_time' => '21:00:00', 'distance' => null],
            ['event_date' => '2026-02-10', 'event_time' => '19:00:00', 'distance' => null],
        ], 'date', 'asc');

        $this->assertSame(
            ['2026-01-15', '2026-02-10', '2026-03-01'],
            array_column($eventos, 'event_date')
        );
    }

    public function testOrdenaPorFechaDescendente()
    {
        $eventos = FeedHandler::ordenar([
            ['event_date' => '2026-01-15', 'event_time' => '21:00:00', 'distance' => null],
            ['event_date' => '2026-03-01', 'event_time' => '20:00:00', 'distance' => null],
        ], 'date', 'desc');

        $this->assertSame(['2026-03-01', '2026-01-15'], array_column($eventos, 'event_date'));
    }

    public function testDesempataPorHoraDentroDelMismoDia()
    {
        $eventos = FeedHandler::ordenar([
            ['event_date' => '2026-01-15', 'event_time' => '22:00:00', 'distance' => null],
            ['event_date' => '2026-01-15', 'event_time' => '18:00:00', 'distance' => null],
        ], 'date', 'asc');

        $this->assertSame(['18:00:00', '22:00:00'], array_column($eventos, 'event_time'));
    }

    public function testOrdenaPorDistanciaAscendente()
    {
        $eventos = FeedHandler::ordenar([
            ['event_date' => '2026-01-01', 'distance' => 30.0],
            ['event_date' => '2026-01-01', 'distance' => 5.0],
            ['event_date' => '2026-01-01', 'distance' => 12.0],
        ], 'distance', 'asc');

        $this->assertSame([5.0, 12.0, 30.0], array_column($eventos, 'distance'));
    }

    public function testOrdenaPorDistanciaDescendente()
    {
        $eventos = FeedHandler::ordenar([
            ['event_date' => '2026-01-01', 'distance' => 5.0],
            ['event_date' => '2026-01-01', 'distance' => 30.0],
        ], 'distance', 'desc');

        $this->assertSame([30.0, 5.0], array_column($eventos, 'distance'));
    }

    public function testLosEventosSinDistanciaVanAlFinal()
    {
        $eventos = FeedHandler::ordenar([
            ['event_date' => '2026-01-01', 'distance' => null],
            ['event_date' => '2026-01-01', 'distance' => 12.0],
        ], 'distance', 'asc');

        $this->assertSame([12.0, null], array_column($eventos, 'distance'));
    }

    /**
     * Los parámetros de orden llegan del query string, así que el saneamiento
     * se comprueba de punta a punta: cualquier valor no permitido debe caer a
     * fecha ascendente en lugar de propagarse.
     *
     * @dataProvider parametrosInvalidos
     */
    public function testIgnoraParametrosDeOrdenInvalidos($query)
    {
        $this->db->onSelect('FROM page_followers pf', [
            $this->evento(['id' => '1', 'event_date' => '2026-03-01']),
            $this->evento(['id' => '2', 'event_date' => '2026-01-01']),
        ]);

        $res = FeedHandler::events($this->db, $this->get($query, $this->user()));

        $this->assertSame(
            ['2026-01-01', '2026-03-01'],
            array_column($res->body['events'], 'event_date'),
            'Con parámetros inválidos debe ordenar por fecha ascendente'
        );
    }

    public function parametrosInvalidos()
    {
        return [
            'sin parámetros' => [[]],
            'columna inventada' => [['sortBy' => 'nombre']],
            'intento de inyección' => [['sortBy' => 'id; DROP TABLE links']],
            'orden inventado' => [['sortOrder' => 'random']],
            'ambos inválidos' => [['sortBy' => 'x', 'sortOrder' => 'y']],
        ];
    }

    public function testRespetaElOrdenDescendentePedido()
    {
        $this->db->onSelect('FROM page_followers pf', [
            $this->evento(['id' => '1', 'event_date' => '2026-01-01']),
            $this->evento(['id' => '2', 'event_date' => '2026-03-01']),
        ]);

        $res = FeedHandler::events($this->db, $this->get(
            ['sortBy' => 'date', 'sortOrder' => 'desc'],
            $this->user()
        ));

        $this->assertSame(['2026-03-01', '2026-01-01'], array_column($res->body['events'], 'event_date'));
    }

    // ------------------------------------------------------------- ayudantes

    /** Fila de evento con los campos que devuelve la consulta del feed. */
    private function evento(array $overrides = [])
    {
        return array_merge([
            'id' => '1',
            'url' => 'https://ejemplo.com',
            'text' => 'Un evento',
            'image_url' => null,
            'description' => null,
            'event_date' => '2026-06-01',
            'event_time' => '20:00:00',
            'event_address' => 'Alguna dirección',
            'event_latitude' => '-34.6037',
            'event_longitude' => '-58.3816',
            'event_maps_url' => null,
            'is_event' => '1',
            'page_id' => '3',
            'page_title' => 'Una página',
            'page_slug' => 'una-pagina',
            'page_image' => null,
            'notify_all_events' => 1,
            'max_distance_km' => 50,
            'user_latitude' => '-34.6037',
            'user_longitude' => '-58.3816',
        ], $overrides);
    }
}
