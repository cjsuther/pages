<?php

namespace Tests\Unit\Handlers;

use CompraProductoHandler;
use Cripto;
use OrdenesDeProductos;
use ProductosDigitales;
use Request;
use Tests\Support\FakeDisco;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

/**
 * Compra, pago y descarga de productos digitales (#18).
 *
 * Mercado Pago, el correo y el disco son dobles. Lo que se mira es que el
 * cobro siga las reglas de las entradas y que el link de descarga sólo
 * funcione con su token.
 */
class CompraProductoHandlerTest extends HandlerTestCase
{
    const TOKEN_MP = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';
    const CODIGO = 'AAAABBBBCCCC';
    const RUTA = 'productos/0123456789abcdef0123456789abcdef';

    /** @var string Token de descarga de la orden de los fixtures */
    private $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('ab', 32);
    }

    // ------------------------------------------------------------- fixtures

    private function hayProducto($precio = '2500.00', $oculto = 0)
    {
        $this->db->onSelect('lg.type = "productos"', [[
            'id' => 300, 'text' => 'Guía de viaje', 'image_url' => null, 'page_id' => 5,
            'producto_id' => 40, 'oculto' => $oculto, 'borrado_en' => null,
            'precio' => $precio, 'archivo_ruta' => self::RUTA,
            'archivo_nombre' => 'guía.pdf', 'archivo_bytes' => 2048,
        ]]);
        $this->db->onInsert('INSERT INTO product_orders', 7);
    }

    private function hayCredencialesDeCobro($conectadoPor = 'oauth', $veces = 1)
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

    private function pedido(array $overrides = [])
    {
        return new Request('POST', array_merge([
            'link_id' => 300,
            'nombre'  => 'Ana Gómez',
            'email'   => 'ana@example.com',
        ], $overrides));
    }

    private function httpQueCreaLaPreferencia()
    {
        return (new FakeHttpClient())->responde('/checkout/preferences', 201, [
            'id' => 'PREF-9',
            'init_point' => 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-9',
        ]);
    }

    private function ordenBase(array $overrides = [])
    {
        return array_merge([
            'id' => 7, 'codigo' => self::CODIGO, 'product_id' => 40, 'link_id' => 300, 'pagina_id' => 5,
            'nombre' => 'Ana Gómez', 'email' => 'ana@example.com',
            'total' => '2500.00', 'estado' => 'pendiente',
            'creado_en' => date('Y-m-d H:i:s'),
            'token_cifrado' => Cripto::cifrar($this->token),
            'mail_enviado_en' => null, 'mail_intentos' => 0,
            'producto' => 'Guía de viaje', 'producto_imagen' => null,
            'archivo_ruta' => self::RUTA, 'archivo_nombre' => 'guía.pdf', 'archivo_bytes' => 2048,
            'pagina' => 'La Sala', 'url_slug' => 'la-sala', 'email_contacto' => null, 'meta_pixel_id' => null,
        ], $overrides);
    }

    private function hayOrden(array $overrides = [], $veces = 1)
    {
        for ($i = 0; $i < $veces; $i++) {
            $this->db->onSelect('WHERE o.codigo = ?', [$this->ordenBase($overrides)]);
        }
    }

    private function avisoDe($pagoId)
    {
        return new Request('POST', ['data' => ['id' => $pagoId]], ['orden' => self::CODIGO]);
    }

    private function httpConPago(array $pago = [])
    {
        return (new FakeHttpClient())->responde('/v1/payments/', 200, array_merge([
            'status' => 'approved',
            'external_reference' => self::CODIGO,
            'transaction_amount' => 2500.0,
        ], $pago));
    }

    // -------------------------------------------------------------- comprar

    public function testComprarDevuelveElLinkDePagoYElToken()
    {
        $this->hayProducto();
        $this->hayCredencialesDeCobro('oauth', 2);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $this->httpQueCreaLaPreferencia());

        $this->assertStatus(201, $r);
        $this->assertSame('pendiente', $r->body['estado']);
        $this->assertStringContainsString('mercadopago', $r->body['url']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r->body['token']);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{12}$/', $r->body['codigo']);
    }

    /** Lo que se guarda es el hash del token, más una copia cifrada: nunca el token. */
    public function testElTokenNoSeGuardaEnClaro()
    {
        $this->hayProducto();
        $this->hayCredencialesDeCobro('oauth', 2);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $this->httpQueCreaLaPreferencia());
        $params = $this->db->paramsFor('INSERT INTO product_orders');

        $this->assertNotContains($r->body['token'], $params);
        $this->assertContains(hash('sha256', $r->body['token']), $params);
        $this->assertSame($r->body['token'], Cripto::descifrar($params[9]));
    }

    public function testConOauthLaPreferenciaLlevaLaComisionDeUnaEntrada()
    {
        $this->hayProducto('2500.00');
        $this->hayCredencialesDeCobro('oauth', 2);
        $http = $this->httpQueCreaLaPreferencia();

        CompraProductoHandler::comprar($this->db, $this->pedido(), $http);
        $enviado = $http->jsonDe('/checkout/preferences');

        $this->assertSame(\Comision::sobre(2500), $enviado['marketplace_fee']);
        $this->assertSame([\Comision::sobre(2500), \Comision::porcentaje(), 7], $this->db->paramsFor('UPDATE product_orders SET comision'));
    }

    public function testSinOauthNoSeMandaComisionYSeCongelaCero()
    {
        $this->hayProducto();
        $this->hayCredencialesDeCobro('manual', 2);
        $http = $this->httpQueCreaLaPreferencia();

        CompraProductoHandler::comprar($this->db, $this->pedido(), $http);
        $enviado = $http->jsonDe('/checkout/preferences');

        $this->assertArrayNotHasKey('marketplace_fee', $enviado);
        $this->assertSame([0.0, 0.0, 7], $this->db->paramsFor('UPDATE product_orders SET comision'));
    }

    public function testLaPreferenciaVuelveALaPantallaDeLaCompraYAvisaAlEndpointDeProductos()
    {
        $this->hayProducto();
        $this->hayCredencialesDeCobro('oauth', 2);
        $http = $this->httpQueCreaLaPreferencia();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $http);
        $enviado = $http->jsonDe('/checkout/preferences');
        $codigo = $r->body['codigo'];

        $this->assertSame('https://frontend.test/compra/' . $codigo, $enviado['back_urls']['success']);
        $this->assertSame('https://rezonar.test/api/public/aviso-pago-producto.php?orden=' . $codigo, $enviado['notification_url']);
        $this->assertSame($codigo, $enviado['external_reference']);
        $this->assertSame([[
            'title' => 'Guía de viaje', 'quantity' => 1, 'unit_price' => 2500.0, 'currency_id' => 'ARS',
        ]], $enviado['items']);
    }

    /** El token nunca sale en una URL que llegue a un servidor. */
    public function testElTokenNoVaEnNingunaUrlDeLaPreferencia()
    {
        $this->hayProducto();
        $this->hayCredencialesDeCobro('oauth', 2);
        $http = $this->httpQueCreaLaPreferencia();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $http);

        $this->assertStringNotContainsString($r->body['token'], json_encode($http->llamadas));
    }

    public function testSinCredencialesNoSeCreaLaOrden()
    {
        $this->hayProducto();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), new FakeHttpClient());

        $this->assertError(503, $r);
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
    }

    public function testSiFallaLaPreferenciaLaOrdenSeCancela()
    {
        $this->hayProducto();
        $this->hayCredencialesDeCobro('oauth', 2);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), new FakeHttpClient());

        $this->assertError(502, $r);
        $this->assertTrue($this->db->ran("UPDATE product_orders SET estado = 'cancelada'"));
    }

    public function testLosDatosDelCompradorSeValidan()
    {
        $this->hayProducto();

        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->pedido(['nombre' => ' '])), 'nombre');
        $this->hayProducto();
        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->pedido(['email' => 'no-es-un-mail'])), 'email');
        $this->hayProducto();
        $this->assertError(400, CompraProductoHandler::comprar($this->db, $this->pedido(['nombre' => ['Ana']])), 'nombre');
        $this->assertNoWrites();
    }

    /** Un link que no es un producto con archivo —un evento, un link común— no se vende acá. */
    public function testUnLinkQueNoEsProductoDa404()
    {
        $r = CompraProductoHandler::comprar($this->db, $this->pedido());

        $this->assertError(404, $r);
        $this->assertNoWrites();
    }

    public function testComprarExigePostYLinkId()
    {
        $this->assertStatus(405, CompraProductoHandler::comprar($this->db, new Request('GET')));
        $this->assertError(400, CompraProductoHandler::comprar($this->db, new Request('POST', ['nombre' => 'x'])));
    }

    // ------------------------------------------------------ oculto / borrado

    public function testUnProductoOcultoNoAceptaPedidosPagos()
    {
        $this->hayProducto('2500.00', 1);
        $this->hayCredencialesDeCobro('oauth', 2);
        $http = $this->httpQueCreaLaPreferencia();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $http);

        $this->assertError(409, $r, 'ya no está disponible');
        $this->assertNoWrites();
        $this->assertSame([], $http->llamadas);
    }

    public function testUnProductoOcultoNoAceptaPedidosGratis()
    {
        $this->hayProducto('0.00', 1);
        $mailer = new FakeMailer();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), null, $mailer);

        $this->assertError(409, $r, 'ya no está disponible');
        $this->assertNoWrites();
        $this->assertSame([], $mailer->enviados);
    }

    /** Un pedido desde una página vieja, de un producto que se borró con ventas. */
    public function testUnProductoBorradoNoAceptaPedidos()
    {
        $this->db->onSelect('WHERE link_original = ?', [[1]]);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido());

        $this->assertError(409, $r, 'ya no está disponible');
        $this->assertNoWrites();
    }

    /** Ocultar no frena lo ya pedido: una pendiente se acredita igual. */
    public function testUnaOrdenDeUnProductoOcultoOBorradoSeAcredita()
    {
        // pagina_id viene de la foto del producto: el link puede no existir.
        $this->hayOrden(['link_id' => null, 'producto' => 'Guía (borrada)']);
        $this->db->onSelect('FROM page_payment_settings WHERE page_id', [[
            'page_id' => 5, 'access_token_cifrado' => Cripto::cifrar(self::TOKEN_MP), 'refresh_token_cifrado' => null,
            'conectado_por' => 'oauth', 'token_expira_en' => date('Y-m-d H:i:s', time() + 86400 * 180),
        ]]);
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['pendiente']]);
        $this->db->onWrite("SET estado = 'pagada'", 1);
        $this->hayOrden(['estado' => 'pagada', 'link_id' => null, 'producto' => 'Guía (borrada)']);
        $mailer = new FakeMailer();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago(), $mailer);

        $this->assertSame('pago acreditado', $r->body['motivo']);
        $this->assertSame([5], $this->db->paramsFor('FROM page_payment_settings WHERE page_id'));
        $this->assertFalse($this->db->ran('SELECT lg.page_id'));
        $this->assertStringContainsString('Guía (borrada)', $mailer->enviados[0]['asunto']);
    }

    /** Sin página (se borró con sus credenciales) no hay a quién preguntarle. */
    public function testSinPaginaElAvisoNoSePuedeVerificar()
    {
        $this->hayOrden(['link_id' => null, 'pagina_id' => null]);

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago());

        $this->assertSame('la página no tiene credenciales', $r->body['motivo']);
        $this->assertNoWrites();
    }

    // ---------------------------------------------------------------- largos

    /**
     * @dataProvider camposLargos
     */
    public function testUnCampoMasLargoQueSuColumnaDa400($campo, $valor, $texto)
    {
        $this->hayProducto();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido([$campo => $valor]));

        $this->assertError(400, $r, $texto);
        $this->assertNoWrites();
    }

    public static function camposLargos()
    {
        return [
            'nombre'   => ['nombre', str_repeat('ñ', 151), 'hasta 150'],
            'email'    => ['email', str_repeat('a', 250) . '@example.com', 'hasta 255'],
            'telefono' => ['telefono', str_repeat('1', 51), 'hasta 50'],
        ];
    }

    /** En el límite justo sí entra (y cuenta caracteres, no bytes). */
    public function testUnNombreDe150CaracteresEntra()
    {
        $this->hayProducto('0.00');

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['nombre' => str_repeat('ñ', 150)]), null, new FakeMailer());

        $this->assertStatus(201, $r);
    }

    // --------------------------------------------------------------- gratis

    /** Cinco por hora por IP, tres por email: después, 429 y ni orden ni mail. */
    public function testDemasiadosPedidosGratisDan429()
    {
        $this->hayProducto('0.00');
        $this->db->onSelect('WHERE email = ? AND total = 0', [[0]]);
        $this->db->onSelect('WHERE ip = ? AND total = 0', [[5]]);
        $mailer = new FakeMailer();
        $req = $this->pedido();
        $req->ip = '203.0.113.9';

        $r = CompraProductoHandler::comprar($this->db, $req, null, $mailer);

        $this->assertError(429, $r, 'demasiados pedidos');
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
        $this->assertSame([], $mailer->enviados);
        $this->assertSame(['203.0.113.9'], $this->db->paramsFor('WHERE ip = ?'));
    }

    public function testDemasiadosPedidosGratisDelMismoEmailDan429()
    {
        $this->hayProducto('0.00');
        $this->db->onSelect('WHERE email = ? AND total = 0', [[3]]);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(['email' => 'ANA@example.com']), null, new FakeMailer());

        $this->assertError(429, $r);
        $this->assertSame(['ana@example.com'], $this->db->paramsFor('WHERE email = ? AND total = 0'));
    }

    public function testLaOrdenGratisGuardaLaIp()
    {
        $this->hayProducto('0.00');
        $req = $this->pedido();
        $req->ip = '203.0.113.9';

        CompraProductoHandler::comprar($this->db, $req, null, new FakeMailer());

        $this->assertSame('203.0.113.9', $this->db->paramsFor('INSERT INTO product_orders')[10]);
        $this->assertSame(40, $this->db->paramsFor('INSERT INTO product_orders')[1]);
    }

    /** Una IP que no es IP no se guarda ni se cuenta. */
    public function testUnaIpInvalidaSeIgnora()
    {
        $this->hayProducto('0.00');
        $req = $this->pedido();
        $req->ip = 'no-es-una-ip';

        CompraProductoHandler::comprar($this->db, $req, null, new FakeMailer());

        $this->assertNull($this->db->paramsFor('INSERT INTO product_orders')[10]);
        $this->assertFalse($this->db->ran('WHERE ip = ?'));
    }

    // --------------------------------------------------------------- gratis

    public function testUnProductoGratisQuedaPagadoSinMercadoPagoYMandaElMail()
    {
        $this->hayProducto('0.00');
        $this->hayOrden(['estado' => 'pagada', 'total' => '0.00']);
        $http = new FakeHttpClient();
        $mailer = new FakeMailer();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), $http, $mailer);

        $this->assertStatus(201, $r);
        $this->assertSame('pagada', $r->body['estado']);
        $this->assertNull($r->body['url']);
        $this->assertSame([], $http->llamadas);
        $this->assertSame('pagada', $this->db->paramsFor('INSERT INTO product_orders')[6]);
        $this->assertCount(1, $mailer->enviados);
    }

    /** Sin token en la respuesta: el link llega al email, que es lo que se pide a cambio. */
    public function testUnProductoGratisNoDevuelveElToken()
    {
        $this->hayProducto('0.00');

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), null, new FakeMailer());

        $this->assertArrayNotHasKey('token', $r->body);
    }

    public function testPedirloDosVecesReenviaElMailSinCrearOtraOrden()
    {
        $this->hayProducto('0.00');
        $this->db->onSelect('WHERE product_id = ? AND email = ?', [[self::CODIGO]]);
        $this->hayOrden(['estado' => 'pagada', 'total' => '0.00', 'mail_enviado_en' => '2020-01-01 00:00:00', 'mail_intentos' => 1]);
        $mailer = new FakeMailer();

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), null, $mailer);

        $this->assertStatus(201, $r);
        $this->assertSame(self::CODIGO, $r->body['codigo']);
        $this->assertFalse($this->db->ran('INSERT INTO product_orders'));
        $this->assertCount(1, $mailer->enviados);
        $this->assertStringContainsString('/descarga#' . $this->token, $mailer->enviados[0]['html']);
    }

    public function testUnFalloDeCorreoNoVolteaElPedidoGratis()
    {
        $this->hayProducto('0.00');
        $this->hayOrden(['estado' => 'pagada', 'total' => '0.00']);

        $r = CompraProductoHandler::comprar($this->db, $this->pedido(), null, (new FakeMailer())->fallarCon('SMTP caído'));

        $this->assertStatus(201, $r);
        $this->assertSame(['SMTP caído', 7], $this->db->paramsFor('SET mail_intentos = mail_intentos + 1, mail_error'));
    }

    // ---------------------------------------------------------------- aviso

    public function testUnPagoAprobadoAcreditaYMandaElLink()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['pendiente']]);
        $this->db->onWrite("SET estado = 'pagada'", 1);
        $this->hayOrden(['estado' => 'pagada']);
        $mailer = new FakeMailer();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago(), $mailer);

        $this->assertStatus(200, $r);
        $this->assertSame('pago acreditado', $r->body['motivo']);
        $this->assertSame('555', $this->db->paramsFor("SET estado = 'pagada'")[0]);
        $this->assertCount(1, $mailer->enviados);
    }

    /** Mercado Pago reintenta: el segundo aviso del mismo pago no acredita ni manda nada. */
    public function testUnAvisoRepetidoNoAcreditaDosVeces()
    {
        $this->hayOrden(['estado' => 'pagada']);
        $this->hayCredencialesDeCobro();
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['pagada']]);
        $mailer = new FakeMailer();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago(), $mailer);

        $this->assertSame('ya estaba pagada', $r->body['motivo']);
        $this->assertNoWrites();
        $this->assertSame([], $mailer->enviados);
    }

    public function testUnPagoDeOtraOrdenNoSeAcredita()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago(['external_reference' => 'OTRAORDEN123']));

        $this->assertSame('el pago es de otra orden', $r->body['motivo']);
        $this->assertNoWrites();
    }

    public function testUnPagoPorOtroMontoNoSeAcredita()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago(['transaction_amount' => 10.0]));

        $this->assertSame('el monto no coincide con la orden', $r->body['motivo']);
        $this->assertNoWrites();
    }

    /** El aviso de entradas no sirve para acreditar una orden de producto. */
    public function testUnAvisoDeUnaOrdenInexistenteSeResponde200()
    {
        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago());

        $this->assertStatus(200, $r);
        $this->assertSame('orden inexistente', $r->body['motivo']);
    }

    /** En productos la referencia tiene que venir: un pago sin ella no acredita. */
    public function testUnPagoSinReferenciaNoSeAcredita()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago(['external_reference' => null]));

        $this->assertSame('el pago es de otra orden', $r->body['motivo']);
        $this->assertNoWrites();
    }

    public function testUnPagoSinMontoNoSeAcredita()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), $this->httpConPago(['transaction_amount' => null]));

        $this->assertSame('el monto no coincide con la orden', $r->body['motivo']);
        $this->assertNoWrites();
    }

    public function testConciliarExigeReferenciaYMonto()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();
        $http = (new FakeHttpClient())->responde('/v1/payments/search', 200, ['results' => [[
            'id' => 777, 'status' => 'approved', 'external_reference' => self::CODIGO,
        ]]]);

        $r = CompraProductoHandler::conciliar($this->db, self::CODIGO, $http);

        $this->assertFalse($r['acreditada']);
        $this->assertSame('el monto no coincide con la orden', $r['motivo']);
        $this->assertNoWrites();
    }

    public function testSiNoSePudoConsultarElPagoSePideReintento()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();

        $r = CompraProductoHandler::aviso($this->db, $this->avisoDe('555'), new FakeHttpClient());

        $this->assertStatus(503, $r);
    }

    public function testUnAvisoSinDatosSeResponde200()
    {
        $r = CompraProductoHandler::aviso($this->db, new Request('POST', [], []));

        $this->assertStatus(200, $r);
        $this->assertSame('aviso sin datos utilizables', $r->body['motivo']);
    }

    public function testElAvisoRechazaOtrosMetodos()
    {
        $this->assertStatus(405, CompraProductoHandler::aviso($this->db, new Request('PUT')));
    }

    // ------------------------------------------------------------ conciliar

    public function testConciliarAcreditaUnPagoQueNoAviso()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['pendiente']]);
        $this->db->onWrite("SET estado = 'pagada'", 1);
        $http = (new FakeHttpClient())->responde('/v1/payments/search', 200, ['results' => [[
            'id' => 777, 'status' => 'approved', 'external_reference' => self::CODIGO, 'transaction_amount' => 2500.0,
        ]]]);

        $r = CompraProductoHandler::conciliar($this->db, self::CODIGO, $http, new FakeMailer());

        $this->assertTrue($r['acreditada']);
        $this->assertSame('777', $this->db->paramsFor("SET estado = 'pagada'")[0]);
    }

    public function testConciliarNoAcreditaSiElMontoNoCoincide()
    {
        $this->hayOrden();
        $this->hayCredencialesDeCobro();
        $http = (new FakeHttpClient())->responde('/v1/payments/search', 200, ['results' => [[
            'id' => 777, 'status' => 'approved', 'external_reference' => self::CODIGO, 'transaction_amount' => 1.0,
        ]]]);

        $r = CompraProductoHandler::conciliar($this->db, self::CODIGO, $http);

        $this->assertFalse($r['acreditada']);
        $this->assertNoWrites();
    }

    public function testConciliarNoRevisaLoYaPagado()
    {
        $this->hayOrden(['estado' => 'pagada']);
        $http = new FakeHttpClient();

        $r = CompraProductoHandler::conciliar($this->db, self::CODIGO, $http);

        $this->assertFalse($r['revisada']);
        $this->assertSame([], $http->llamadas);
    }

    // ---------------------------------------------------------------- orden

    public function testLaOrdenNoExponeDatosPersonalesNiElToken()
    {
        $this->hayOrden(['estado' => 'pagada']);

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));

        $this->assertStatus(200, $r);
        $this->assertSame('pagada', $r->body['orden']['estado']);
        $this->assertSame('Guía de viaje', $r->body['orden']['producto']);
        $json = json_encode($r->body);
        $this->assertStringNotContainsString('ana@example.com', $json);
        $this->assertStringNotContainsString('Ana Gómez', $json);
        $this->assertStringNotContainsString($this->token, $json);
        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString(self::RUTA, $json);
    }

    /** La pantalla de vuelta concilia antes de decir que no se pagó. */
    public function testLaPantallaDeVueltaConciliaAlEntrar()
    {
        $this->hayOrden();
        $this->hayOrden();
        $this->hayCredencialesDeCobro();
        $this->db->onSelect('SELECT estado FROM product_orders WHERE codigo', [['pendiente']]);
        $this->db->onWrite("SET estado = 'pagada'", 1);
        $this->hayOrden(['estado' => 'pagada'], 3);
        $http = (new FakeHttpClient())->responde('/v1/payments/search', 200, ['results' => [[
            'id' => 777, 'status' => 'approved', 'external_reference' => self::CODIGO, 'transaction_amount' => 2500.0,
        ]]]);

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]), $http, new FakeMailer());

        $this->assertSame('pagada', $r->body['orden']['estado']);
    }

    public function testUnaPendienteViejaSeInformaVencida()
    {
        $this->hayOrden(['creado_en' => '2020-01-01 00:00:00'], 2);

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]), new FakeHttpClient());

        $this->assertSame('vencida', $r->body['orden']['estado']);
    }

    /** Fuera de la ventana de la conciliación, mirar la orden no le pregunta a Mercado Pago. */
    public function testUnaOrdenViejaNoSeConciliaAlMirarla()
    {
        $vieja = date('Y-m-d H:i:s', time() - (OrdenesDeProductos::DIAS_A_CONCILIAR + 1) * 86400);
        $this->hayOrden(['estado' => 'rechazada', 'creado_en' => $vieja]);
        $this->hayCredencialesDeCobro();
        $http = new FakeHttpClient();

        $r = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]), $http);

        $this->assertSame('rechazada', $r->body['orden']['estado']);
        $this->assertSame([], $http->llamadas);
    }

    public function testUnaOrdenDentroDeLaVentanaSiSeConcilia()
    {
        $this->hayOrden(['estado' => 'rechazada', 'creado_en' => date('Y-m-d H:i:s', time() - 86400)], 2);
        $this->hayCredencialesDeCobro();
        $http = new FakeHttpClient();

        CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]), $http);

        $this->assertNotSame([], $http->llamadas);
    }

    /** La pantalla de descarga funciona con el producto borrado: título de la foto, sin imagen ni página. */
    public function testLaDescargaDeUnProductoBorradoMuestraSuTitulo()
    {
        $this->db->onSelect('WHERE o.token_hash = ?', [$this->ordenBase([
            'estado' => 'pagada', 'link_id' => null, 'producto' => 'Guía guardada',
            'producto_imagen' => null, 'pagina' => null, 'url_slug' => null,
        ])]);

        $r = CompraProductoHandler::descarga($this->db, new Request('POST', ['token' => $this->token]));

        $this->assertStatus(200, $r);
        $this->assertSame('Guía guardada', $r->body['producto']['titulo']);
        $this->assertNull($r->body['producto']['imagen']);
        $this->assertNull($r->body['producto']['url_slug']);
    }

    public function testUnaOrdenInexistenteDa404()
    {
        $this->assertError(404, CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => 'NOEXISTE1234'])));
        $this->assertError(400, CompraProductoHandler::orden($this->db, new Request('GET')));
        $this->assertStatus(405, CompraProductoHandler::orden($this->db, new Request('POST', [], ['codigo' => self::CODIGO])));
    }

    // ------------------------------------------------------------- descarga

    private function hayOrdenPorToken(array $overrides = [])
    {
        $this->db->onSelect('WHERE o.token_hash = ?', [$this->ordenBase(array_merge(['estado' => 'pagada'], $overrides))]);
    }

    private function discoConElArchivo($contenido = 'contenido del pdf')
    {
        $disco = new FakeDisco();
        $disco->archivos[ProductosDigitales::rutaEnDisco(self::RUTA)] = $contenido;

        return $disco;
    }

    private function formulario($token)
    {
        return new Request('POST', [], [], null, [], [], ['token' => $token]);
    }

    public function testLaPantallaDeDescargaRecibeLosDatosDelProducto()
    {
        $this->hayOrdenPorToken();

        $r = CompraProductoHandler::descarga($this->db, new Request('POST', ['token' => $this->token]));

        $this->assertStatus(200, $r);
        $this->assertSame('Guía de viaje', $r->body['producto']['titulo']);
        $this->assertSame('guía.pdf', $r->body['producto']['archivo_nombre']);
        $this->assertSame(2048, $r->body['producto']['archivo_bytes']);
        $this->assertSame(hash('sha256', $this->token), $this->db->paramsFor('WHERE o.token_hash = ?')[0]);
        $this->assertArrayNotHasKey('archivo_ruta', $r->body['producto']);
    }

    public function testUnTokenInventadoDa404SinDetalles()
    {
        $r = CompraProductoHandler::descarga($this->db, new Request('POST', ['token' => str_repeat('cd', 32)]));

        $this->assertError(404, $r, 'no es válido');
    }

    /** Un token de una orden sin pagar responde exactamente lo mismo que uno inventado. */
    public function testUnTokenDeUnaOrdenNoPagadaDaLoMismoQueUnoInventado()
    {
        $this->hayOrdenPorToken(['estado' => 'pendiente']);
        $noPagada = CompraProductoHandler::descarga($this->db, new Request('POST', ['token' => $this->token]));
        $inventado = CompraProductoHandler::descarga($this->db, new Request('POST', ['token' => str_repeat('cd', 32)]));

        $this->assertSame($inventado->status, $noPagada->status);
        $this->assertSame($inventado->body, $noPagada->body);
    }

    /** Una forma que no puede ser un token ni llega a la base. */
    public function testUnTokenConOtraFormaNoConsultaLaBase()
    {
        foreach (['', 'abc', self::CODIGO, str_repeat('zz', 32), [$this->token]] as $token) {
            $r = CompraProductoHandler::descarga($this->db, new Request('POST', ['token' => $token]));
            $this->assertStatus(404, $r);
        }

        $this->assertFalse($this->db->ran('FROM product_orders'));
    }

    /** El token no va nunca en la URL: un GET no descarga ni muestra nada. */
    public function testElTokenNoSeAceptaPorLaUrl()
    {
        $this->assertStatus(405, CompraProductoHandler::descarga($this->db, new Request('GET', [], ['token' => $this->token])));
        $this->assertStatus(405, CompraProductoHandler::archivo($this->db, new Request('GET', [], ['token' => $this->token]), $this->discoConElArchivo()));
        $this->assertFalse($this->db->ran('FROM product_orders'));
    }

    public function testConElTokenSeDescargaElArchivoComoAdjunto()
    {
        $this->hayOrdenPorToken();
        $disco = $this->discoConElArchivo('0123456789');

        $r = CompraProductoHandler::archivo($this->db, $this->formulario($this->token), $disco);

        $this->assertStatus(200, $r);
        $this->assertSame(ProductosDigitales::rutaEnDisco(self::RUTA), $r->archivo);
        $this->assertSame([
            'Content-Type'           => 'application/octet-stream',
            'Content-Disposition'    => "attachment; filename*=UTF-8''gu%C3%ADa.pdf",
            'Content-Length'         => '10',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ], $r->headers);
    }

    public function testCadaDescargaSumaAlContador()
    {
        $this->hayOrdenPorToken();
        $this->hayOrdenPorToken();
        $disco = $this->discoConElArchivo();

        CompraProductoHandler::archivo($this->db, $this->formulario($this->token), $disco);
        CompraProductoHandler::archivo($this->db, $this->formulario($this->token), $disco);

        $this->assertSame(2, $this->db->countCalls('SET descargas = descargas + 1'));
        $this->assertSame([7], $this->db->paramsFor('SET descargas = descargas + 1'));
    }

    public function testConUnTokenInvalidoNoSeDescargaNiSeCuenta()
    {
        $r = CompraProductoHandler::archivo($this->db, $this->formulario(str_repeat('cd', 32)), $this->discoConElArchivo());

        $this->assertStatus(404, $r);
        $this->assertNull($r->archivo);
        $this->assertSame('text/html; charset=UTF-8', $r->headers['Content-Type']);
        $this->assertStringContainsString('no es válido', $r->raw);
        $this->assertNoWrites();
    }

    public function testUnaOrdenNoPagadaNoDescarga()
    {
        $this->hayOrdenPorToken(['estado' => 'pendiente']);

        $r = CompraProductoHandler::archivo($this->db, $this->formulario($this->token), $this->discoConElArchivo());

        $this->assertStatus(404, $r);
        $this->assertNull($r->archivo);
    }

    /** Una ruta guardada que no tiene la forma de las nuestras no se lee nunca. */
    public function testUnaRutaRaraNoSeSirve()
    {
        $this->hayOrdenPorToken(['archivo_ruta' => '../../config.php']);

        $r = CompraProductoHandler::archivo($this->db, $this->formulario($this->token), $this->discoConElArchivo());

        $this->assertStatus(404, $r);
        $this->assertNull($r->archivo);
        $this->assertNoWrites();
    }

    public function testSiElArchivoNoEstaEnDiscoNoSeCuentaLaDescarga()
    {
        $this->hayOrdenPorToken();

        $r = CompraProductoHandler::archivo($this->db, $this->formulario($this->token), new FakeDisco());

        $this->assertStatus(404, $r);
        $this->assertNoWrites();
    }

    /** El nombre de la descarga no puede romper la cabecera. */
    public function testElNombreDelArchivoVaCodificado()
    {
        $this->hayOrdenPorToken(['archivo_nombre' => "plan \"final\";\r\nX-Malo: 1.zip"]);

        $r = CompraProductoHandler::archivo($this->db, $this->formulario($this->token), $this->discoConElArchivo());

        $this->assertStringNotContainsString("\n", $r->headers['Content-Disposition']);
        $this->assertStringNotContainsString('"', $r->headers['Content-Disposition']);
        $this->assertStringStartsWith("attachment; filename*=UTF-8''", $r->headers['Content-Disposition']);
    }

    public function testElTokenEsLaUnicaCredencialNoElCodigo()
    {
        $this->assertSame('https://frontend.test/descarga#' . $this->token, OrdenesDeProductos::urlDeDescarga($this->token));
    }
}
