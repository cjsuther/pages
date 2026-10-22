<?php

namespace Tests\Unit\Lib;

use Importador;
use Tests\Support\HandlerTestCase;

class ImportadorTest extends HandlerTestCase
{
    private function fuente(array $overrides = [])
    {
        return array_merge([
            'id' => 1,
            'user_id' => 7,
            'page_id' => 5,
            'adaptador' => 'eventbrite',
            'nombre' => 'Stand up en Buenos Aires',
            'slug' => 'standup-bsas',
            'parametros' => '{"busqueda":"stand-up"}',
        ], $overrides);
    }

    private function evento(array $overrides = [])
    {
        return array_merge([
            'id' => 'EV-1',
            'titulo' => 'Blah Blah Stand Up',
            'descripcion' => 'Un show',
            'imagen' => 'https://img/1.jpg',
            'url' => 'https://eventbrite/e/1',
            'fecha' => date('Y-m-d', strtotime('+10 days')),
            'hora' => '21:00:00',
            'direccion' => 'Blue Velvet — Bolívar 624',
            'latitud' => '-34.6151736',
            'longitud' => '-58.3730221',
            'precio_desde' => null,
        ], $overrides);
    }

    /** La página y el grupo ya existen: el caso de las corridas siguientes. */
    private function laPaginaExiste()
    {
        $this->db->onSelect('SELECT id FROM pages WHERE id = ?', [[5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id', [[10]]);
    }

    private function conEventos(array $eventos)
    {
        return function () use ($eventos) { return $eventos; };
    }

    // ----------------------------------------------------------- validación

    public function testUnEventoCompletoEsValido()
    {
        $this->assertNull(Importador::validar($this->evento()));
    }

    /** Sin coordenadas no se puede ubicar en el mapa, que es media agenda. */
    public function testSeDescartaUnEventoSinCoordenadas()
    {
        $this->assertSame('sin coordenadas', Importador::validar($this->evento(['latitud' => null])));
        $this->assertSame('sin coordenadas', Importador::validar($this->evento(['longitud' => 'x'])));
    }

    public function testSeDescartaUnEventoSinFechaUtilizable()
    {
        $this->assertSame('sin fecha utilizable', Importador::validar($this->evento(['fecha' => null])));
        $this->assertSame('sin fecha utilizable', Importador::validar($this->evento(['fecha' => 'ayer'])));
    }

    public function testSeDescartaUnEventoQueYaPaso()
    {
        $this->assertSame('ya pasó', Importador::validar($this->evento(['fecha' => '2020-01-01'])));
    }

    public function testSeDescartaUnEventoSinTitulo()
    {
        $this->assertSame('sin id o sin título', Importador::validar($this->evento(['titulo' => ''])));
    }

    // ------------------------------------------------------------- alta

    public function testUnEventoNuevoSeCrea()
    {
        $this->laPaginaExiste();
        $this->db->onWrite('INSERT INTO links', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento()]));

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['creados']);
    }

    /** origen + origen_id es lo que hace que reimportar no duplique. */
    public function testElEventoGuardaDeDondeVino()
    {
        $this->laPaginaExiste();
        $this->db->onWrite('INSERT INTO links', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento()]));
        $params = $this->db->paramsFor('INSERT INTO links');

        $this->assertContains('eventbrite', $params);
        $this->assertContains('EV-1', $params);
    }

    public function testLosEventosInvalidosSeSaltanSinCortarLaCorrida()
    {
        $this->laPaginaExiste();
        $this->db->onWrite('INSERT INTO links', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([
            $this->evento(['id' => 'MALO', 'latitud' => null]),
            $this->evento(['id' => 'BUENO']),
        ]));

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['creados']);
    }

    // ------------------------------------------------------- actualización

    private function yaExiste(array $overrides = [])
    {
        $this->db->onSelect('FROM links WHERE group_id = ? AND origen', [array_merge([
            'id' => 99,
            'text' => 'Blah Blah Stand Up',
            'description' => 'Un show',
            'image_url' => 'https://img/1.jpg',
            'url' => 'https://eventbrite/e/1',
            'event_date' => date('Y-m-d', strtotime('+10 days')),
            'event_time' => '21:00:00',
            'event_address' => 'Blue Velvet — Bolívar 624',
            'event_latitude' => '-34.6151736',
            'event_longitude' => '-58.3730221',
            'precio_desde' => null,
            'campos_editados' => null,
        ], $overrides)]);
    }

    public function testUnEventoSinCambiosNoSeToca()
    {
        $this->laPaginaExiste();
        $this->yaExiste();
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento()]));

        $this->assertSame(0, $r['creados']);
        $this->assertSame(0, $r['actualizados']);
        $this->assertSame(0, $this->db->countCalls('UPDATE links SET'));
    }

    /** Una reprogramación es justo el dato que más importa que llegue. */
    public function testUnCambioDeFechaSeActualiza()
    {
        $this->laPaginaExiste();
        $this->yaExiste(['event_date' => '2026-12-01']);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento()]));

        $this->assertSame(1, $r['actualizados']);
        $this->assertStringContainsString('event_date = ?', $this->db->callsFor('UPDATE links SET')[0]['sql']);
    }

    /**
     * Es la regla que sostiene todo: corregir a mano el título de un show
     * importado no puede durar hasta la madrugada siguiente.
     */
    public function testLoEditadoAManoNoSePisa()
    {
        $this->laPaginaExiste();
        $this->yaExiste(['text' => 'Título corregido a mano', 'campos_editados' => 'text']);
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento()]));

        $this->assertSame(0, $r['actualizados'], 'el título editado no se actualiza');
        $this->assertSame(0, $this->db->countCalls('UPDATE links SET'));
    }

    /** Congelar un campo no congela el resto del evento. */
    public function testLoNoEditadoSeSigueActualizando()
    {
        $this->laPaginaExiste();
        $this->yaExiste([
            'text' => 'Título corregido a mano',
            'campos_editados' => 'text',
            'event_date' => '2026-12-01',
        ]);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento()]));
        $sql = $this->db->callsFor('UPDATE links SET')[0]['sql'];

        $this->assertStringContainsString('event_date = ?', $sql);
        $this->assertStringNotContainsString('text = ?', $sql);
    }

    // ---------------------------------------------------- precio al reimportar

    /** La ficha falló una noche: el precio que ya se conocía se queda. */
    public function testUnPrecioQueNoVinoNoPisaElConocido()
    {
        $this->laPaginaExiste();
        $this->yaExiste(['precio_desde' => '15000.00']);
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento(['precio_desde' => null])]));

        $this->assertSame(0, $r['actualizados']);
        $this->assertSame(0, $this->db->countCalls('UPDATE links SET'));
    }

    /** Si el resto cambió, se actualiza el resto, pero el precio no se borra. */
    public function testUnPrecioQueNoVinoNoSeBorraAunqueCambieOtraCosa()
    {
        $this->laPaginaExiste();
        $this->yaExiste(['precio_desde' => '15000.00', 'event_date' => '2026-12-01']);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento(['precio_desde' => null])]));
        $sql = $this->db->callsFor('UPDATE links SET')[0]['sql'];

        $this->assertStringContainsString('event_date = ?', $sql);
        $this->assertStringNotContainsString('precio_desde', $sql);
    }

    public function testUnPrecioNuevoActualizaElGuardado()
    {
        $this->laPaginaExiste();
        $this->yaExiste(['precio_desde' => '15000.00']);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento(['precio_desde' => 18000.0])]));
        $llamada = $this->db->callsFor('UPDATE links SET')[0];

        $this->assertSame(1, $r['actualizados']);
        $this->assertStringContainsString('precio_desde = ?', $llamada['sql']);
        $this->assertContains(18000.0, $llamada['params']);
    }

    public function testUnPrecioQueAparecePorPrimeraVezSeGuarda()
    {
        $this->laPaginaExiste();
        $this->yaExiste(['precio_desde' => null]);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento(['precio_desde' => 0.0])]));

        $this->assertStringContainsString('precio_desde = ?', $this->db->callsFor('UPDATE links SET')[0]['sql']);
    }

    /** Pasar a gratis es un dato, no una ausencia: tiene que llegar. */
    public function testUnGratisPisaUnPrecioAnterior()
    {
        $this->laPaginaExiste();
        $this->yaExiste(['precio_desde' => '15000.00']);
        $this->db->onWrite('UPDATE links SET', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento(['precio_desde' => 0.0])]));

        $this->assertStringContainsString('precio_desde = ?', $this->db->callsFor('UPDATE links SET')[0]['sql']);
    }

    // ---------------------------------------------------------- JSON-LD

    private function conLd(...$bloques)
    {
        return '<html><head>' . implode('', array_map(function ($b) {
            return '<script type="application/ld+json">' . json_encode($b) . '</script>';
        }, $bloques)) . '</head></html>';
    }

    public function testElJsonLdConUnaOfertaSola()
    {
        $html = $this->conLd(['@type' => 'Event', 'offers' => ['@type' => 'Offer', 'price' => '15000']]);

        $this->assertSame(15000.0, Importador::precioDeJsonLd($html));
    }

    public function testElJsonLdConVariasOfertasDaLaMasBarata()
    {
        $html = $this->conLd(['@type' => 'Event', 'offers' => [['price' => 50000], ['price' => '32000.50'], ['price' => 'consultar']]]);

        $this->assertSame(32000.5, Importador::precioDeJsonLd($html));
    }

    /** Las fichas traen varios bloques: el de la organización no tiene ofertas. */
    public function testElJsonLdRecorreTodosLosBloques()
    {
        $html = $this->conLd(['@type' => 'Organization', 'name' => 'X'], ['@type' => 'Event', 'offers' => ['price' => 9000]]);

        $this->assertSame(9000.0, Importador::precioDeJsonLd($html));
    }

    public function testElJsonLdDevuelveElCeroComoCero()
    {
        $this->assertSame(0.0, Importador::precioDeJsonLd($this->conLd(['offers' => ['price' => '0']])));
    }

    public function testSinOfertasNiJsonLdNoHayPrecio()
    {
        $this->assertNull(Importador::precioDeJsonLd($this->conLd(['@type' => 'Event', 'name' => 'X'])));
        $this->assertNull(Importador::precioDeJsonLd('<html><body>nada</body></html>'));
        $this->assertNull(Importador::precioDeJsonLd('<script type="application/ld+json">{roto</script>'));
        $this->assertNull(Importador::precioDeJsonLd(''));
        $this->assertNull(Importador::precioDeJsonLd(null));
    }

    public function testLaMuestraDeAllAccessDa50000()
    {
        $html = file_get_contents(__DIR__ . '/../../Fixtures/precios/artmedia/allaccess-la-joaqui-50000.html');

        $this->assertSame(50000.0, Importador::precioDeJsonLd($html));
    }

    public function testSePuedenCongelarVariosCampos()
    {
        $link = ['campos_editados' => 'text, event_time ,description'];

        $this->assertSame(['text', 'event_time', 'description'], Importador::camposEditados($link));
    }

    public function testSinMarcasNoHayNadaCongelado()
    {
        $this->assertSame([], Importador::camposEditados(['campos_editados' => null]));
        $this->assertSame([], Importador::camposEditados([]));
    }

    // ------------------------------------------------------- cuando falla

    /**
     * Cero eventos casi siempre significa que el sitio cambió y el adaptador
     * dejó de encontrar nada, no que se hayan quedado sin cartelera.
     */
    public function testUnaFuenteQueNoDevuelveNadaSeReportaComoProblema()
    {
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([]));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('cambió el sitio', $r['error']);
    }

    public function testSiElSitioSeCaeSeAnotaYNoSeRompe()
    {
        $this->db->onWrite('UPDATE import_sources', 1);

        $r = Importador::sincronizar($this->db, $this->fuente(), function () {
            throw new \RuntimeException('timeout');
        });

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('timeout', $r['error']);
    }

    /** Sin esto no habría forma de saber si el cron está levantando la fuente. */
    public function testCadaCorridaQuedaAnotadaEnLaFuente()
    {
        $this->laPaginaExiste();
        $this->db->onWrite('INSERT INTO links', 1);
        $this->db->onWrite('UPDATE import_sources', 1);

        Importador::sincronizar($this->db, $this->fuente(), $this->conEventos([$this->evento()]));

        $sql = $this->db->callsFor('UPDATE import_sources')[0]['sql'];

        $this->assertStringContainsString('ultima_corrida = NOW()', $sql);
        $this->assertStringContainsString('ultimo_resultado', $sql);
    }

    // ----------------------------------------------------- marcarEditados

    public function testMarcarEditadosSoloAnotaCamposConocidos()
    {
        $this->db->onSelect('SELECT campos_editados, origen FROM links', [[
            'campos_editados' => null, 'origen' => 'eventbrite',
        ]]);
        $this->db->onWrite('UPDATE links SET campos_editados', 1);

        Importador::marcarEditados($this->db, 99, ['text', 'inventado', 'event_date']);

        $this->assertSame('text,event_date', $this->db->paramsFor('UPDATE links SET campos_editados')[0]);
    }

    /** Un evento cargado a mano no tiene ningún cron que lo vaya a pisar. */
    public function testNoSeMarcaNadaEnUnEventoQueNoVinoDeUnaFuente()
    {
        $this->db->onSelect('SELECT campos_editados, origen FROM links', [[
            'campos_editados' => null, 'origen' => null,
        ]]);

        Importador::marcarEditados($this->db, 99, ['text']);

        $this->assertSame(0, $this->db->countCalls('UPDATE links SET campos_editados'));
    }

    public function testLasMarcasSeAcumulanSinRepetirse()
    {
        $this->db->onSelect('SELECT campos_editados, origen FROM links', [[
            'campos_editados' => 'text', 'origen' => 'eventbrite',
        ]]);
        $this->db->onWrite('UPDATE links SET campos_editados', 1);

        Importador::marcarEditados($this->db, 99, ['text', 'event_time']);

        $this->assertSame('text,event_time', $this->db->paramsFor('UPDATE links SET campos_editados')[0]);
    }
}
