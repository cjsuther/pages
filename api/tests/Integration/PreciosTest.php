<?php

namespace Tests\Integration;

use FeedHandler;
use PublicHandler;
use Tests\Support\IntegracionTestCase;

/**
 * El precio que se muestra de cada evento (#32), contra una MariaDB de verdad:
 * que todos los endpoints de eventos lo calculen igual —de la venta si vende
 * por Rezonar, del precio cargado si no— y que el filtro de la home se aplique
 * en la consulta.
 *
 * FakePdo no sirve para esto: reconoce las consultas por fragmentos y nunca
 * mira que el JOIN y el CASE anden.
 */
class PreciosTest extends IntegracionTestCase
{
    private $duenaId;
    private $paginaId;
    private $grupoId;

    /** @var array<string, int> id de cada evento por nombre */
    private $eventos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId);

        $base = ['event_latitude' => '-34.6', 'event_longitude' => '-58.4'];

        $this->eventos = [
            'sin precio'  => $this->evento($this->grupoId, $base + ['text' => 'Sin precio', 'event_date' => $this->dentroDe(1)]),
            'gratis'      => $this->evento($this->grupoId, $base + ['text' => 'Gratis', 'precio_desde' => 0, 'event_date' => $this->dentroDe(2)]),
            'barato'      => $this->evento($this->grupoId, $base + ['text' => 'Barato', 'precio_desde' => 8000, 'event_date' => $this->dentroDe(3)]),
            'caro'        => $this->evento($this->grupoId, $base + ['text' => 'Caro', 'precio_desde' => 60000, 'event_date' => $this->dentroDe(4)]),
            // Vende por Rezonar: manda la venta, no lo cargado a mano.
            'vende'       => $this->evento($this->grupoId, $base + ['text' => 'Vende', 'precio_desde' => 99999, 'event_date' => $this->dentroDe(5)]),
            'vende gratis' => $this->evento($this->grupoId, $base + ['text' => 'Vende gratis', 'event_date' => $this->dentroDe(6)]),
            // Con la venta apagada vuelve a valer lo cargado a mano.
            'venta apagada' => $this->evento($this->grupoId, $base + ['text' => 'Venta apagada', 'precio_desde' => 30000, 'event_date' => $this->dentroDe(7)]),
        ];

        $this->insertar('event_ticketing', ['link_id' => $this->eventos['vende'], 'activo' => 1, 'capacidad' => 50, 'precio' => '12000.00']);
        $this->insertar('event_ticketing', ['link_id' => $this->eventos['vende gratis'], 'activo' => 1, 'capacidad' => 50, 'precio' => '0.00']);
        $this->insertar('event_ticketing', ['link_id' => $this->eventos['venta apagada'], 'activo' => 0, 'capacidad' => 50, 'precio' => '5000.00']);
    }

    private function dentroDe($dias)
    {
        return date('Y-m-d', strtotime("+$dias days"));
    }

    /** Los precios esperados de todos los eventos, por título. */
    private function esperados()
    {
        return [
            'Sin precio' => null, 'Gratis' => 0.0, 'Barato' => 8000.0, 'Caro' => 60000.0,
            'Vende' => 12000.0, 'Vende gratis' => 0.0, 'Venta apagada' => 30000.0,
        ];
    }

    private function porTitulo(array $eventos, $campo = 'text')
    {
        $precios = [];

        foreach ($eventos as $evento) {
            $precios[$evento[$campo]] = $evento['precio_referencia'];
        }

        return $precios;
    }

    private function recientes(array $query = [])
    {
        $res = PublicHandler::recentEvents($this->db, $this->get($query));
        $this->assertStatus(200, $res);

        return $this->porTitulo($res->body['events']);
    }

    // ------------------------------------------------------------- endpoints

    public function testLosEventosRecientesTraenElPrecioDeReferencia()
    {
        $this->assertSame($this->esperados(), $this->recientes());
    }

    public function testLaPaginaTraeElPrecioDeReferencia()
    {
        $res = PublicHandler::page($this->db, $this->get(['slug' => 'la-sala']));
        $this->assertStatus(200, $res);

        $this->assertSame($this->esperados(), $this->porTitulo($res->body['page']['groups'][0]['links']));
    }

    public function testLosEventosColaboradosTraenElPrecioDeReferencia()
    {
        $otraPagina = $this->pagina($this->usuario('otra@test.local', 'Otra'), 'otra', 'Otra');
        $otroGrupo = $this->grupo($otraPagina);
        $this->insertar('event_collaborations', [
            'link_id' => $this->eventos['vende'], 'collaborator_page_id' => $otraPagina,
            'requester_page_id' => $this->paginaId, 'status' => 'accepted', 'collaborator_group_id' => $otroGrupo,
        ]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'otra']));

        $this->assertSame(['Vende' => 12000.0], $this->porTitulo($res->body['page']['groups'][0]['collaborated_events']));
    }

    public function testElDetalleTraeElPrecioDeReferencia()
    {
        foreach (['vende' => 12000.0, 'gratis' => 0.0, 'sin precio' => null, 'venta apagada' => 30000.0] as $nombre => $precio) {
            $res = PublicHandler::event($this->db, $this->get(['id' => (string) $this->eventos[$nombre]]));

            $this->assertSame($precio, $res->body['event']['precio_referencia'], $nombre);
        }
    }

    public function testLosEventosDelRangoTraenElPrecioDeReferencia()
    {
        $res = PublicHandler::events($this->db, $this->get());

        $this->assertSame($this->esperados(), $this->porTitulo($res->body['events'], 'title'));
    }

    public function testLaBusquedaTraeElPrecioDeReferencia()
    {
        $res = PublicHandler::search($this->db, $this->get(['q' => 'vende']));
        $eventos = array_values(array_filter($res->body['results'], function ($r) { return $r['type'] === 'event'; }));

        $this->assertSame(['Vende' => 12000.0, 'Vende gratis' => 0.0], $this->porTitulo($eventos, 'title'));
    }

    public function testElFeedTraeElPrecioDeReferencia()
    {
        $seguidora = $this->usuario('fan@test.local', 'Fan');
        $this->insertar('page_followers', ['page_id' => $this->paginaId, 'user_id' => $seguidora, 'notify_all_events' => 1]);

        $res = FeedHandler::events($this->db, $this->get([], $this->user($seguidora, 'fan@test.local')));
        $this->assertStatus(200, $res);

        $this->assertSame($this->esperados(), $this->porTitulo($res->body['events']));

        $res = FeedHandler::events($this->db, $this->get(['precio' => 'gratis'], $this->user($seguidora, 'fan@test.local')));

        $this->assertSame(['Gratis' => 0.0, 'Vende gratis' => 0.0], $this->porTitulo($res->body['events']));
    }

    // --------------------------------------------------------------- filtros

    /** Con un filtro, lo que no tiene precio conocido no aparece. */
    public function testElFiltroGratis()
    {
        $this->assertSame(['Gratis' => 0.0, 'Vende gratis' => 0.0], $this->recientes(['precio' => 'gratis']));
    }

    /** "Hasta" incluye lo gratis: no sale más que eso. */
    public function testElFiltroHasta()
    {
        $this->assertSame(
            ['Gratis' => 0.0, 'Barato' => 8000.0, 'Vende' => 12000.0, 'Vende gratis' => 0.0],
            $this->recientes(['precio' => 'hasta:25000'])
        );
        $this->assertSame(
            ['Gratis' => 0.0, 'Barato' => 8000.0, 'Vende gratis' => 0.0],
            $this->recientes(['precio' => 'hasta:10000'])
        );
    }

    /** El recorte de 30 es después de filtrar: un gratis lejano igual aparece. */
    public function testElFiltroSeAplicaAntesDelRecorte()
    {
        for ($i = 0; $i < PublicHandler::LIMITE_EVENTOS_RECIENTES; $i++) {
            $this->evento($this->grupoId, ['text' => "Pago $i", 'precio_desde' => 50000, 'event_date' => $this->dentroDe(1)]);
        }

        $lejano = $this->evento($this->grupoId, ['text' => 'Gratis lejano', 'precio_desde' => 0, 'event_date' => $this->dentroDe(90)]);

        $this->assertArrayNotHasKey('Gratis lejano', $this->recientes());
        $this->assertArrayHasKey('Gratis lejano', $this->recientes(['precio' => 'gratis']));
        $this->assertGreaterThan(0, $lejano);
    }
}
