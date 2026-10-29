<?php

namespace Tests\Unit\Handlers;

use Fechas;
use PublicHandler;
use Tests\Support\HandlerTestCase;

class PublicHandlerTest extends HandlerTestCase
{
    // =================================================================== page

    public function testPageRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::page($this->db, $this->post()), 'Method not allowed');
    }

    public function testPageExigeSlug()
    {
        $this->assertError(400, PublicHandler::page($this->db, $this->get()), 'Slug is required');
    }

    public function testPageRechazaSlugEnBlanco()
    {
        $this->assertError(400, PublicHandler::page($this->db, $this->get(['slug' => '   '])), 'Slug is required');
    }

    public function testPageDevuelve404SiNoExiste()
    {
        $res = PublicHandler::page($this->db, $this->get(['slug' => 'no-existe']));

        $this->assertError(404, $res, 'Page not found');
    }

    public function testPageBuscaPorSlugRecortado()
    {
        PublicHandler::page($this->db, $this->get(['slug' => '  mi-pagina  ']));

        $this->assertSame(['mi-pagina'], $this->db->paramsFor('FROM pages WHERE url_slug = ?'));
    }

    /**
     * Un evento compartido tiene que ofrecer el botón de reservar también en la
     * página que colabora. Llegaba sin disponibilidad —eso se agrega después de
     * la consulta, y sólo se hacía para los eventos propios—, así que el mismo
     * evento mostraba el botón en la página de origen y no en la otra.
     */
    public function testLosEventosColaboradosTraenLaDisponibilidadDeEntradas()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5, 'title' => 'Mi página']]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 20, 'type' => 'eventos']]);
        $this->db->onSelect('WHERE group_id = ? AND event_date >= ?', []);
        $this->db->onSelect('FROM event_collaborations ec', [[
            'id' => 300, 'text' => 'Un show compartido', 'is_collaborated' => 1,
        ]]);
        $this->db->onSelect('FROM event_ticketing WHERE link_id = ?', [[
            'activo' => 1, 'capacidad' => 50, 'precio' => 0, 'moneda' => 'ARS',
            'max_por_compra' => 4,
        ]]);
        $this->db->onSelect('FROM ticket_orders', [['0']]);
        $this->db->onSelect('COUNT(*) as count FROM page_followers', [['count' => '0']]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $colaborado = $res->body['page']['groups'][0]['collaborated_events'][0];
        $this->assertArrayHasKey('entradas', $colaborado, 'sin esto no se dibuja el botón de reservar');
        $this->assertTrue($colaborado['entradas']['activo']);
    }

    public function testPageDevuelveLaPaginaConGruposYSeguidores()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5, 'title' => 'Mi página']]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'links']]);
        $this->db->onSelect('FROM links WHERE group_id = ? ORDER BY position', [['id' => 100, 'text' => 'Un link']]);
        $this->db->onSelect('COUNT(*) as count FROM page_followers', [['count' => '42']]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $this->assertStatus(200, $res);
        $this->assertSame('Mi página', $res->body['page']['title']);
        $this->assertSame(42, $res->body['page']['follower_count'], 'el contador debe ser int, no string');
        $this->assertSame('Un link', $res->body['page']['groups'][0]['links'][0]['text']);
    }

    // ------------------------------------------------------------ productos

    /** Una página con un grupo de productos y uno de links. */
    private function paginaConProductos(array $productos)
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [
            ['id' => 10, 'type' => 'productos', 'title' => 'Tienda'],
            ['id' => 11, 'type' => 'links', 'title' => 'Links'],
        ]);
        $this->db->onSelect('INNER JOIN digital_products dp ON dp.link_id = l.id', $productos);
        $this->db->onSelect('FROM links WHERE group_id = ? ORDER BY position', [['id' => 100, 'text' => 'Un link']]);

        return PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));
    }

    private function productoDeLaBase(array $extra = [])
    {
        return array_merge([
            'id' => 400, 'group_id' => 10, 'text' => 'Guía', 'description' => 'Todo lo que hay que saber',
            'image_url' => 'https://img/tapa.jpg', 'url' => '', 'position' => 0, 'precio' => '2500.00', 'oculto' => '0',
            'archivo_nombre' => 'guia.pdf', 'archivo_bytes' => '1048576', 'archivo_tipo' => 'application/pdf',
        ], $extra);
    }

    public function testLaPaginaPublicaMuestraLosGruposDeProductos()
    {
        $res = $this->paginaConProductos([$this->productoDeLaBase()]);

        $this->assertStatus(200, $res);
        $this->assertSame(['Tienda', 'Links'], array_column($res->body['page']['groups'], 'title'));

        $producto = $res->body['page']['groups'][0]['links'][0];
        $this->assertSame(400, $producto['id']);
        $this->assertSame('Guía', $producto['text']);
        $this->assertSame('Todo lo que hay que saber', $producto['description']);
        $this->assertSame('https://img/tapa.jpg', $producto['image_url']);
        $this->assertSame(2500.0, $producto['precio']);
        $this->assertSame('guia.pdf', $producto['archivo_nombre']);
        $this->assertSame(1048576, $producto['archivo_bytes']);
    }

    /** Los productos se leen con el filtro de los que están en venta, no con uno propio. */
    public function testLosProductosSalenDelFiltroDeLosQueEstanEnVenta()
    {
        $this->paginaConProductos([]);

        $llamada = $this->db->callsFor('INNER JOIN digital_products dp ON dp.link_id = l.id')[0];
        $this->assertStringContainsString(\ProductosDigitales::CONDICION_EN_VENTA, $llamada['sql']);
        $this->assertSame([10], $llamada['params']);
        $this->assertStringNotContainsString('archivo_ruta,', $llamada['sql']);
    }

    /**
     * Aunque la consulta trajera la ruta del archivo (o cualquier otra columna
     * que se agregue a links), la respuesta pública sólo lleva lo que se ve.
     */
    public function testLaRespuestaPublicaNuncaLlevaLaRutaDelArchivo()
    {
        $res = $this->paginaConProductos([$this->productoDeLaBase([
            'archivo_ruta' => 'productos/' . str_repeat('a', 32),
            'link_original' => 99,
        ])]);

        $json = json_encode($res->body);
        $this->assertStringNotContainsString('archivo_ruta', $json);
        $this->assertStringNotContainsString(str_repeat('a', 32), $json);
        $this->assertSame(
            ['id', 'group_id', 'text', 'description', 'image_url', 'position', 'precio', 'archivo_nombre', 'archivo_bytes', 'tipo'],
            array_keys($res->body['page']['groups'][0]['links'][0])
        );
    }

    /**
     * Un físico (#42) sale con su envío, sus características y qué está
     * agotado, pero no con cuántas unidades quedan.
     */
    public function testUnFisicoSaleConCaracteristicasYAgotadasSinCantidades()
    {
        $this->db->onSelect('FROM product_attributes a', [
            ['atributo_id' => 70, 'product_id' => 30, 'atributo' => 'Talle', 'opcion_id' => 100, 'opcion' => 'S'],
            ['atributo_id' => 70, 'product_id' => 30, 'atributo' => 'Talle', 'opcion_id' => 101, 'opcion' => 'M'],
        ]);
        $this->db->onSelect('FROM product_variants', [
            ['product_id' => 30, 'clave' => '100', 'stock' => '0'],
            ['product_id' => 30, 'clave' => '101', 'stock' => '25'],
        ]);

        $res = $this->paginaConProductos([$this->productoDeLaBase([
            'producto_id' => 30, 'tipo' => 'fisico', 'archivo_nombre' => null, 'archivo_bytes' => null,
            'costo_envio' => '0.00', 'zona_envio' => 'Sólo CABA', 'stock' => null,
        ])]);

        $producto = $res->body['page']['groups'][0]['links'][0];
        $this->assertSame('fisico', $producto['tipo']);
        $this->assertSame(0.0, $producto['costo_envio']);
        $this->assertSame('Sólo CABA', $producto['zona_envio']);
        $this->assertSame('Talle', $producto['caracteristicas'][0]['nombre']);
        $this->assertSame(['100'], $producto['agotadas']);
        $this->assertFalse($producto['agotado']);
        $this->assertArrayNotHasKey('stock', $producto);
        $this->assertArrayNotHasKey('variantes', $producto);
        $this->assertStringNotContainsString('25', json_encode($producto['caracteristicas']));
    }

    public function testUnDigitalSaleSinLoDeLosFisicos()
    {
        $res = $this->paginaConProductos([$this->productoDeLaBase()]);

        $producto = $res->body['page']['groups'][0]['links'][0];
        $this->assertSame('digital', $producto['tipo']);
        $this->assertArrayNotHasKey('caracteristicas', $producto);
    }

    public function testPageDevuelveCeroSeguidoresSiNoHayFilas()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $this->assertSame(0, $res->body['page']['follower_count']);
    }

    public function testPageSoloMuestraEventosFuturos()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);

        PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $llamada = $this->db->callsFor('WHERE group_id = ? AND event_date')[0];

        // >= y no >: un evento de hoy a las 20:30 todavía no pasó.
        $this->assertStringContainsString('event_date >= ?', $llamada['sql']);
        $this->assertContains(Fechas::hoy(), $llamada['params'], 'compara contra hoy en Argentina');
    }

    /**
     * El corte se calcula en Argentina y no con CURDATE(): el servidor corre en
     * UTC, tres horas adelante, así que a partir de las 21:00 la base creería
     * que ya es el día siguiente y los eventos de esa misma noche desaparecerían
     * de la página justo cuando la gente los está por ir a ver.
     */
    public function testElCorteDeFechaNoUsaLaHoraDelServidor()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);

        PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        foreach ($this->db->log() as $llamada) {
            $this->assertStringNotContainsString('CURDATE()', $llamada['sql']);
        }
    }

    public function testPageCargaColaboradoresDeCadaEvento()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);
        $this->db->onSelect('FROM links WHERE group_id = ? AND event_date', [['id' => 100, 'text' => 'Evento']]);
        $this->db->onSelect('JOIN pages p ON ec.collaborator_page_id', [['page_title' => 'Colaboradora']]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $evento = $res->body['page']['groups'][0]['links'][0];
        $this->assertSame('Colaboradora', $evento['collaborators'][0]['page_title']);
    }

    public function testPageSoloTraeColaboracionesAceptadas()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);

        PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));

        $sql = $this->db->callsFor('FROM event_collaborations ec JOIN links l')[0]['sql'];

        $this->assertStringContainsString("ec.status = 'accepted'", $sql);
    }

    /**
     * El evento sale igual venga de donde venga.
     *
     * Los tres caminos —los eventos propios de un grupo, los colaborados y el
     * de la URL suelta— pasan por completarEvento(). El bug que motivó esto
     * fue justamente que cada uno lo armaba por su cuenta y uno se olvidaba de
     * la disponibilidad. Si mañana alguien agrega una consulta que no delega,
     * este test lo dice.
     */
    public function testLosTresCaminosArmanElEventoIgual()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 20, 'type' => 'eventos']]);
        $this->db->onSelect('WHERE group_id = ? AND event_date >= ?', [['id' => 100, 'text' => 'Propio']]);
        $this->db->onSelect('FROM event_collaborations ec JOIN links l', [['id' => 300, 'text' => 'Compartido']]);
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 100, 'text' => 'Propio']]);

        $pagina = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']))->body['page'];
        $suelto = PublicHandler::event($this->db, $this->get(['id' => '100']))->body['event'];

        $eventos = [
            'propio'     => $pagina['groups'][0]['links'][0],
            'colaborado' => $pagina['groups'][0]['collaborated_events'][0],
            'suelto'     => $suelto,
        ];

        foreach ($eventos as $camino => $evento) {
            $this->assertArrayHasKey('entradas', $evento, "el evento $camino llega sin disponibilidad");
            $this->assertArrayHasKey('collaborators', $evento, "el evento $camino llega sin colaboradores");
        }
    }

    public function testPageDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('FROM pages WHERE url_slug = ?', 'sin conexión');

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'x']));

        $this->assertError(500, $res, 'Server error: sin conexión');
    }

    // ================================================================== event

    public function testEventExigeId()
    {
        $this->assertError(400, PublicHandler::event($this->db, $this->get()), 'ID de evento requerido');
    }

    public function testEventDevuelve404SiNoExiste()
    {
        $res = PublicHandler::event($this->db, $this->get(['id' => '99']));

        $this->assertError(404, $res, 'Evento no encontrado');
    }

    public function testEventDevuelveElEventoConSuPagina()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [[
            'id' => 100,
            'text' => 'Mi evento',
            'page_title' => 'Mi página',
            'page_slug' => 'mi-pagina',
        ]]);

        $res = PublicHandler::event($this->db, $this->get(['id' => '100']));

        $this->assertStatus(200, $res);
        $this->assertSame('Mi evento', $res->body['event']['text']);
        $this->assertSame('mi-pagina', $res->body['event']['page_slug']);
    }

    public function testEventSoloDevuelveLinksDeGruposDeEventos()
    {
        PublicHandler::event($this->db, $this->get(['id' => '100']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString("lg.type = 'eventos'", $sql);
    }

    public function testEventIncluyeElTextoDelBoton()
    {
        PublicHandler::event($this->db, $this->get(['id' => '100']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString('l.url_text', $sql);
    }

    /**
     * El detalle de un evento se ve en una pantalla aparte, pero es de esa
     * página y no de Rezonar: sin los colores se dibujaba blanco con el verde
     * nuestro, y quien llegaba por un enlace directo no reconocía de quién era
     * la fecha.
     */
    public function testEventTraeLosColoresDeLaPagina()
    {
        PublicHandler::event($this->db, $this->get(['id' => '100']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        foreach (['p.primary_color', 'p.secondary_color', 'p.card_color',
                  'p.title_color', 'p.background_color', 'p.text_color', 'p.template',
                  'p.background_image'] as $campo) {
            $this->assertStringContainsString($campo, $sql, "Falta $campo");
        }
    }

    // =============================================================== producto

    private function productoConPagina(array $extra = [])
    {
        return $this->productoDeLaBase(array_merge([
            'page_title' => 'La Sala', 'page_slug' => 'la-sala', 'page_image' => 'https://img/logo.jpg',
            'primary_color' => '#ff8800', 'secondary_color' => null, 'card_color' => null, 'title_color' => null,
            'background_color' => '#111111', 'text_color' => '#eeeeee', 'template' => 'cards',
            'background_image' => 'https://img/fondo.jpg',
        ], $extra));
    }

    public function testProductoRechazaMetodosDistintosDeGet()
    {
        $this->assertStatus(405, PublicHandler::producto($this->db, $this->post(['id' => 400])));
    }

    /** @dataProvider idsInvalidos */
    public function testProductoExigeUnIdValido($id)
    {
        $this->assertError(400, PublicHandler::producto($this->db, $this->get(['id' => $id])), 'ID de producto requerido');
        $this->assertSame(0, $this->db->countCalls('FROM links l'));
    }

    public static function idsInvalidos()
    {
        return ['vacío' => [''], 'cero' => ['0'], 'negativo' => ['-4'], 'texto' => ['abc']];
    }

    /**
     * Uno que no existe, oculto, borrado o sin archivo: la consulta no lo
     * trae y la respuesta es la misma, sin nada que diga cuál era.
     */
    public function testProductoQueNoEstaEnVentaDa404SinDetalles()
    {
        $res = PublicHandler::producto($this->db, $this->get(['id' => '400']));

        $this->assertError(404, $res, 'Este producto ya no está disponible.');
        $this->assertSame(['error'], array_keys($res->body));
    }

    public function testProductoUsaElFiltroDeLosQueEstanEnVenta()
    {
        PublicHandler::producto($this->db, $this->get(['id' => '400']));

        $llamada = $this->db->callsFor('INNER JOIN digital_products dp ON dp.link_id = l.id')[0];
        $this->assertStringContainsString(\ProductosDigitales::CONDICION_EN_VENTA, $llamada['sql']);
        $this->assertStringContainsString("lg.type = 'productos'", $llamada['sql']);
        $this->assertSame([400], $llamada['params']);
    }

    public function testProductoDevuelveElProductoConSuPaginaYSusColores()
    {
        $this->db->onSelect('INNER JOIN digital_products dp ON dp.link_id = l.id', [$this->productoConPagina()]);

        $res = PublicHandler::producto($this->db, $this->get(['id' => '400']));

        $this->assertStatus(200, $res);
        $producto = $res->body['producto'];
        $this->assertSame(400, $producto['id']);
        $this->assertSame('Guía', $producto['text']);
        $this->assertSame(2500.0, $producto['precio']);
        $this->assertSame('guia.pdf', $producto['archivo_nombre']);
        $this->assertSame(1048576, $producto['archivo_bytes']);
        $this->assertSame('La Sala', $producto['page_title']);
        $this->assertSame('la-sala', $producto['page_slug']);
        $this->assertSame('#ff8800', $producto['primary_color']);
        $this->assertSame('#111111', $producto['background_color']);
    }

    /**
     * Aunque la consulta trajera la ruta del archivo, el estado o datos de
     * ventas, la respuesta sólo lleva las dos listas cerradas.
     */
    public function testProductoNuncaLlevaLaRutaNiDatosDeVentas()
    {
        $this->db->onSelect('INNER JOIN digital_products dp ON dp.link_id = l.id', [$this->productoConPagina([
            'archivo_ruta' => 'productos/' . str_repeat('b', 32),
            'token_hash' => 'secreto', 'ventas' => 12, 'oculto' => '0', 'user_id' => 9, 'page_id' => 5,
        ])]);

        $res = PublicHandler::producto($this->db, $this->get(['id' => '400']));

        $json = json_encode($res->body);
        $this->assertStringNotContainsString('archivo_ruta', $json);
        $this->assertStringNotContainsString(str_repeat('b', 32), $json);
        $this->assertStringNotContainsString('secreto', $json);
        $this->assertSame(
            array_merge(PublicHandler::CAMPOS_DE_PRODUCTO, PublicHandler::CAMPOS_DE_PAGINA_DEL_PRODUCTO),
            array_keys($res->body['producto'])
        );
    }

    // ================================================================= events

    public function testEventsUsaElRangoPorDefectoDeTreintaDias()
    {
        PublicHandler::events($this->db, $this->get());

        $params = $this->db->paramsFor('l.event_date BETWEEN ? AND ?');

        $this->assertSame(date('Y-m-d'), $params[0]);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $params[1]);
    }

    public function testEventsRespetaElRangoPedido()
    {
        PublicHandler::events($this->db, $this->get(['start' => '2026-01-01', 'end' => '2026-01-31']));

        $this->assertSame(['2026-01-01', '2026-01-31'], $this->db->paramsFor('l.event_date BETWEEN ? AND ?'));
    }

    public function testRangoPorDefectoEsDeterministaConFechaFija()
    {
        list($inicio, $fin) = PublicHandler::rangoPorDefecto(mktime(0, 0, 0, 1, 1, 2026));

        $this->assertSame('2026-01-01', $inicio);
        $this->assertSame('2026-01-31', $fin);
    }

    public function testEventsDevuelveLista()
    {
        $this->db->onSelect('l.event_date BETWEEN ? AND ?', [
            ['id' => 1, 'title' => 'Uno'],
            ['id' => 2, 'title' => 'Dos'],
        ]);

        $res = PublicHandler::events($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertCount(2, $res->body['events']);
    }

    // ============================================================== followers

    public function testFollowersExigePageId()
    {
        $this->assertError(400, PublicHandler::followers($this->db, $this->get()), 'ID de página requerido');
    }

    public function testFollowersRechazaPaginaInexistente()
    {
        $res = PublicHandler::followers($this->db, $this->get(['page_id' => '99']));

        $this->assertError(404, $res, 'Página no encontrada');
    }

    public function testFollowersDevuelveLaLista()
    {
        $this->db->onSelect('SELECT id FROM pages WHERE id = ?', [['id' => 5]]);
        $this->db->onSelect('FROM page_followers pf JOIN users u', [
            ['email' => 'a@b.com', 'page_title' => 'Su página', 'followed_at' => '2026-01-01'],
        ]);

        $res = PublicHandler::followers($this->db, $this->get(['page_id' => '5']));

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['followers']);
    }

    public function testFollowersOrdenaPorMasRecientePrimero()
    {
        $this->db->onSelect('SELECT id FROM pages WHERE id = ?', [['id' => 5]]);

        PublicHandler::followers($this->db, $this->get(['page_id' => '5']));

        $sql = $this->db->callsFor('FROM page_followers pf JOIN users u')[0]['sql'];
        $this->assertStringContainsString('ORDER BY pf.created_at DESC', $sql);
    }

    // =========================================================== recent-pages

    public function testRecentPagesRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::recentPages($this->db, $this->post()), 'Method not allowed');
    }

    public function testRecentPagesLimitaADoceResultados()
    {
        PublicHandler::recentPages($this->db, $this->get());

        $sql = $this->db->callsFor('FROM pages p JOIN users u')[0]['sql'];

        $this->assertStringContainsString('LIMIT 12', $sql);
        $this->assertStringContainsString('ORDER BY p.created_at DESC', $sql);
    }

    public function testRecentPagesIncluyeElContadorDeSeguidores()
    {
        $this->db->onSelect('FROM pages p JOIN users u', [
            ['id' => 1, 'title' => 'Una', 'follower_count' => '3'],
        ]);

        $res = PublicHandler::recentPages($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertSame('3', $res->body['pages'][0]['follower_count']);
    }

    public function testRecentPagesDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('FROM pages p JOIN users u', 'timeout');

        $this->assertError(500, PublicHandler::recentPages($this->db, $this->get()), 'Server error: timeout');
    }

    // ========================================================== recent-events

    public function testRecentEventsRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::recentEvents($this->db, $this->post()), 'Method not allowed');
    }

    public function testRecentEventsSoloTraeEventosFuturos()
    {
        PublicHandler::recentEvents($this->db, $this->get());

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString("l.event_date >= '" . Fechas::hoy() . "'", $sql);
        $this->assertStringContainsString("lg.type = 'eventos'", $sql);
        $this->assertStringContainsString('LIMIT 30', $sql);
    }

    public function testRecentEventsDevuelveLista()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 1, 'text' => 'Evento']]);

        $res = PublicHandler::recentEvents($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['events']);
    }

    // ------------------------------------------------- precio de referencia

    public function testRecentEventsTraeElPrecioDeReferencia()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [
            ['id' => 1, 'precio_referencia' => '15000.00'],
            ['id' => 2, 'precio_referencia' => null],
        ]);

        $res = PublicHandler::recentEvents($this->db, $this->get());
        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertSame([15000.0, null], array_column($res->body['events'], 'precio_referencia'));
        $this->assertStringContainsString(\PrecioDeReferencia::JOIN, $sql);
        $this->assertStringContainsString(\PrecioDeReferencia::COLUMNA, $sql);
    }

    /** La lista viene recortada: el filtro va en la consulta, antes del LIMIT. */
    public function testRecentEventsFiltraGratisEnLaConsulta()
    {
        PublicHandler::recentEvents($this->db, $this->get(['precio' => 'gratis']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString('AND ' . \PrecioDeReferencia::EXPRESION . ' = 0', $sql);
        $this->assertSame([], $this->db->paramsFor('FROM links l JOIN link_groups lg'));
    }

    public function testRecentEventsFiltraHastaUnMontoConParametro()
    {
        PublicHandler::recentEvents($this->db, $this->get(['precio' => 'hasta:25000']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString('AND ' . \PrecioDeReferencia::EXPRESION . ' <= ?', $sql);
        $this->assertStringNotContainsString('25000', $sql, 'el monto va como parámetro');
        $this->assertSame([25000.0], $this->db->paramsFor('FROM links l JOIN link_groups lg'));
    }

    public function testRecentEventsSinFiltroNoFiltraPorPrecio()
    {
        PublicHandler::recentEvents($this->db, $this->get());

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringNotContainsString('AND ' . \PrecioDeReferencia::EXPRESION, $sql);
    }

    public function testRecentEventsRechazaUnFiltroDePrecioInvalido()
    {
        $this->assertError(400, PublicHandler::recentEvents($this->db, $this->get(['precio' => 'barato'])), 'gratis');
        $this->assertSame([], $this->db->log());
    }

    public function testSearchTraeElPrecioDeReferenciaDeLosEventos()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 100, 'title' => 'Recital', 'precio_referencia' => '0.00']]);

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertSame(0.0, $res->body['results'][0]['precio_referencia']);
        $this->assertStringContainsString(\PrecioDeReferencia::JOIN, $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql']);
    }

    public function testEventsTraeElPrecioDeReferencia()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 1, 'precio_referencia' => '8000.00']]);

        $res = PublicHandler::events($this->db, $this->get());

        $this->assertSame(8000.0, $res->body['events'][0]['precio_referencia']);
    }

    /** Vende por Rezonar: el precio es el de la venta, sin otra consulta. */
    public function testElEventoQueVendeTraeElPrecioDeLaVenta()
    {
        $this->db->onSelect('WHERE l.id = ? AND lg.type', [['id' => 7, 'text' => 'Show', 'precio_desde' => '99999.00']]);
        $this->db->onSelect('FROM event_ticketing WHERE link_id', [[
            'link_id' => 7, 'activo' => 1, 'capacidad' => 100, 'precio' => '12000.00', 'moneda' => 'ARS',
            'max_por_compra' => 10, 'plano' => null, 'tipos' => null,
        ]]);

        $res = PublicHandler::event($this->db, $this->get(['id' => '7']));

        $this->assertSame(12000.0, $res->body['event']['precio_referencia']);
        $this->assertSame(1, $this->db->countCalls('FROM event_ticketing'), 'reusa la disponibilidad');
    }

    public function testElEventoQueNoVendeTraeSuPrecioDesde()
    {
        $this->db->onSelect('WHERE l.id = ? AND lg.type', [['id' => 7, 'text' => 'Show', 'precio_desde' => '0.00']]);

        $res = PublicHandler::event($this->db, $this->get(['id' => '7']));

        $this->assertSame(0.0, $res->body['event']['precio_referencia']);
        $this->assertStringContainsString('l.precio_desde', $this->db->callsFor('WHERE l.id = ? AND lg.type')[0]['sql']);
    }

    public function testLosEventosDeLaPaginaTraenElPrecioDeReferencia()
    {
        $this->db->onSelect('FROM pages WHERE url_slug = ?', [['id' => 5]]);
        $this->db->onSelect('FROM link_groups WHERE page_id = ?', [['id' => 10, 'type' => 'eventos']]);
        $this->db->onSelect('FROM links WHERE group_id = ? AND event_date', [['id' => 100, 'text' => 'Evento', 'precio_desde' => '4500.00']]);
        $this->db->onSelect('FROM event_collaborations ec JOIN links l', [['id' => 200, 'text' => 'Colaborado', 'precio_desde' => null]]);

        $res = PublicHandler::page($this->db, $this->get(['slug' => 'mi-pagina']));
        $grupo = $res->body['page']['groups'][0];

        $this->assertSame(4500.0, $grupo['links'][0]['precio_referencia']);
        $this->assertNull($grupo['collaborated_events'][0]['precio_referencia']);
    }

    // ================================================================= search

    public function testSearchRechazaMetodosDistintosDeGet()
    {
        $this->assertError(405, PublicHandler::search($this->db, $this->post()), 'Method not allowed');
    }

    /**
     * @dataProvider consultasCortas
     */
    public function testSearchNoConsultaConMenosDeDosCaracteres($q)
    {
        $res = PublicHandler::search($this->db, $this->get($q === null ? [] : ['q' => $q]));

        $this->assertStatus(200, $res);
        $this->assertSame(['results' => []], $res->body);
        $this->assertSame([], $this->db->log(), 'No debe tocar la base con una consulta tan corta');
    }

    public function consultasCortas()
    {
        return [
            'sin parámetro' => [null],
            'vacío' => [''],
            'una letra' => ['a'],
            'sólo espacios' => ['   '],
            'espacio y letra' => [' a '],
        ];
    }

    public function testSearchBuscaPaginasYEventos()
    {
        $this->db->onSelect('FROM pages p WHERE p.title LIKE', [
            ['id' => 1, 'title' => 'Rock en el parque'],
        ]);
        $this->db->onSelect('FROM links l JOIN link_groups lg', [
            ['id' => 100, 'title' => 'Recital de rock'],
        ]);

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertStatus(200, $res);
        $this->assertCount(2, $res->body['results']);
    }

    public function testSearchEtiquetaCadaResultadoConSuTipo()
    {
        $this->db->onSelect('FROM pages p WHERE p.title LIKE', [['id' => 1, 'title' => 'Una página']]);
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 100, 'title' => 'Un evento']]);

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertSame('page', $res->body['results'][0]['type']);
        $this->assertSame('event', $res->body['results'][1]['type']);
    }

    public function testSearchDevuelveLasPaginasAntesQueLosEventos()
    {
        $this->db->onSelect('FROM pages p WHERE p.title LIKE', [['id' => 1], ['id' => 2]]);
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['id' => 100]]);

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $tipos = array_column($res->body['results'], 'type');
        $this->assertSame(['page', 'page', 'event'], $tipos);
    }

    public function testSearchEnvuelveElTerminoEnComodines()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertSame(['%rock%', '%rock%'], $this->db->paramsFor('FROM pages p WHERE p.title LIKE'));
    }

    public function testSearchRecortaEspaciosDelTermino()
    {
        PublicHandler::search($this->db, $this->get(['q' => '  rock  ']));

        $this->assertSame(['%rock%', '%rock%'], $this->db->paramsFor('FROM pages p WHERE p.title LIKE'));
    }

    public function testSearchBuscaEventosPorTextoDescripcionYDireccion()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertSame(
            ['%rock%', '%rock%', '%rock%'],
            $this->db->paramsFor('FROM links l JOIN link_groups lg')
        );
    }

    public function testSearchNoDevuelveEventosPasados()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $sql = $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql'];

        $this->assertStringContainsString("l.event_date >= '" . Fechas::hoy() . "'", $sql);
    }

    public function testSearchLimitaCadaTipoADiezResultados()
    {
        PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertStringContainsString('LIMIT 10', $this->db->callsFor('FROM pages p WHERE p.title LIKE')[0]['sql']);
        $this->assertStringContainsString('LIMIT 10', $this->db->callsFor('FROM links l JOIN link_groups lg')[0]['sql']);
    }

    public function testSearchTrataElTerminoComoParametroNoComoSql()
    {
        // El término va siempre por placeholder: un intento de inyección debe
        // viajar como dato literal.
        PublicHandler::search($this->db, $this->get(['q' => "'; DROP TABLE pages; --"]));

        $sql = $this->db->callsFor('FROM pages p WHERE p.title LIKE')[0]['sql'];

        $this->assertStringNotContainsString('DROP TABLE', $sql);
        $this->assertSame("%'; DROP TABLE pages; --%", $this->db->paramsFor('FROM pages p WHERE p.title LIKE')[0]);
    }

    public function testSearchDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('FROM pages p WHERE p.title LIKE', 'índice corrupto');

        $res = PublicHandler::search($this->db, $this->get(['q' => 'rock']));

        $this->assertError(500, $res, 'Server error: índice corrupto');
    }

    // ================================================================= costos

    /** Quien todavía no tiene cuenta tiene que poder ver lo que se le cobra. */
    public function testCostosNoExigeSesion()
    {
        $res = PublicHandler::costos(null, $this->get());

        $this->assertStatus(200, $res);
    }

    /** Los mismos números que se aplican al cobrar, no una copia aparte. */
    public function testCostosPublicaLoQueSeAplicaAlCobrar()
    {
        $res = PublicHandler::costos(null, $this->get());

        $this->assertSame(\Comision::porcentaje(), $res->body['comision']);
        $this->assertSame(\Comision::mercadoPago(), $res->body['mercadopago']);
    }

    public function testCostosNoTocaLaBase()
    {
        PublicHandler::costos($this->db, $this->get());

        $this->assertSame(0, $this->db->countCalls('SELECT'));
    }

    public function testCostosRechazaOtrosMetodos()
    {
        $this->assertError(405, PublicHandler::costos($this->db, $this->post()), 'Method not allowed');
    }
}
