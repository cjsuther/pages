<?php

namespace Tests\Unit\Handlers;

use Comision;
use CompraProductoHandler;
use Cripto;
use Request;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

/**
 * Las formas de entrega de un físico en la compra (#53): envío a domicilio,
 * retiro en un lugar y coordinar después de la compra. Cambia el total, lo
 * que se pide y la foto del pedido; el cobro es el mismo (CompraFisicoHandlerTest).
 */
class CompraFormasDeEntregaTest extends HandlerTestCase
{
    const TOKEN_MP = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';
    const CODIGO = 'AAAABBBBCCCC';

    const DIRECCION = 'Av. Corrientes 1234, C1043 CABA, Argentina';
    const MAPS = 'https://maps.google.com/?cid=123';
    const INDICACIONES = 'Lunes a viernes de 10 a 18, timbre 3';

    /** Un disco sin características, con las tres formas habilitadas salvo que se diga otra cosa. */
    private function hayDisco(array $overrides = [])
    {
        $this->db->onSelect('lg.type = "productos"', [array_merge([
            'id' => 302, 'text' => 'Disco', 'image_url' => null, 'page_id' => 5,
            'producto_id' => 42, 'tipo' => 'fisico', 'oculto' => 0, 'borrado_en' => null,
            'precio' => '3000.00', 'costo_envio' => '700.00', 'zona_envio' => null, 'stock' => null,
            'entrega_envio' => 1, 'entrega_retiro' => 1, 'entrega_coordinar' => 1,
            'retiro_direccion' => self::DIRECCION, 'retiro_maps_url' => self::MAPS,
            'retiro_indicaciones' => self::INDICACIONES,
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null,
        ], $overrides)]);
        $this->db->onInsert('INSERT INTO product_orders', 9);
    }

    private function hayCredencialesDeCobro()
    {
        for ($i = 0; $i < 2; $i++) {
            $this->db->onSelect('SELECT lg.page_id', [[5]]);
            $this->db->onSelect('FROM page_payment_settings WHERE page_id', [[
                'page_id' => 5, 'mp_user_id' => '987654321', 'access_token_cifrado' => Cripto::cifrar(self::TOKEN_MP),
                'refresh_token_cifrado' => null, 'modo' => 'produccion', 'conectado_por' => 'oauth',
                'token_expira_en' => date('Y-m-d H:i:s', time() + 86400 * 180), 'verificado_en' => '2026-08-16 20:00:00',
            ]]);
        }
    }

    private function http()
    {
        return (new FakeHttpClient())->responde('/checkout/preferences', 201, [
            'id' => 'PREF-9', 'init_point' => 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-9',
        ]);
    }

    private function pedido(array $overrides = [])
    {
        return new Request('POST', array_merge([
            'link_id'  => 302,
            'nombre'   => 'Ana Gómez',
            'email'    => 'ana@example.com',
            'telefono' => '11 5555-1234',
            'cantidad' => 2,
        ], $overrides));
    }

    private function conEnvio(array $overrides = [])
    {
        return $this->pedido(array_merge([
            'forma_entrega' => 'envio',
            'envio'    => [
                'calle' => 'Av. Siempreviva 742', 'localidad' => 'Springfield', 'provincia' => 'Buenos Aires',
                'codigo_postal' => '1414',
            ],
            'horarios' => ['dias' => ['lunes'], 'franjas' => ['tarde']],
        ], $overrides));
    }

    // ------------------------------------------------------------ la forma

    public function testUnaFormaNoHabilitadaEs400()
    {
        $this->hayDisco(['entrega_retiro' => 0]);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => 'retiro']), $this->http());

        $this->assertError(400, $r, 'no está disponible');
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
    }

    public function testUnaFormaDesconocidaEs400()
    {
        $this->hayDisco();

        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => 'dron']), $this->http()));
        $this->hayDisco();
        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => ['envio']]), $this->http()));
    }

    public function testConVariasFormasHayQueElegirUna()
    {
        $this->hayDisco();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $this->http());

        $this->assertError(400, $r, 'Elegí cómo querés recibir');
    }

    /** Con una sola forma el formulario no pregunta: se usa ésa. */
    public function testConUnaSolaFormaSeUsaEsaSinPreguntar()
    {
        $this->hayDisco(['entrega_envio' => 0, 'entrega_retiro' => 0]);
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $this->http());

        $this->assertStatus(201, $r);
        $this->assertSame('coordinar', $this->db->paramsFor('INSERT INTO product_orders')[17]);
    }

    /** Un físico de antes de #53, sin las columnas: sólo envío, como funcionaba. */
    public function testUnProductoSinLasColumnasEsSoloEnvio()
    {
        $this->hayDisco(['entrega_envio' => null, 'entrega_retiro' => null, 'entrega_coordinar' => null]);
        $this->hayCredencialesDeCobro();

        $sinForma = $this->conEnvio();
        unset($sinForma->body['forma_entrega']);

        $this->assertStatus(201, CompraProductoHandler::comprar($this->db, $sinForma, $this->http()));
        $this->assertSame('envio', $this->db->paramsFor('INSERT INTO product_orders')[17]);
    }

    /** Un retiro habilitado sin dirección no se puede ofrecer. */
    public function testUnRetiroSinDireccionNoSeAcepta()
    {
        $this->hayDisco(['retiro_direccion' => null]);

        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => 'retiro']), $this->http()));
    }

    /** @dataProvider formas */
    public function testElTelefonoSigueSiendoObligatorio($forma)
    {
        $this->hayDisco();

        $r = CompraProductoHandler::comprar($this->db, $this->conEnvio(['forma_entrega' => $forma, 'telefono' => '']), $this->http());

        $this->assertError(400, $r, 'teléfono');
    }

    public function formas()
    {
        return [['envio'], ['retiro'], ['coordinar']];
    }

    public function testConEnvioSeExigenLaDireccionYLosHorarios()
    {
        $this->hayDisco();

        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->conEnvio(['envio' => null]), $this->http()), 'dirección');
        $this->hayDisco();
        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->conEnvio(['horarios' => null]), $this->http()), 'día');
    }

    /** @dataProvider sinEnvio */
    public function testSinEnvioNoSePideDireccionNiHorarios($forma)
    {
        $this->hayDisco();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => $forma]), $this->http());

        $this->assertStatus(201, $r);
        $this->assertFalse($this->db->ran('INSERT INTO product_order_envios'));
    }

    public function sinEnvio()
    {
        return [['retiro'], ['coordinar']];
    }

    /** Si al retirar llegan una dirección y horarios, se ignoran: no se guardan. */
    public function testConRetiroLaDireccionQueLlegaSeIgnora()
    {
        $this->hayDisco();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::comprar($this->db, $this->conEnvio(['forma_entrega' => 'retiro']), $this->http());

        $this->assertStatus(201, $r);
        $this->assertFalse($this->db->ran('INSERT INTO product_order_envios'));
        $this->assertStringNotContainsString('Siempreviva', json_encode($this->db->log(), JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------ el total

    public function testConEnvioElTotalSumaElEnvioYLaComisionVaSobreEseTotal()
    {
        $this->hayDisco();
        $this->hayCredencialesDeCobro();
        $http = $this->http();

        $r = CompraProductoHandler::comprar($this->db, $this->conEnvio(), $http);

        $this->assertSame(6700.0, $r->body['total']);
        $preferencia = $http->jsonDe('/checkout/preferences');
        $this->assertSame(Comision::sobre(6700), $preferencia['marketplace_fee']);
        $this->assertSame(['Disco', 'Envío'], array_column($preferencia['items'], 'title'));
        $this->assertSame(700.0, $this->db->paramsFor('INSERT INTO product_orders')[16]);
    }

    /** @dataProvider sinEnvio */
    public function testSinEnvioNoSeCobraElEnvioNiVaSuRenglon($forma)
    {
        $this->hayDisco();
        $this->hayCredencialesDeCobro();
        $http = $this->http();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => $forma]), $http);

        $this->assertSame(6000.0, $r->body['total']);
        $preferencia = $http->jsonDe('/checkout/preferences');
        $this->assertSame(Comision::sobre(6000), $preferencia['marketplace_fee']);
        $this->assertSame(['Disco'], array_column($preferencia['items'], 'title'));
        $orden = $this->db->paramsFor('INSERT INTO product_orders');
        $this->assertSame(6000.0, $orden[5]);
        $this->assertSame(0.0, $orden[16], 'costo_envio en 0');
        $this->assertSame([Comision::sobre(6000), Comision::porcentaje(), 9], $this->db->paramsFor('UPDATE product_orders SET comision'));
    }

    /** Producto gratis y retiro: total 0, no pasa por Mercado Pago, como hoy. */
    public function testUnPedidoGratisConRetiroNoPasaPorMercadoPago()
    {
        $this->hayDisco(['precio' => '0.00']);
        $http = $this->http();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => 'retiro']), $http, new FakeMailer());

        $this->assertStatus(201, $r);
        $this->assertSame('pagada', $r->body['estado']);
        $this->assertNull($r->body['url']);
        $this->assertFalse($http->llamoA('/checkout/preferences'));
        $orden = $this->db->paramsFor('INSERT INTO product_orders');
        $this->assertSame(0, $orden[5]);
        $this->assertSame('retiro', $orden[17]);
        $this->assertTrue($this->db->ran("SET estado_pedido = 'ingresado'"));
    }

    /** Gratis con envío pago: el envío se sigue cobrando. */
    public function testProductoGratisConEnvioSeCobraElEnvio()
    {
        $this->hayDisco(['precio' => '0.00']);
        $this->hayCredencialesDeCobro();
        $http = $this->http();

        $r = CompraProductoHandler::comprar($this->db, $this->conEnvio(), $http);

        $this->assertSame(700.0, $r->body['total']);
        $this->assertSame(['Envío'], array_column($http->jsonDe('/checkout/preferences')['items'], 'title'));
    }

    // ------------------------------------------------------------ la foto

    /** La orden guarda el punto de retiro como estaba al comprar. */
    public function testLaOrdenGuardaLaFotoDelRetiro()
    {
        $this->hayDisco();
        $this->hayCredencialesDeCobro();

        CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => 'retiro']), $this->http());

        $this->assertSame(
            ['retiro', self::DIRECCION, self::MAPS, self::INDICACIONES],
            array_slice($this->db->paramsFor('INSERT INTO product_orders'), 17)
        );
    }

    public function testCoordinarNoGuardaPuntoDeRetiro()
    {
        $this->hayDisco();
        $this->hayCredencialesDeCobro();

        CompraProductoHandler::comprar($this->db, $this->pedido(['forma_entrega' => 'coordinar']), $this->http());

        $this->assertSame(['coordinar', null, null, null], array_slice($this->db->paramsFor('INSERT INTO product_orders'), 17));
    }

    // ------------------------------------------------------ la orden pública

    private function ordenPagada(array $overrides = [])
    {
        return array_merge([
            'id' => 9, 'codigo' => self::CODIGO, 'product_id' => 42, 'link_id' => 302, 'pagina_id' => 5,
            'tipo' => 'fisico', 'nombre' => 'Ana Gómez', 'email' => 'ana@example.com', 'telefono' => '11 5555-1234',
            'total' => '6000.00', 'estado' => 'pagada', 'creado_en' => date('Y-m-d H:i:s'),
            'producto' => 'Disco', 'producto_imagen' => null, 'opciones_texto' => null,
            'cantidad' => 2, 'precio_unitario' => '3000.00', 'costo_envio' => '0.00', 'sin_stock' => 0,
            'archivo_ruta' => null, 'archivo_nombre' => null, 'archivo_bytes' => null,
            'pagina' => 'La Sala', 'url_slug' => 'la-sala', 'meta_pixel_id' => null,
            'estado_pedido' => 'ingresado', 'entrega_fecha' => null, 'entrega_franja' => null, 'envio_empresa' => null,
            'forma_entrega' => 'retiro', 'retiro_direccion' => self::DIRECCION, 'retiro_maps_url' => self::MAPS,
            'retiro_indicaciones' => self::INDICACIONES,
        ], $overrides);
    }

    /** El punto de retiro es de quien vende: sale entero, sin recortar. */
    public function testLaOrdenPublicaNoRecortaLaDireccionDelRetiro()
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenPagada()]);

        $orden = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]))->body['orden'];

        $this->assertSame('retiro', $orden['forma_entrega']);
        $this->assertSame(
            ['direccion' => self::DIRECCION, 'maps_url' => self::MAPS, 'indicaciones' => self::INDICACIONES],
            $orden['retiro']
        );
        $this->assertNull($orden['envio']);
        $this->assertSame(
            ['Ingresado', 'Con fecha de retiro', 'Listo para retirar', 'Retirado'],
            array_column($orden['linea_de_tiempo'], 'etiqueta')
        );
    }

    /** La dirección de quien compra sí se recorta, como hoy. */
    public function testLaOrdenPublicaRecortaLaDireccionDelComprador()
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenPagada([
            'forma_entrega' => 'envio', 'retiro_direccion' => null, 'retiro_maps_url' => null, 'retiro_indicaciones' => null,
        ])]);
        $this->db->onSelect('FROM product_order_envios WHERE order_id = ?', [[
            'order_id' => 9, 'calle' => 'Av. Siempreviva 742', 'piso_depto' => null, 'localidad' => 'Springfield',
            'provincia' => 'Buenos Aires', 'codigo_postal' => '1414', 'indicaciones' => null,
            'dias' => 'lunes', 'franjas' => 'tarde', 'comentario_horario' => null,
        ]]);

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));

        $this->assertSame('envio', $r->body['orden']['forma_entrega']);
        $this->assertNull($r->body['orden']['retiro']);
        $this->assertSame('Av. Siempreviva 7…', $r->body['orden']['envio']['calle']);
        $this->assertStringNotContainsString('742', json_encode($r->body, JSON_UNESCAPED_UNICODE));
    }

    public function testUnPedidoViejoSinFormaEsEnvio()
    {
        $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenPagada([
            'forma_entrega' => null, 'retiro_direccion' => null, 'retiro_maps_url' => null, 'retiro_indicaciones' => null,
        ])]);

        $orden = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]))->body['orden'];

        $this->assertSame('envio', $orden['forma_entrega']);
        $this->assertSame('Con fecha de entrega', $orden['linea_de_tiempo'][1]['etiqueta']);
    }
}
