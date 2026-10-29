<?php

namespace Tests\Unit\Handlers;

use ProductosDigitales;
use ProductosHandler;
use Request;
use Tests\Support\FakeDisco;
use Tests\Support\HandlerTestCase;

class ProductosHandlerTest extends HandlerTestCase
{
    /** @var FakeDisco */
    private $disco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disco = new FakeDisco();
    }

    // ============================================================ acceso

    public function testRechazaMetodosDistintosDePost()
    {
        $req = new Request('GET', [], ['link_id' => 5], $this->user());

        $this->assertError(405, ProductosHandler::archivo($this->db, $req, $this->disco));
    }

    public function testExigeSesion()
    {
        $res = $this->subir($this->archivo(), null);

        $this->assertError(401, $res, 'Unauthorized');
        $this->assertSame([], $this->disco->movidos);
    }

    public function testExigeElProducto()
    {
        $req = new Request('POST', [], [], $this->user(), ['archivo' => $this->archivo()]);

        $this->assertError(400, ProductosHandler::archivo($this->db, $req, $this->disco), 'link_id');
    }

    public function testRechazaUnProductoDeOtraPagina()
    {
        // Sin regla para PageAccess, canManageLink devuelve false.
        $res = $this->subir($this->archivo());

        $this->assertError(404, $res, 'Producto no encontrado');
        $this->assertSame([], $this->disco->movidos);
        $this->assertNoWrites();
    }

    /** Un link común o un evento no tienen dónde guardar un archivo. */
    public function testRechazaUnLinkQueNoEsProducto()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'eventos']]);

        $res = $this->subir($this->archivo());

        $this->assertError(400, $res, 'no es un producto digital');
        $this->assertSame([], $this->disco->movidos);
    }

    /** Un físico se manda, no se descarga (#42). */
    public function testRechazaElArchivoDeUnProductoFisico()
    {
        $this->esProducto(['tipo' => 'fisico']);

        $res = $this->subir($this->archivo());

        $this->assertError(400, $res, 'físico no lleva archivo');
        $this->assertSame([], $this->disco->movidos);
        $this->assertNoWrites();
    }

    // ============================================================ tamaño

    public function testUnArchivoDeMasDe50MbSeRechazaDiciendoElMaximo()
    {
        $this->esProducto();

        $res = $this->subir($this->archivo(['size' => ProductosDigitales::MAX_BYTES + 1]));

        $this->assertError(413, $res, '50 MB');
        $this->assertSame([], $this->disco->movidos);
    }

    public function testUnArchivoDeJusto50MbPasa()
    {
        $this->esProducto();

        $this->assertStatus(200, $this->subir($this->archivo(['size' => ProductosDigitales::MAX_BYTES])));
    }

    /**
     * @dataProvider cortesDePhp
     *
     * PHP puede cortar el archivo antes de que llegue: el mensaje tiene que
     * decir el máximo y no uno genérico.
     */
    public function testSiPhpCortaElArchivoElMensajeDiceElMaximo($codigo)
    {
        $this->esProducto();

        $res = $this->subir($this->archivo(['error' => $codigo, 'size' => 0, 'tmp_name' => '']));

        $this->assertError(413, $res, '50 MB');
    }

    public function cortesDePhp()
    {
        return [
            'upload_max_filesize' => [UPLOAD_ERR_INI_SIZE],
            'MAX_FILE_SIZE del formulario' => [UPLOAD_ERR_FORM_SIZE],
        ];
    }

    /**
     * Pasado post_max_size, PHP descarta el POST entero: $_FILES llega vacío
     * aunque el navegador haya mandado 80 MB.
     */
    public function testUnPostDescartadoPorPostMaxSizeDiceElMaximo()
    {
        $this->esProducto();
        $req = new Request('POST', [], ['link_id' => 5], $this->user(), [], ['Content-Length' => '83886080']);

        $res = ProductosHandler::archivo($this->db, $req, $this->disco);

        $this->assertError(413, $res, '50 MB');
    }

    public function testSinArchivoYSinCuerpoPideElArchivo()
    {
        $this->esProducto();
        $req = new Request('POST', [], ['link_id' => 5], $this->user(), []);

        $this->assertError(400, ProductosHandler::archivo($this->db, $req, $this->disco), 'No llegó ningún archivo');
    }

    public function testUnArchivoCortadoAMitadDeCaminoSeRechaza()
    {
        $this->esProducto();

        $res = $this->subir($this->archivo(['error' => UPLOAD_ERR_PARTIAL]));

        $this->assertError(400, $res, 'no llegó entero');
        $this->assertSame([], $this->disco->movidos);
    }

    public function testUnArchivoVacioSeRechaza()
    {
        $this->esProducto();

        $this->assertError(400, $this->subir($this->archivo(['size' => 0])), 'vacío');
    }

    // ===================================================== almacenamiento

    public function testElArchivoVaALaCarpetaPrivadaConNombreAleatorio()
    {
        $this->esProducto();

        $this->assertStatus(200, $this->subir($this->archivo()));

        $destino = $this->disco->movidos[0][1];
        $this->assertStringStartsWith(ProductosDigitales::raiz() . 'productos/', $destino);
        $this->assertMatchesRegularExpression('#/productos/[0-9a-f]{32}$#', $destino);
    }

    /**
     * @dataProvider nombresPeligrosos
     *
     * El nombre que manda el usuario nunca arma la ruta, y el archivo se
     * guarda sin extensión: ni .php para ejecutarse, ni .jpg para que el
     * .htaccess de uploads/ lo deje ver.
     */
    public function testElNombreEnviadoNoTocaLaRuta($nombre)
    {
        $this->esProducto();

        $this->subir($this->archivo(['name' => $nombre]));

        $destino = $this->disco->movidos[0][1];
        $this->assertStringNotContainsString('..', substr($destino, strlen(ProductosDigitales::raiz())));
        $this->assertStringNotContainsString('.', basename($destino));
    }

    public function nombresPeligrosos()
    {
        return [
            'php' => ['shell.php'],
            'imagen' => ['foto.jpg'],
            'path traversal' => ['../../../config.php'],
            'htaccess' => ['.htaccess'],
        ];
    }

    public function testLaCarpetaQuedaConUnHtaccessQueNiegaTodo()
    {
        $this->esProducto();

        $this->subir($this->archivo());

        $htaccess = ProductosDigitales::raiz() . 'productos/.htaccess';
        $this->assertArrayHasKey($htaccess, $this->disco->archivos);
        $this->assertStringContainsString('Require all denied', $this->disco->archivos[$htaccess]);
    }

    /** Sin el .htaccess el archivo quedaría público: no se guarda. */
    public function testSiNoSePuedeProtegerLaCarpetaNoSeGuardaNada()
    {
        $this->esProducto();
        $this->disco->escribeArchivos = false;

        $res = $this->subir($this->archivo());

        $this->assertStatus(500, $res);
        $this->assertSame([], $this->disco->movidos);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO digital_products'));
    }

    public function testGuardaElNombreOriginalElTamanoYElTipoDetectado()
    {
        $this->esProducto();
        $this->disco->mime = 'application/zip';

        $this->subir($this->archivo(['name' => 'Pack de fotos.zip', 'size' => 2048, 'type' => 'image/png']));

        $params = $this->db->paramsFor('INSERT INTO digital_products');
        $this->assertSame(5, $params[0]);
        $this->assertMatchesRegularExpression('#^productos/[0-9a-f]{32}$#', $params[1]);
        $this->assertSame('Pack de fotos.zip', $params[2]);
        $this->assertSame(2048, $params[3]);
        // El tipo es el que se detectó, no el que dijo el navegador.
        $this->assertSame('application/zip', $params[4]);
    }

    public function testSiNoSeDetectaElTipoQuedaComoBinario()
    {
        $this->esProducto();
        $this->disco->mime = null;

        $this->subir($this->archivo());

        $this->assertSame('application/octet-stream', $this->db->paramsFor('INSERT INTO digital_products')[4]);
    }

    public function testDevuelveElProductoSinLaRutaEnDisco()
    {
        $this->esProducto();
        $this->db->onSelect('LEFT JOIN digital_products', [[
            'id' => 5, 'text' => 'Guía', 'precio' => '0.00',
            'archivo_nombre' => 'guia.pdf', 'archivo_bytes' => 1024, 'archivo_tipo' => 'application/pdf',
        ]]);

        $res = $this->subir($this->archivo());

        $this->assertStatus(200, $res);
        $this->assertSame('guia.pdf', $res->body['link']['archivo_nombre']);
        $this->assertArrayNotHasKey('archivo_ruta', $res->body['link']);
        $sql = $this->db->callsFor('LEFT JOIN digital_products')[0]['sql'];
        $this->assertStringNotContainsString('archivo_ruta', $sql);
    }

    public function testSiFallaElMovimientoNoSeTocaLaBase()
    {
        $this->esProducto();
        $this->disco->funciona = false;
        $this->disco->escribeArchivos = true;
        // La carpeta ya estaba preparada de antes.
        $this->disco->archivos[ProductosDigitales::raiz() . '.htaccess'] = 'x';
        $this->disco->archivos[ProductosDigitales::raiz() . 'productos/.htaccess'] = 'x';

        $res = $this->subir($this->archivo());

        $this->assertStatus(500, $res);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO digital_products'));
    }

    /** Un archivo sin fila que lo nombre no lo encuentra nadie nunca más. */
    public function testSiFallaLaBaseSeBorraElArchivoRecienGuardado()
    {
        $this->esProducto();
        $this->db->failOn('INSERT INTO digital_products', 'sin conexión');

        $res = $this->subir($this->archivo());

        $this->assertStatus(500, $res);
        $this->assertSame([$this->disco->movidos[0][1]], $this->disco->borrados);
    }

    // ========================================================== reemplazo

    public function testAlReemplazarSeBorraElArchivoAnterior()
    {
        $this->esProducto(['archivo_ruta' => 'productos/' . str_repeat('a', 32)]);

        $this->subir($this->archivo());

        $this->assertSame([ProductosDigitales::raiz() . 'productos/' . str_repeat('a', 32)], $this->disco->borrados);
    }

    public function testElPrimerArchivoNoBorraNada()
    {
        $this->esProducto(['archivo_ruta' => null]);

        $this->subir($this->archivo());

        $this->assertSame([], $this->disco->borrados);
    }

    /** Si el nuevo no se pudo guardar, el producto sigue con el de siempre. */
    public function testSiFallaElReemplazoElAnteriorQueda()
    {
        $this->esProducto(['archivo_ruta' => 'productos/' . str_repeat('a', 32)]);
        $this->db->failOn('INSERT INTO digital_products', 'sin conexión');

        $this->subir($this->archivo());

        $this->assertNotContains(ProductosDigitales::raiz() . 'productos/' . str_repeat('a', 32), $this->disco->borrados);
    }

    // ============================================================ ventas

    private function venta(array $extra = [])
    {
        return array_merge([
            'id' => 7, 'codigo' => 'ABCDEF123456', 'nombre' => 'Ana', 'email' => 'ana@test.local',
            'telefono' => null, 'total' => '2500.00', 'estado' => 'pagada',
            'creado_en' => '2026-10-01 10:00:00', 'pagada_en' => '2026-10-01 10:05:00',
            'descargas' => '0', 'primera_descarga_en' => null, 'ultima_descarga_en' => null,
            'producto_id' => 3, 'link_id' => 5, 'producto' => 'Guía', 'producto_borrado_en' => null,
        ], $extra);
    }

    private function verVentas(array $query, $user = 'default')
    {
        return ProductosHandler::ventas($this->db, $this->get($query, $user === 'default' ? $this->user() : $user));
    }

    public function testLasVentasExigenSesion()
    {
        $this->assertError(401, $this->verVentas(['link_id' => 5], null));
    }

    public function testLasVentasSoloSeLeen()
    {
        $res = ProductosHandler::ventas($this->db, $this->post([], $this->user(), ['link_id' => 5]));

        $this->assertError(405, $res);
    }

    public function testLasVentasPidenUnProductoOUnaPagina()
    {
        $this->assertError(400, $this->verVentas([]), 'link_id o page_id');
    }

    /** Otra persona, sin acceso a la página, no ve nombres ni emails de nadie. */
    public function testSinAccesoAlProductoLasVentasDan403()
    {
        $res = $this->verVentas(['link_id' => 5]);

        $this->assertError(403, $res);
        $this->assertSame(0, $this->db->countCalls('FROM product_orders'));
    }

    public function testSinAccesoALaPaginaLasVentasDan403()
    {
        $res = $this->verVentas(['page_id' => 9]);

        $this->assertError(403, $res);
        $this->assertSame(0, $this->db->countCalls('FROM product_orders'));
    }

    public function testUnLinkQueNoEsProductoNoTieneVentasDeProducto()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'eventos']]);

        $this->assertError(400, $this->verVentas(['link_id' => 5]));
    }

    public function testLasVentasDeUnProductoTraenLasDescargasYLosTotales()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'productos']]);
        $this->db->onSelect('WHERE dp.link_id = ?', [
            $this->venta(['id' => 9, 'descargas' => '3', 'primera_descarga_en' => '2026-10-02 08:00:00']),
            $this->venta(['id' => 8, 'total' => '1000.00']),
            $this->venta(['id' => 7, 'estado' => 'cancelada', 'pagada_en' => null]),
        ]);

        $res = $this->verVentas(['link_id' => 5]);

        $this->assertStatus(200, $res);
        $this->assertSame([5], $this->db->paramsFor('WHERE dp.link_id = ?'));
        $this->assertSame(['pagadas' => 2, 'recaudado' => 3500.0, 'descargaron' => 1], $res->body['resumen']);
        $primera = $res->body['ordenes'][0];
        $this->assertSame(3, $primera['descargas']);
        $this->assertSame('2026-10-02 08:00:00', $primera['primera_descarga_en']);
        $this->assertSame(2500.0, $primera['total']);
        $this->assertFalse($primera['producto_borrado']);
    }

    /** Una pendiente cuyo plazo pasó se ve vencida, como en el resto. */
    public function testUnaPendienteViejaSeVeVencida()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'productos']]);
        $this->db->onSelect('WHERE dp.link_id = ?', [
            $this->venta(['estado' => 'pendiente', 'pagada_en' => null, 'creado_en' => '2020-01-01 00:00:00']),
        ]);

        $res = $this->verVentas(['link_id' => 5]);

        $this->assertSame('vencida', $res->body['ordenes'][0]['estado']);
        $this->assertSame(0, $res->body['resumen']['pagadas']);
    }

    /** Ni el token ni el link de descarga: lo de la lista lo ve cualquier administrador. */
    public function testLasVentasNuncaLlevanElTokenNiElLink()
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'productos']]);
        $this->db->onSelect('WHERE dp.link_id = ?', [$this->venta([
            'token_hash' => str_repeat('b', 64), 'token_cifrado' => 'cifrado-secreto', 'ip' => '198.51.100.7',
        ])]);

        $res = $this->verVentas(['link_id' => 5]);

        $json = json_encode($res->body);
        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString('cifrado-secreto', $json);
        $this->assertStringNotContainsString('/descarga', $json);
        $this->assertStringNotContainsString('198.51.100.7', $json);
        $sql = $this->db->callsFor('WHERE dp.link_id = ?')[0]['sql'];
        $this->assertStringNotContainsString('token', $sql);
        $this->assertStringNotContainsString('o.*', $sql);
    }

    /** Por página el permiso es sobre la página: el link de un producto borrado no existe. */
    public function testLasVentasDeLaPaginaIncluyenLosProductosBorrados()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [
            $this->venta(['link_id' => null, 'producto' => 'Pack viejo', 'producto_borrado_en' => '2026-09-01 00:00:00']),
        ]);

        $res = $this->verVentas(['page_id' => 9]);

        $this->assertStatus(200, $res);
        $this->assertSame([9], $this->db->paramsFor('WHERE dp.page_id = ?'));
        $this->assertSame(0, $this->db->countCalls('FROM links l JOIN link_groups lg'));
        $venta = $res->body['ordenes'][0];
        $this->assertSame('Pack viejo', $venta['producto']);
        $this->assertTrue($venta['producto_borrado']);
        $this->assertNull($venta['link_id']);
    }

    public function testLasVentasSeExportanAExcel()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [$this->venta(['descargas' => '2'])]);

        $res = $this->verVentas(['page_id' => 9, 'formato' => 'excel', 'nombre' => 'La Sala']);

        $this->assertStatus(200, $res);
        $this->assertStringContainsString('ventas-la-sala.xlsx', implode("\n", $res->headers));
        $hoja = $this->hojaDelExcel($res->raw);
        $this->assertStringContainsString('Descargó', $hoja);
        $this->assertStringContainsString('Sí · 2 veces', $hoja);
        $this->assertStringContainsString('ana@test.local', $hoja);
    }

    // ========================================================= físicos (#44)

    private function pedido(array $extra = [])
    {
        return $this->venta(array_merge([
            'id' => 8, 'codigo' => 'FFFF00001111', 'tipo' => 'fisico', 'producto' => 'Remera', 'total' => '5500.00',
            'telefono' => '11 5555-1234', 'opciones_texto' => 'Talle M', 'cantidad' => '2', 'sin_stock' => '0',
            'estado_pedido' => 'ingresado', 'entrega_fecha' => null, 'entrega_franja' => null,
            'calle' => 'Av. Corrientes 1234', 'piso_depto' => '3° B', 'localidad' => 'CABA',
            'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => '1043', 'indicaciones' => 'Timbre B',
            'dias' => 'lunes,sabado', 'franjas' => 'tarde', 'comentario_horario' => 'Después de las 18',
        ], $extra));
    }

    public function testLaListaDeUnFisicoTraeElPedidoYLaLocalidadNoLaDireccion()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [$this->pedido(['sin_stock' => '1']), $this->venta()]);

        $res = $this->verVentas(['page_id' => 9]);

        $this->assertStatus(200, $res);
        list($fisico, $digital) = $res->body['ordenes'];
        $this->assertSame('fisico', $fisico['tipo']);
        $this->assertSame('ingresado', $fisico['estado_pedido']);
        $this->assertSame('Talle M', $fisico['opciones']);
        $this->assertSame(2, $fisico['cantidad']);
        $this->assertSame('CABA', $fisico['localidad']);
        $this->assertTrue($fisico['sin_stock']);
        $this->assertArrayNotHasKey('calle', $fisico);
        $this->assertStringNotContainsString('Corrientes', json_encode($res->body));
        $this->assertSame('digital', $digital['tipo']);
        $this->assertArrayNotHasKey('estado_pedido', $digital);
        // Cada resumen con lo suyo.
        $this->assertSame(['pagadas' => 1, 'recaudado' => 2500.0, 'descargaron' => 0], $res->body['resumen']);
        $this->assertSame(5500.0, $res->body['resumen_fisicos']['recaudado']);
    }

    public function testElExcelDePedidosTraeLaDireccionCompletaYLosHorarios()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [
            $this->pedido(['estado_pedido' => 'con_fecha', 'entrega_fecha' => '2026-11-03', 'entrega_franja' => 'tarde']),
            // Sin pagar no está en gestión; un digital no se entrega.
            $this->pedido(['id' => 9, 'codigo' => 'SINPAGAR0000', 'estado' => 'pendiente', 'estado_pedido' => null, 'nombre' => 'Beto']),
            $this->venta(['nombre' => 'Carla']),
        ]);

        $res = $this->verVentas(['page_id' => 9, 'formato' => 'excel', 'tipo' => 'fisico', 'nombre' => 'La Sala']);

        $this->assertStatus(200, $res);
        $this->assertStringContainsString('pedidos-la-sala.xlsx', implode("\n", $res->headers));
        $hoja = $this->hojaDelExcel($res->raw);
        foreach (['Av. Corrientes 1234', '3° B', 'Timbre B', 'Lunes y Sábado', 'Después de las 18',
                  'Con fecha de entrega', '2026-11-03', 'Tarde', 'Calle y número'] as $texto) {
            $this->assertStringContainsString($texto, $hoja);
        }
        $this->assertStringNotContainsString('Beto', $hoja);
        $this->assertStringNotContainsString('Carla', $hoja);
    }

    public function testElExcelDePedidosRespetaElFiltroDeEstado()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [
            $this->pedido(['nombre' => 'Ana']),
            $this->pedido(['id' => 9, 'nombre' => 'Beto', 'estado_pedido' => 'entregado']),
        ]);

        $hoja = $this->hojaDelExcel($this->verVentas([
            'page_id' => 9, 'formato' => 'excel', 'tipo' => 'fisico', 'estado_pedido' => 'entregado',
        ])->raw);

        $this->assertStringContainsString('Beto', $hoja);
        $this->assertStringNotContainsString('Ana', $hoja);
    }

    /** #53: la forma de entrega en la lista, una columna en el Excel y su filtro. */
    public function testLaListaTraeLaFormaDeEntregaYUnPedidoViejoEsEnvio()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [
            $this->pedido(['forma_entrega' => 'coordinar']),
            $this->pedido(['id' => 9, 'forma_entrega' => null]),
        ]);

        $ordenes = $this->verVentas(['page_id' => 9])->body['ordenes'];

        $this->assertSame(['coordinar', 'envio'], array_column($ordenes, 'forma_entrega'));
    }

    public function testElExcelDePedidosTraeLaFormaYElPuntoDeRetiro()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [
            $this->pedido([
                'nombre' => 'Ana', 'forma_entrega' => 'retiro', 'estado_pedido' => 'enviado',
                'retiro_direccion' => 'Humboldt 1500, CABA', 'retiro_indicaciones' => 'Timbre 3',
                'calle' => null, 'piso_depto' => null, 'localidad' => null, 'provincia' => null, 'codigo_postal' => null,
                'indicaciones' => null, 'dias' => null, 'franjas' => null, 'comentario_horario' => null,
            ]),
            $this->pedido(['id' => 9, 'nombre' => 'Beto', 'forma_entrega' => 'envio']),
        ]);

        $hoja = $this->hojaDelExcel($this->verVentas(['page_id' => 9, 'formato' => 'excel', 'tipo' => 'fisico'])->raw);

        foreach (['Forma de entrega', 'Punto de retiro', 'Retiro', 'Envío a domicilio', 'Listo para retirar',
                  'Humboldt 1500, CABA — Timbre 3'] as $texto) {
            $this->assertStringContainsString($texto, $hoja);
        }
    }

    public function testElExcelDePedidosRespetaElFiltroDeForma()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [
            $this->pedido(['nombre' => 'Ana', 'forma_entrega' => 'envio']),
            $this->pedido(['id' => 9, 'nombre' => 'Beto', 'forma_entrega' => 'coordinar']),
        ]);

        $hoja = $this->hojaDelExcel($this->verVentas([
            'page_id' => 9, 'formato' => 'excel', 'tipo' => 'fisico', 'forma_entrega' => 'coordinar',
        ])->raw);

        $this->assertStringContainsString('Beto', $hoja);
        $this->assertStringNotContainsString('Ana', $hoja);
    }

    public function testUnFiltroDeFormaInventadoNoVale()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);

        $this->assertError(400, $this->verVentas(['page_id' => 9, 'formato' => 'excel', 'tipo' => 'fisico', 'forma_entrega' => 'dron']));
    }

    public function testUnFiltroDeEstadoInventadoNoVale()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);

        $this->assertError(400, $this->verVentas(['page_id' => 9, 'formato' => 'excel', 'tipo' => 'fisico', 'estado_pedido' => 'x']));
    }

    /** Sin permiso no hay Excel de pedidos: tiene direcciones. */
    public function testElExcelDePedidosExigeLaPagina()
    {
        $this->assertError(403, $this->verVentas(['page_id' => 9, 'formato' => 'excel', 'tipo' => 'fisico']));
        $this->assertFalse($this->db->ran('product_order_envios'));
    }

    public function testElExcelDeDigitalesDejaAfueraLosFisicos()
    {
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE dp.page_id = ?', [$this->pedido(['nombre' => 'Beto']), $this->venta(['nombre' => 'Carla'])]);

        $hoja = $this->hojaDelExcel($this->verVentas(['page_id' => 9, 'formato' => 'excel', 'tipo' => 'digital'])->raw);

        $this->assertStringContainsString('Carla', $hoja);
        $this->assertStringNotContainsString('Beto', $hoja);
    }

    private function hojaDelExcel($contenido)
    {
        $ruta = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($ruta, $contenido);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($ruta) === true, 'el archivo tiene que ser un zip válido');
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($ruta);

        $this->assertNotFalse($xml);

        return $xml;
    }

    // =========================================================== reenviar

    private function reenviar($codigo = 'ABCDEF123456', $user = 'default', $mailer = null)
    {
        return ProductosHandler::reenviar(
            $this->db,
            $this->post(['codigo' => $codigo], $user === 'default' ? $this->user() : $user),
            $mailer
        );
    }

    private function ordenDeLaBase(array $extra = [])
    {
        return array_merge([
            'id' => 7, 'codigo' => 'ABCDEF123456', 'estado' => 'pagada', 'nombre' => 'Ana',
            'email' => 'ana@test.local', 'producto' => 'Guía', 'pagina' => 'La Sala', 'pagina_id' => 9,
            'token_cifrado' => \Cripto::cifrar(str_repeat('c', 64)), 'mail_enviado_en' => '2026-01-01 00:00:00',
            'mail_intentos' => 1, 'archivo_nombre' => 'guia.pdf', 'producto_imagen' => null, 'email_contacto' => null,
        ], $extra);
    }

    public function testReenviarExigeSesionYPost()
    {
        $this->assertError(401, $this->reenviar('ABCDEF123456', null));
        $this->assertError(405, ProductosHandler::reenviar($this->db, $this->get(['codigo' => 'X'], $this->user())));
        $this->assertError(400, $this->reenviar(''));
    }

    public function testReenviarUnaCompraQueNoExisteDa404()
    {
        $this->assertError(404, $this->reenviar());
    }

    public function testSinAccesoALaPaginaNoSeReenvia()
    {
        $mailer = new \Tests\Support\FakeMailer();
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase()]);

        $res = $this->reenviar('ABCDEF123456', 'default', $mailer);

        $this->assertError(403, $res);
        $this->assertSame([], $mailer->enviados);
        $this->assertSame([9, 1, 1], $this->db->paramsFor('FROM pages p WHERE p.id = ?'));
    }

    public function testUnaCompraSinPagarNoSeReenvia()
    {
        $mailer = new \Tests\Support\FakeMailer();
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase(['estado' => 'pendiente'])]);
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);

        $this->assertError(409, $this->reenviar('ABCDEF123456', 'default', $mailer));
        $this->assertSame([], $mailer->enviados);
    }

    /** El mismo link de siempre, sacado de token_cifrado: no se genera otro. */
    public function testReenviaElMismoLinkDeLaCompra()
    {
        $mailer = new \Tests\Support\FakeMailer();
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase()]);
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase()]);

        $res = $this->reenviar('ABCDEF123456', 'default', $mailer);

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['enviado']);
        $this->assertCount(1, $mailer->enviados);
        $this->assertSame('ana@test.local', $mailer->enviados[0]['para']);
        $this->assertStringContainsString('/descarga#' . str_repeat('c', 64), $mailer->enviados[0]['html']);
        $this->assertStringNotContainsString(str_repeat('c', 64), json_encode($res->body));
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
        $this->assertFalse($this->db->ran('token_hash ='));
    }

    /** El mínimo entre reenvíos vale también para el botón del panel. */
    public function testRecienEnviadoNoSeReenviaDeNuevo()
    {
        $mailer = new \Tests\Support\FakeMailer();
        $recien = ['mail_enviado_en' => date('Y-m-d H:i:s')];
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase($recien)]);
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase($recien)]);

        $this->assertError(429, $this->reenviar('ABCDEF123456', 'default', $mailer));
        $this->assertSame([], $mailer->enviados);
    }

    /** Justo el caso del botón: el cron agotó los intentos y el mail nunca salió. */
    public function testReenviaAunqueElCronHayaAgotadoLosIntentos()
    {
        $mailer = new \Tests\Support\FakeMailer();
        $nuncaSalio = ['mail_enviado_en' => null, 'mail_intentos' => 3, 'mail_error' => 'SMTP caído'];
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase($nuncaSalio)]);
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase($nuncaSalio)]);

        $res = $this->reenviar('ABCDEF123456', 'default', $mailer);

        $this->assertStatus(200, $res);
        $this->assertCount(1, $mailer->enviados);
        $this->assertTrue($this->db->ran('mail_error = NULL'));
    }

    public function testSiFallaElCorreoSeAvisa()
    {
        $mailer = (new \Tests\Support\FakeMailer())->fallarCon('SMTP caído');
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase()]);
        $this->db->onSelect('FROM pages p WHERE p.id = ?', [['1' => 1]]);
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenDeLaBase()]);

        $this->assertError(502, $this->reenviar('ABCDEF123456', 'default', $mailer), 'SMTP caído');
    }

    // ========================================================== ayudantes

    private function subir(array $archivo, $user = 'default')
    {
        $req = new Request(
            'POST',
            [],
            ['link_id' => '5'],
            $user === 'default' ? $this->user() : $user,
            ['archivo' => $archivo]
        );

        return ProductosHandler::archivo($this->db, $req, $this->disco);
    }

    private function archivo(array $overrides = [])
    {
        return array_merge([
            'name' => 'guia.pdf',
            'type' => 'application/pdf',
            'size' => 1024,
            'tmp_name' => '/tmp/phpPRODUCTO',
            'error' => UPLOAD_ERR_OK,
        ], $overrides);
    }

    private function autorizarLink()
    {
        $this->db->onSelect('FROM links l JOIN link_groups lg', [['1' => 1]]);
    }

    /** Un link autorizado de un grupo de productos, con su fila actual. */
    private function esProducto(array $producto = [])
    {
        $this->autorizarLink();
        $this->db->onSelect('SELECT lg.type', [['type' => 'productos']]);
        $this->db->onSelect('SELECT * FROM digital_products WHERE link_id', [array_merge([
            'id' => 1, 'link_id' => 5, 'precio' => '0.00', 'archivo_ruta' => null,
        ], $producto)]);
    }
}
