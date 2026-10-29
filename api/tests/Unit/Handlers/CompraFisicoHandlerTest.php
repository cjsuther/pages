<?php

namespace Tests\Unit\Handlers;

use Comision;
use CompraProductoHandler;
use Cripto;
use OrdenesDeProductos;
use Request;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

/**
 * La compra de un producto físico (#43): cantidad, opciones, dirección y
 * horarios; el total con envío, la comisión sobre ese total, el pedido gratis,
 * el stock y lo que muestra la pantalla pública del pedido.
 *
 * El cobro es el de los digitales (CompraProductoHandlerTest): acá se mira
 * lo que cambia.
 */
class CompraFisicoHandlerTest extends HandlerTestCase
{
    const TOKEN_MP = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';
    const CODIGO = 'AAAABBBBCCCC';

    // ------------------------------------------------------------- fixtures

    /** Una remera con Talle (S, M) y Color (Negro, Blanco); M/Negro tiene stock. */
    private function hayRemera(array $overrides = [], $stockMNegro = 5)
    {
        $this->db->onSelect('lg.type = "productos"', [array_merge([
            'id' => 300, 'text' => 'Remera', 'image_url' => null, 'page_id' => 5,
            'producto_id' => 40, 'tipo' => 'fisico', 'oculto' => 0, 'borrado_en' => null,
            'precio' => '2500.00', 'costo_envio' => '500.00', 'zona_envio' => 'CABA y GBA', 'stock' => null,
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null,
        ], $overrides)]);
        $this->db->onSelect('FROM product_attributes a', [
            ['atributo_id' => 1, 'product_id' => 40, 'atributo' => 'Talle', 'opcion_id' => 11, 'opcion' => 'S'],
            ['atributo_id' => 1, 'product_id' => 40, 'atributo' => 'Talle', 'opcion_id' => 12, 'opcion' => 'M'],
            ['atributo_id' => 2, 'product_id' => 40, 'atributo' => 'Color', 'opcion_id' => 21, 'opcion' => 'Negro'],
            ['atributo_id' => 2, 'product_id' => 40, 'atributo' => 'Color', 'opcion_id' => 22, 'opcion' => 'Blanco'],
        ]);
        $this->db->onSelect('FROM product_variants WHERE product_id IN', [
            ['product_id' => 40, 'clave' => '12-21', 'stock' => $stockMNegro],
            ['product_id' => 40, 'clave' => '11-21', 'stock' => 0],
        ]);
        $this->db->onSelect('SELECT id FROM product_variants WHERE product_id = ? AND clave = ?', [['id' => 77]]);
        $this->db->onInsert('INSERT INTO product_orders', 7);
    }

    /** Un llavero sin características, con stock general. */
    private function hayLlavero(array $overrides = [])
    {
        $this->db->onSelect('lg.type = "productos"', [array_merge([
            'id' => 301, 'text' => 'Llavero', 'image_url' => null, 'page_id' => 5,
            'producto_id' => 41, 'tipo' => 'fisico', 'oculto' => 0, 'borrado_en' => null,
            'precio' => '1000.00', 'costo_envio' => '0.00', 'zona_envio' => null, 'stock' => 3,
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null,
        ], $overrides)]);
        $this->db->onInsert('INSERT INTO product_orders', 8);
    }

    private function hayCredencialesDeCobro($conectadoPor = 'oauth', $veces = 2)
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->db->onSelect('SELECT lg.page_id', [[5]]);
            $this->db->onSelect('FROM page_payment_settings WHERE page_id', [[
                'page_id' => 5,
                'mp_user_id' => '987654321',
                'access_token_cifrado' => Cripto::cifrar(self::TOKEN_MP),
                'refresh_token_cifrado' => null,
                'modo' => 'produccion',
                'conectado_por' => $conectadoPor,
                'token_expira_en' => date('Y-m-d H:i:s', time() + 86400 * 180),
                'verificado_en' => '2026-08-16 20:00:00',
            ]]);
        }
    }

    private function http()
    {
        return (new FakeHttpClient())->responde('/checkout/preferences', 201, [
            'id' => 'PREF-9',
            'init_point' => 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-9',
        ]);
    }

    private function pedido(array $overrides = [], $linkId = 300)
    {
        return new Request('POST', array_merge([
            'link_id'  => $linkId,
            'nombre'   => 'Ana Gómez',
            'email'    => 'ana@example.com',
            'telefono' => '11 5555-1234',
            'cantidad' => 2,
            'opciones' => [12, 21],
            'envio'    => [
                'calle' => 'Av. Corrientes 1234', 'piso_depto' => '3° B', 'localidad' => 'CABA',
                'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => '1043',
                'indicaciones' => 'Timbre B',
            ],
            'horarios' => ['dias' => ['lunes', 'sabado'], 'franjas' => ['tarde'], 'comentario' => 'Después de las 18'],
        ], $overrides));
    }

    // ---------------------------------------------------------------- cobro

    public function testElTotalEsPrecioPorCantidadMasEnvio()
    {
        $this->hayRemera();
        $this->hayCredencialesDeCobro();
        $http = $this->http();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $http);

        $this->assertStatus(201, $r);
        $this->assertSame(5500.0, $r->body['total']);
        $this->assertSame(5500.0, $this->db->paramsFor('INSERT INTO product_orders')[5]);

        // En Mercado Pago se ve el producto por la cantidad y el envío aparte.
        $items = $http->jsonDe('/checkout/preferences')['items'];
        $this->assertSame('Remera (Talle M · Color Negro)', $items[0]['title']);
        $this->assertSame(2, $items[0]['quantity']);
        $this->assertSame(2500.0, $items[0]['unit_price']);
        $this->assertSame(['Envío', 1, 500.0], [$items[1]['title'], $items[1]['quantity'], $items[1]['unit_price']]);
    }

    /** Mercado Pago hace el split sobre el total de la preferencia, envío incluido. */
    public function testLaComisionEsSobreElTotalConEnvio()
    {
        $this->hayRemera();
        $this->hayCredencialesDeCobro();
        $http = $this->http();

        CompraProductoHandler::comprar($this->db, $this->pedido(), $http);

        $this->assertSame(Comision::sobre(5500), $http->jsonDe('/checkout/preferences')['marketplace_fee']);
        $this->assertSame([Comision::sobre(5500), Comision::porcentaje(), 7], $this->db->paramsFor('UPDATE product_orders SET comision'));
    }

    public function testConEnvioIncluidoNoHayRenglonDeEnvio()
    {
        $this->hayLlavero();
        $this->hayCredencialesDeCobro();
        $http = $this->http();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 1, 'opciones' => null], 301), $http);

        $this->assertStatus(201, $r);
        $this->assertCount(1, $http->jsonDe('/checkout/preferences')['items']);
        $this->assertSame(1000.0, $r->body['total']);
    }

    /** Un físico no tiene nada que descargar: el token no sale. */
    public function testNoDevuelveElTokenDeDescarga()
    {
        $this->hayRemera();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $this->http());

        $this->assertArrayNotHasKey('token', $r->body);
        $this->assertStringContainsString('mercadopago', $r->body['url']);
    }

    // ------------------------------------------------------------------ foto

    public function testLaOrdenGuardaUnaFotoDelPedido()
    {
        $this->hayRemera();
        $this->hayCredencialesDeCobro();

        CompraProductoHandler::comprar($this->db, $this->pedido(), $this->http());

        $orden = $this->db->paramsFor('INSERT INTO product_orders');
        $this->assertSame(
            ['Remera', 77, 'Talle M · Color Negro', 2, 2500.0, 500.0],
            array_slice($orden, 11),
            'título, variante, opciones, cantidad, precio unitario y envío'
        );
        $this->assertSame('11 5555-1234', $orden[4]);

        $opciones = $this->db->callsFor('INSERT INTO product_order_options');
        $this->assertSame([[7, 12], [7, 21]], array_column($opciones, 'params'));

        $this->assertSame(
            [7, 'Av. Corrientes 1234', '3° B', 'CABA', 'Ciudad Autónoma de Buenos Aires', '1043', 'Timbre B',
             'lunes,sabado', 'tarde', 'Después de las 18'],
            $this->db->paramsFor('INSERT INTO product_order_envios')
        );
        $this->assertTrue($this->db->committed);
    }

    /** Con el pago todavía pendiente no se toca el stock: se descuenta al acreditar. */
    public function testCrearLaOrdenNoDescuentaStock()
    {
        $this->hayRemera();
        $this->hayCredencialesDeCobro();

        CompraProductoHandler::comprar($this->db, $this->pedido(), $this->http());

        $this->assertFalse($this->db->ran('SET stock = stock - ?'));
    }

    // ------------------------------------------------------------ validación

    public function testElTelefonoEsObligatorio()
    {
        $this->hayRemera();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['telefono' => '']), $this->http());

        $this->assertError(400, $r, 'teléfono');
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
    }

    /** @dataProvider cantidadesInvalidas */
    public function testLaCantidadVaDeUnoADiez($cantidad)
    {
        $this->hayRemera();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => $cantidad]), $this->http());

        $this->assertError(400, $r, 'de 1 a 10');
    }

    public function cantidadesInvalidas()
    {
        return [[0], [11], ['muchas'], [-1]];
    }

    public function testHayQueElegirLasOpciones()
    {
        $this->hayRemera();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['opciones' => [12]]), $this->http());

        $this->assertError(400, $r, 'Elegí color');
    }

    public function testFaltaLaDireccion()
    {
        $this->hayRemera();

        $pedido = $this->pedido();
        $pedido->body['envio']['codigo_postal'] = '';

        $this->assertError(400, CompraProductoHandler::comprar($this->db, $pedido, $this->http()), 'código postal');
    }

    public function testFaltanLosHorarios()
    {
        $this->hayRemera();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['horarios' => ['dias' => ['lunes'], 'franjas' => []]]), $this->http());

        $this->assertError(400, $r, 'franja');
    }

    // ----------------------------------------------------------------- stock

    public function testNoDejaComprarMasQueElStockDeLaCombinacion()
    {
        $this->hayRemera([], 1);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 2]), $this->http());

        $this->assertError(409, $r, 'No queda stock suficiente');
        $this->assertStringNotContainsString('1', $r->body['error'], 'No dice cuántas quedan');
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
    }

    public function testUnaCombinacionAgotada()
    {
        $this->hayRemera();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['opciones' => [11, 21], 'cantidad' => 1]), $this->http());

        $this->assertError(409, $r, 'agotada');
    }

    public function testElStockGeneralDeUnProductoSinCaracteristicas()
    {
        $this->hayLlavero();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 4, 'opciones' => null], 301), $this->http());

        $this->assertError(409, $r, 'No queda stock suficiente');
    }

    public function testUnProductoAgotado()
    {
        $this->hayLlavero(['stock' => 0]);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 1, 'opciones' => null], 301), $this->http());

        $this->assertError(409, $r, 'agotado');
    }

    public function testUnFisicoInactivoNoSeVende()
    {
        $this->hayLlavero(['oculto' => 1]);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 1, 'opciones' => null], 301), $this->http());

        $this->assertStatus(409, $r);
    }

    // ---------------------------------------------------------------- gratis

    public function testUnPedidoGratisNoPasaPorMercadoPagoYDescuentaElStock()
    {
        $this->hayLlavero(['precio' => '0.00']);
        $this->db->onSelect('SELECT stock FROM digital_products WHERE id = ? FOR UPDATE', [['stock' => 3]]);
        $http = $this->http();
        $mailer = new FakeMailer();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 2, 'opciones' => null], 301), $http, $mailer);

        $this->assertStatus(201, $r);
        $this->assertSame('pagada', $r->body['estado']);
        $this->assertNull($r->body['url']);
        $this->assertArrayNotHasKey('token', $r->body);
        $this->assertFalse($http->llamoA('/checkout/preferences'));

        $orden = $this->db->paramsFor('INSERT INTO product_orders');
        $this->assertSame(0, $orden[5]);
        $this->assertSame('pagada', $orden[6]);
        // Nace pagado: el stock se descuenta en la misma transacción.
        $this->assertSame([2, 41], $this->db->paramsFor('UPDATE digital_products SET stock = stock - ?'));
        $this->assertTrue($this->db->committed);
        // #44: confirmado, queda Ingresado en la misma transacción.
        $this->assertTrue($this->db->ran("SET estado_pedido = 'ingresado'"));
        $sqls = array_column($this->db->log(), 'sql');
        $this->assertLessThan($this->indiceDe($sqls, "SET estado_pedido = 'ingresado'"), $this->indiceDe($sqls, 'INSERT INTO product_orders'));
    }

    public function testUnPedidoGratisRespetaElLimitePorEmail()
    {
        $this->hayLlavero(['precio' => '0.00']);
        $this->db->onSelect('WHERE email = ? AND total = 0', [[OrdenesDeProductos::GRATIS_POR_HORA_POR_EMAIL]]);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 1, 'opciones' => null], 301), $this->http());

        $this->assertError(429, $r);
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
    }

    public function testUnPedidoGratisRespetaElLimitePorIp()
    {
        $this->hayLlavero(['precio' => '0.00']);
        $this->db->onSelect('WHERE email = ? AND total = 0', [[0]]);
        $this->db->onSelect('WHERE ip = ? AND total = 0', [[OrdenesDeProductos::GRATIS_POR_HORA_POR_IP]]);
        $pedido = $this->pedido(['cantidad' => 1, 'opciones' => null], 301);
        $pedido->ip = '203.0.113.9';

        $this->assertError(429, CompraProductoHandler::comprar($this->db, $pedido, $this->http()));
    }

    /** Si sólo el producto es gratis, el envío se cobra. */
    public function testProductoGratisConEnvioPagoSeCobraElEnvio()
    {
        $this->hayLlavero(['precio' => '0.00', 'costo_envio' => '800.00']);
        $this->hayCredencialesDeCobro();
        $http = $this->http();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['cantidad' => 2, 'opciones' => null], 301), $http);

        $this->assertSame(800.0, $r->body['total']);
        $this->assertSame([['title' => 'Envío', 'quantity' => 1, 'unit_price' => 800.0, 'currency_id' => 'ARS']],
            $http->jsonDe('/checkout/preferences')['items']);
    }

    // ------------------------------------------------------------ acreditar

    private function ordenFisica(array $overrides = [])
    {
        return array_merge([
            'id' => 7, 'codigo' => self::CODIGO, 'product_id' => 40, 'link_id' => 300, 'pagina_id' => 5,
            'tipo' => 'fisico', 'nombre' => 'Ana Gómez', 'email' => 'ana@example.com', 'telefono' => '11 5555-1234',
            'total' => '5500.00', 'estado' => 'pendiente', 'creado_en' => date('Y-m-d H:i:s'),
            'token_cifrado' => Cripto::cifrar(str_repeat('ab', 32)),
            'mail_enviado_en' => null, 'mail_intentos' => 0, 'aviso_vendedor_en' => null,
            'producto' => 'Remera', 'producto_imagen' => null, 'opciones_texto' => 'Talle M · Color Negro',
            'cantidad' => 2, 'precio_unitario' => '2500.00', 'costo_envio' => '500.00', 'sin_stock' => 0,
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null,
            'pagina' => 'La Sala', 'url_slug' => 'la-sala', 'email_contacto' => null, 'meta_pixel_id' => null,
            'duena_email' => 'duena@example.com', 'mp_payment_id' => null,
            'estado_pedido' => null, 'entrega_fecha' => null, 'entrega_franja' => null,
            'envio_empresa' => null, 'envio_seguimiento' => null, 'cancelado_motivo' => null,
        ], $overrides);
    }

    private function hayPagoAprobadoDelPedido($stock)
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenFisica()]);
        $this->db->onSelect('FROM page_payment_settings WHERE page_id', [[
            'page_id' => 5, 'mp_user_id' => '987654321', 'access_token_cifrado' => Cripto::cifrar(self::TOKEN_MP),
            'refresh_token_cifrado' => null, 'modo' => 'produccion', 'conectado_por' => 'oauth',
            'token_expira_en' => date('Y-m-d H:i:s', time() + 86400 * 180), 'verificado_en' => '2026-08-16 20:00:00',
        ]]);
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['estado' => 'pendiente']]);
        $this->db->onWrite("SET estado = 'pagada'", 1);
        $this->db->onSelect("WHERE dp.tipo = 'fisico' AND o.codigo = ?", [['id' => 7, 'product_id' => 40, 'cantidad' => 2]]);
        $this->db->onSelect('SELECT option_id FROM product_order_options', [['option_id' => 12], ['option_id' => 21]]);
        $this->db->onSelect('FROM product_variants WHERE product_id = ? AND clave = ? FOR UPDATE', [['stock' => $stock]]);
        $this->db->onWrite("SET estado_pedido = 'ingresado'", 1);

        return (new FakeHttpClient())->responde('/v1/payments/', 200, [
            'status' => 'approved', 'external_reference' => self::CODIGO, 'transaction_amount' => 5500.0,
        ]);
    }

    public function testAlAcreditarSeDescuentaElStockEnLaMismaTransaccion()
    {
        $http = $this->hayPagoAprobadoDelPedido(5);
        $aviso = new Request('POST', ['data' => ['id' => '555']], ['orden' => self::CODIGO]);

        $r = CompraProductoHandler::aviso($this->db, $aviso, $http, new FakeMailer());

        $this->assertSame('pago acreditado', $r->body['motivo']);
        $this->assertSame([2, 40, '12-21'], $this->db->paramsFor('UPDATE product_variants SET stock = stock - ?'));
        $this->assertFalse($this->db->ran('SET sin_stock = 1'));
        $this->assertTrue($this->db->committed);
        $this->assertSame(0, $this->db->transactionDepth);

        // Primero se marca pagada, después se toma la fila de stock.
        $sqls = array_column($this->db->log(), 'sql');
        $pagada = $this->indiceDe($sqls, "SET estado = 'pagada'");
        $this->assertLessThan($this->indiceDe($sqls, 'FOR UPDATE'), $pagada);
    }

    /** #44: pagado, el pedido queda Ingresado y lo anota el sistema, en la misma transacción. */
    public function testAlAcreditarElPedidoQuedaIngresadoConSuHistorial()
    {
        $http = $this->hayPagoAprobadoDelPedido(5);
        $aviso = new Request('POST', ['data' => ['id' => '555']], ['orden' => self::CODIGO]);

        CompraProductoHandler::aviso($this->db, $aviso, $http, new FakeMailer());

        $this->assertSame([7], $this->db->paramsFor("SET estado_pedido = 'ingresado' WHERE id = ? AND estado_pedido IS NULL"));
        $this->assertSame([7, 'ingresado', null, null], $this->db->paramsFor('INSERT INTO product_order_historial'));
        $sqls = array_column($this->db->log(), 'sql');
        $this->assertLessThan($this->indiceDe($sqls, 'INSERT INTO product_order_historial'), $this->indiceDe($sqls, "SET estado = 'pagada'"));
        $this->assertTrue($this->db->committed);
    }

    /** Sin stock también entra en la gestión: lo resuelve quien vende. */
    public function testSinStockTambienQuedaIngresado()
    {
        $http = $this->hayPagoAprobadoDelPedido(1);

        CompraProductoHandler::aviso($this->db, new Request('POST', ['data' => ['id' => '556']], ['orden' => self::CODIGO]), $http, new FakeMailer());

        $this->assertTrue($this->db->ran("SET estado_pedido = 'ingresado'"));
        $this->assertTrue($this->db->ran('INSERT INTO product_order_historial'));
    }

    /** Dos compraron la última unidad: el segundo pago se acredita igual, marcado sin stock. */
    public function testSiYaNoHayStockLaOrdenQuedaPagadaYSinStock()
    {
        $http = $this->hayPagoAprobadoDelPedido(1);
        $aviso = new Request('POST', ['data' => ['id' => '556']], ['orden' => self::CODIGO]);

        $r = CompraProductoHandler::aviso($this->db, $aviso, $http, new FakeMailer());

        $this->assertSame('pago acreditado', $r->body['motivo']);
        $this->assertSame([7], $this->db->paramsFor('UPDATE product_orders SET sin_stock = 1'));
        $this->assertFalse($this->db->ran('SET stock = stock - ?'));
        // Nada se cancela ni se devuelve solo.
        $this->assertFalse($this->db->ran("SET estado = 'cancelada'"));
        $this->assertFalse($http->llamoA('/refunds'));
        $this->assertTrue($this->db->committed);
    }

    public function testUnPagoRepetidoNoDescuentaDeNuevo()
    {
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['estado' => 'pagada']]);

        $r = OrdenesDeProductos::acreditarPago($this->db, self::CODIGO, '555', 'approved');

        $this->assertSame('ya estaba pagada', $r['motivo']);
        $this->assertFalse($this->db->ran('FOR UPDATE'));
    }

    public function testUnRechazoNoTocaElStock()
    {
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['estado' => 'pendiente']]);

        OrdenesDeProductos::acreditarPago($this->db, self::CODIGO, '555', 'rejected');

        $this->assertFalse($this->db->ran('FOR UPDATE'));
    }

    private function indiceDe(array $sqls, $fragmento)
    {
        foreach ($sqls as $i => $sql) {
            if (strpos(preg_replace('/\s+/', ' ', $sql), $fragmento) !== false) {
                return $i;
            }
        }

        $this->fail('No se ejecutó: ' . $fragmento);
    }

    // --------------------------------------------------------- orden pública

    private function hayPedidoPagado(array $orden = ['estado_pedido' => 'ingresado'])
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenFisica(['estado' => 'pagada'] + $orden)]);
        $this->db->onSelect('FROM product_order_envios WHERE order_id = ?', [[
            'order_id' => 7, 'calle' => 'Av. Corrientes 1234', 'piso_depto' => '3° B', 'localidad' => 'CABA',
            'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => '1043', 'indicaciones' => 'Timbre B',
            'dias' => 'lunes,sabado', 'franjas' => 'tarde', 'comentario_horario' => 'Después de las 18',
        ]]);
    }

    public function testLaOrdenPublicaMuestraElDetalleLaDireccionYLosHorarios()
    {
        $this->hayPedidoPagado();

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));
        $orden = $r->body['orden'];

        $this->assertStatus(200, $r);
        $this->assertSame('fisico', $orden['tipo']);
        $this->assertSame('ingresado', $orden['estado_pedido']);
        $this->assertSame('Talle M · Color Negro', $orden['opciones']);
        $this->assertSame(2, $orden['cantidad']);
        $this->assertSame(2500.0, $orden['precio_unitario']);
        $this->assertSame(500.0, $orden['costo_envio']);
        $this->assertSame(5500.0, $orden['total']);
        $this->assertSame(['lunes', 'sabado'], $orden['horarios']['dias']);
        $this->assertSame('Después de las 18', $orden['horarios']['comentario']);
        $this->assertSame('CABA', $orden['envio']['localidad']);
    }

    /** #44: el estado actual y los pasos, con la fecha de cada uno que ya pasó. */
    public function testLaOrdenPublicaMuestraLaLineaDeTiempoDelPedido()
    {
        $this->hayPedidoPagado([
            'estado_pedido' => 'enviado', 'entrega_fecha' => '2026-11-03', 'entrega_franja' => 'tarde',
            'envio_empresa' => 'Correo Argentino', 'envio_seguimiento' => 'CA123456789AR',
        ]);
        $this->db->onSelect('FROM product_order_historial h', [
            ['id' => 1, 'estado' => 'ingresado', 'datos' => null, 'creado_en' => '2026-10-30 10:00:00', 'user_id' => null, 'quien' => null],
            ['id' => 2, 'estado' => 'con_fecha', 'datos' => '{"fecha":"2026-11-02","franja":null}',
             'creado_en' => '2026-10-30 11:00:00', 'user_id' => 1, 'quien' => 'Vendedora'],
            ['id' => 3, 'estado' => 'con_fecha', 'datos' => '{"fecha":"2026-11-03","franja":"tarde"}',
             'creado_en' => '2026-10-31 09:00:00', 'user_id' => 1, 'quien' => 'Vendedora'],
            ['id' => 4, 'estado' => 'enviado', 'datos' => '{"empresa":"Correo Argentino","seguimiento":"CA123456789AR"}',
             'creado_en' => '2026-11-02 08:00:00', 'user_id' => 1, 'quien' => 'Vendedora'],
        ]);

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));
        $orden = $r->body['orden'];

        $this->assertSame('enviado', $orden['estado_pedido']);
        $this->assertSame([
            ['estado' => 'ingresado', 'etiqueta' => 'Ingresado', 'fecha' => '2026-10-30 10:00:00'],
            ['estado' => 'con_fecha', 'etiqueta' => 'Con fecha de entrega', 'fecha' => '2026-10-31 09:00:00'],
            ['estado' => 'enviado', 'etiqueta' => 'Enviado', 'fecha' => '2026-11-02 08:00:00'],
            ['estado' => 'entregado', 'etiqueta' => 'Entregado', 'fecha' => null],
        ], $orden['linea_de_tiempo']);
        $this->assertSame('2026-11-03', $orden['entrega_fecha']);
        $this->assertSame('tarde', $orden['entrega_franja']);
        $this->assertSame('Correo Argentino', $orden['envio_empresa']);
        // Ni el seguimiento ni quién lo hizo: el código no es una credencial.
        $json = json_encode($r->body, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('CA123456789AR', $json);
        $this->assertStringNotContainsString('Vendedora', $json);
    }

    public function testUnPedidoCanceladoCortaLaLineaDeTiempoYNoMuestraElMotivo()
    {
        $this->hayPedidoPagado(['estado_pedido' => 'cancelado', 'cancelado_motivo' => 'Me quedé sin la tela']);
        $this->db->onSelect('FROM product_order_historial h', [
            ['id' => 1, 'estado' => 'ingresado', 'datos' => null, 'creado_en' => '2026-10-30 10:00:00', 'user_id' => null, 'quien' => null],
            ['id' => 2, 'estado' => 'cancelado', 'datos' => '{"motivo":"Me quedé sin la tela"}',
             'creado_en' => '2026-10-31 10:00:00', 'user_id' => 1, 'quien' => 'Vendedora'],
        ]);

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));

        $this->assertSame(['ingresado', 'cancelado'], array_column($r->body['orden']['linea_de_tiempo'], 'estado'));
        $this->assertStringNotContainsString('tela', json_encode($r->body, JSON_UNESCAPED_UNICODE));
    }

    /** Sin pagar no hay estado de pedido, aunque la columna diga otra cosa. */
    public function testUnPedidoSinPagarNoTieneEstadoNiLineaDeTiempo()
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenFisica([
            'estado' => 'cancelada', 'estado_pedido' => null,
        ])]);

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));

        $this->assertNull($r->body['orden']['estado_pedido']);
        $this->assertSame([], $r->body['orden']['linea_de_tiempo']);
        $this->assertFalse($this->db->ran('product_order_historial'));
    }

    /** El código no es una credencial: nada de contacto, y la calle a medias. */
    public function testLaOrdenPublicaNoTraeTelefonoNiEmailYRecortaLaCalle()
    {
        $this->hayPedidoPagado();

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));
        $json = json_encode($r->body, JSON_UNESCAPED_UNICODE);

        $this->assertSame('Av. Corrientes 1…', $r->body['orden']['envio']['calle']);
        $this->assertStringNotContainsString('1234', $json);
        $this->assertStringNotContainsString('5555', $json);
        $this->assertStringNotContainsString('ana@example.com', $json);
        $this->assertStringNotContainsString('duena@example.com', $json);
        $this->assertStringNotContainsString('Ana Gómez', $json);
        $this->assertStringNotContainsString('Timbre B', $json);
        $this->assertStringNotContainsString('3° B', $json);
        $this->assertArrayNotHasKey('token', $r->body['orden']);
    }

    public function testElTokenDeUnPedidoNoSirveParaDescargar()
    {
        $this->db->onSelect('WHERE o.token_hash = ?', [[
            'id' => 7, 'codigo' => self::CODIGO, 'estado' => 'pagada', 'descargas' => 0, 'tipo' => 'fisico',
            'link_id' => 300, 'producto' => 'Remera', 'producto_imagen' => null,
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null,
            'pagina_id' => 5, 'pagina' => 'La Sala', 'url_slug' => 'la-sala',
        ]]);

        $r = CompraProductoHandler::descarga($this->db, new Request('POST', ['token' => str_repeat('ab', 32)]));

        $this->assertStatus(404, $r);
    }
}
