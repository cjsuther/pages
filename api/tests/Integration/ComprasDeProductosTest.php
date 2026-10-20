<?php

namespace Tests\Integration;

use CheckoutHandler;
use Cobros;
use CompraProductoHandler;
use CorreoProductos;
use Cripto;
use EntradasHandler;
use LinksHandler;
use OrdenesDeProductos;
use ProductosDigitales;
use ProductosHandler;
use Request;
use Tests\Support\FakeDisco;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Compra, pago y descarga de productos digitales contra una MariaDB de verdad
 * (#18). Mercado Pago, el correo y el disco son dobles.
 *
 * Lo que se afirma es lo que quedó escrito en la base: que el pago se acredite
 * una sola vez, que la comisión sea la de una entrada y que el link de
 * descarga sea la única llave.
 */
class ComprasDeProductosTest extends IntegracionTestCase
{
    private $duenaId;
    private $paginaId;
    private $grupoId;
    /** @var FakeHttpClient */
    private $http;
    /** @var FakeMailer */
    private $mailer;
    /** @var FakeDisco */
    private $disco;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId, 'productos', 'Tienda');
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();
        $this->disco = new FakeDisco();
    }

    // ============================================================ fixtures

    /** Un producto con su archivo, que queda "en disco" en el FakeDisco. */
    private function producto($precio = '2500.00', $titulo = 'Guía de viaje', $contenido = 'PDF de la guía')
    {
        $linkId = $this->insertar('links', [
            'group_id' => $this->grupoId, 'url' => '', 'text' => $titulo, 'position' => 0,
            'image_url' => 'https://rezonar.test/api/uploads/tapa.jpg',
        ]);
        $ruta = ProductosDigitales::rutaNueva();
        $this->insertar('digital_products', [
            'link_id' => $linkId, 'precio' => $precio, 'archivo_ruta' => $ruta,
            'archivo_nombre' => 'guía.pdf', 'archivo_bytes' => strlen($contenido), 'archivo_tipo' => 'application/pdf',
        ]);
        $this->disco->archivos[ProductosDigitales::rutaEnDisco($ruta)] = $contenido;

        return $linkId;
    }

    private function conectarPorOAuth()
    {
        $r = Cobros::guardarDesdeOAuth($this->db, $this->paginaId, [
            'user_id'       => '987654',
            'access_token'  => 'APP_USR-1234567890-token-de-prueba',
            'refresh_token' => 'TG-refresh-de-prueba',
            'public_key'    => 'APP_USR-public-key',
            'modo'          => 'produccion',
            'expira_en'     => gmdate('Y-m-d H:i:s', time() + 90 * 86400),
        ]);

        $this->assertTrue($r['ok']);
    }

    private function conectarAMano()
    {
        $this->insertar('page_payment_settings', [
            'page_id'              => $this->paginaId,
            'access_token_cifrado' => Cripto::cifrar('APP_USR-manual-token'),
            'token_ultimos4'       => 'oken',
            'public_key'           => 'APP_USR-public-key',
            'modo'                 => 'produccion',
            'conectado_por'        => 'manual',
        ]);
    }

    private function preferenciaOk()
    {
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);
    }

    private function pagoDeMercadoPago($codigo, $total, $estado = 'approved', array $extra = [])
    {
        $this->http->responde('/v1/payments/', 200, array_merge([
            'id' => 555001,
            'status' => $estado,
            'external_reference' => $codigo,
            'transaction_amount' => $total,
            'fee_details' => [['type' => 'application_fee', 'amount' => 37.5]],
        ], $extra));
    }

    private function comprar($linkId, array $datos = [])
    {
        return CompraProductoHandler::comprar($this->db, $this->post(array_merge([
            'link_id' => $linkId,
            'nombre'  => 'Ana Compradora',
            'email'   => 'ana@test.local',
        ], $datos)), $this->http, $this->mailer);
    }

    private function aviso($codigo, $pagoId = '555001')
    {
        return CompraProductoHandler::aviso(
            $this->db,
            $this->post(['type' => 'payment', 'data' => ['id' => $pagoId]], null, ['orden' => $codigo]),
            $this->http,
            $this->mailer
        );
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM product_orders WHERE codigo = ?', [$codigo]);
    }

    private function cantidadDeOrdenes()
    {
        return (int) $this->valor('SELECT COUNT(*) FROM product_orders');
    }

    private function descargar($token)
    {
        return CompraProductoHandler::archivo(
            $this->db,
            new Request('POST', [], [], null, [], [], ['token' => $token]),
            $this->disco
        );
    }

    /** Una compra paga ya acreditada; devuelve [codigo, token]. */
    private function compraPagada($linkId)
    {
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $res = $this->comprar($linkId);
        $this->pagoDeMercadoPago($res->body['codigo'], 2500.0);
        $this->aviso($res->body['codigo']);

        return [$res->body['codigo'], $res->body['token']];
    }

    // ============================================================ esquema

    public function testLaTablaTieneLoQuePideElIssue()
    {
        $esquema = self::esquema();

        $this->assertArrayHasKey('product_orders', $esquema);

        foreach ([
            'codigo', 'link_id', 'nombre', 'email', 'telefono', 'total', 'comision', 'comision_porcentaje',
            'mp_comision_cobrada', 'estado', 'mp_preference_id', 'mp_payment_id', 'pagada_en',
            'token_hash', 'token_cifrado', 'descargas', 'primera_descarga_en', 'ultima_descarga_en',
            'mail_enviado_en', 'mail_intentos', 'mail_error', 'creado_en',
        ] as $columna) {
            $this->assertArrayHasKey($columna, $esquema['product_orders'], $columna);
        }

        $estado = $this->fila("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_orders' AND COLUMN_NAME = 'estado'
        ")['COLUMN_TYPE'];
        $this->assertSame("enum('pendiente','pagada','rechazada','cancelada','vencida')", $estado);
    }

    public function testLaMigracionEsIdempotente()
    {
        [$codigo] = $this->compraPagada($this->producto());
        $sql = file_get_contents(dirname(__DIR__, 3) . '/migration_ordenes_de_productos.sql');

        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $sentencia) {
            $this->db->exec($sentencia);
        }

        $this->assertSame('pagada', $this->orden($codigo)['estado']);
    }

    // ========================================================= compra paga

    public function testUnaCompraPagaQuedaPendienteConSuPreferencia()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();

        $res = $this->comprar($linkId, ['telefono' => '11 5555-1234']);

        $this->assertStatus(201, $res);
        $orden = $this->orden($res->body['codigo']);
        $this->assertSame('pendiente', $orden['estado']);
        $this->assertSame('2500.00', $orden['total']);
        $this->assertSame('pref-123', $orden['mp_preference_id']);
        $this->assertSame('11 5555-1234', $orden['telefono']);
        $this->assertNull($orden['pagada_en']);
        $this->assertSame(0, (int) $orden['descargas']);
    }

    /** Se guarda el hash y una copia cifrada; el token en claro no está en ninguna columna. */
    public function testElTokenSoloQuedaHasheadoYCifrado()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();

        $res = $this->comprar($linkId);
        $token = $res->body['token'];
        $orden = $this->orden($res->body['codigo']);

        $this->assertSame(hash('sha256', $token), $orden['token_hash']);
        $this->assertSame($token, Cripto::descifrar($orden['token_cifrado']));
        $this->assertNotContains($token, $orden);
    }

    public function testConOauthLaPreferenciaLlevaMarketplaceFeeYLaComisionQuedaCongelada()
    {
        $linkId = $this->producto('2500.00');
        $this->conectarPorOAuth();
        $this->preferenciaOk();

        $res = $this->comprar($linkId);

        $enviado = $this->http->jsonDe('/checkout/preferences');
        $this->assertSame(\Comision::sobre(2500), $enviado['marketplace_fee']);

        $orden = $this->orden($res->body['codigo']);
        $this->assertEquals(\Comision::sobre(2500), (float) $orden['comision']);
        $this->assertEquals(\Comision::porcentaje(), (float) $orden['comision_porcentaje']);
    }

    public function testConUnaCredencialAManoNoSeMandaMarketplaceFee()
    {
        $linkId = $this->producto();
        $this->conectarAMano();
        $this->preferenciaOk();

        $res = $this->comprar($linkId);

        $this->assertStatus(201, $res);
        $this->assertArrayNotHasKey('marketplace_fee', $this->http->jsonDe('/checkout/preferences'));
        $this->assertSame('0.00', $this->orden($res->body['codigo'])['comision']);
    }

    /** La comisión de un producto es la de una entrada del mismo total. */
    public function testLaComisionEsLaMismaQueLaDeUnaEntradaDelMismoTotal()
    {
        $this->conectarPorOAuth();

        $eventos = $this->grupo($this->paginaId, 'eventos', 'Fechas');
        $eventoId = $this->evento($eventos);
        $config = EntradasHandler::config($this->db, $this->post(
            ['activo' => 1, 'capacidad' => 10, 'precio' => 3333.33, 'max_por_compra' => 10],
            $this->user($this->duenaId),
            ['link_id' => $eventoId]
        ));
        $this->assertStatus(200, $config);

        $this->preferenciaOk();
        $entrada = CheckoutHandler::comprar($this->db, $this->post([
            'link_id' => $eventoId, 'nombre' => 'Ana', 'email' => 'ana@test.local', 'cantidad' => 1,
        ]), $this->http, $this->mailer);
        $this->assertStatus(201, $entrada);

        $this->preferenciaOk();
        $producto = $this->comprar($this->producto('3333.33'));
        $this->assertStatus(201, $producto);

        $deLaEntrada = $this->fila('SELECT comision, comision_porcentaje FROM ticket_orders WHERE codigo = ?', [$entrada->body['codigo']]);
        $delProducto = $this->fila('SELECT comision, comision_porcentaje FROM product_orders WHERE codigo = ?', [$producto->body['codigo']]);

        $this->assertSame($deLaEntrada['comision'], $delProducto['comision']);
        $this->assertSame($deLaEntrada['comision_porcentaje'], $delProducto['comision_porcentaje']);
        $this->assertNotSame('0.00', $delProducto['comision']);
    }

    public function testSinMercadoPagoConectadoNoSeVende()
    {
        $res = $this->comprar($this->producto());

        $this->assertStatus(503, $res);
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    /** Un evento no es un producto: no se compra por acá. */
    public function testUnEventoNoSeCompraComoProducto()
    {
        $this->conectarPorOAuth();
        $eventoId = $this->evento($this->grupo($this->paginaId, 'eventos', 'Fechas'));

        $this->assertStatus(404, $this->comprar($eventoId));
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    // ================================================================ aviso

    public function testElAvisoAcreditaUnaSolaVezYMandaUnSoloMail()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];

        $this->pagoDeMercadoPago($codigo, 2500.0);
        $primero = $this->aviso($codigo);
        $this->pagoDeMercadoPago($codigo, 2500.0);
        $segundo = $this->aviso($codigo);

        $this->assertSame('pago acreditado', $primero->body['motivo']);
        $this->assertSame('ya estaba pagada', $segundo->body['motivo']);

        $orden = $this->orden($codigo);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame('555001', $orden['mp_payment_id']);
        $this->assertSame('37.50', $orden['mp_comision_cobrada']);
        $this->assertNotNull($orden['pagada_en']);
        $this->assertNotNull($orden['mail_enviado_en']);
        $this->assertCount(1, $this->mailer->enviados);
        $this->assertStringContainsString('Guía de viaje', $this->mailer->enviados[0]['asunto']);
    }

    public function testUnMontoQueNoCoincideNoAcredita()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];

        $this->pagoDeMercadoPago($codigo, 1.0);
        $res = $this->aviso($codigo);

        $this->assertSame('el monto no coincide con la orden', $res->body['motivo']);
        $this->assertSame('pendiente', $this->orden($codigo)['estado']);
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testUnaReferenciaQueNoCoincideNoAcredita()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];

        $this->pagoDeMercadoPago('OTRAORDEN999', 2500.0);
        $res = $this->aviso($codigo);

        $this->assertSame('el pago es de otra orden', $res->body['motivo']);
        $this->assertSame('pendiente', $this->orden($codigo)['estado']);
    }

    /** Un mismo pago no puede acreditar dos órdenes: el índice único lo impide. */
    public function testUnMismoPagoNoAcreditaDosOrdenes()
    {
        [$codigo] = $this->compraPagada($this->producto());
        $this->preferenciaOk();
        $otra = $this->comprar($this->producto('2500.00', 'Otra guía'))->body['codigo'];

        $this->expectException(\PDOException::class);
        OrdenesDeProductos::acreditarPago($this->db, $otra, '555001', 'approved');
    }

    /** Quien reintenta con otra tarjeta después de un rechazo tiene que recibir lo que pagó. */
    public function testUnAprobadoDespuesDeUnRechazoAcredita()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];

        $this->pagoDeMercadoPago($codigo, 2500.0, 'rejected', ['id' => 1]);
        $this->aviso($codigo, '1');
        $this->assertSame('rechazada', $this->orden($codigo)['estado']);

        $this->pagoDeMercadoPago($codigo, 2500.0, 'approved', ['id' => 2]);
        $this->aviso($codigo, '2');

        $this->assertSame('pagada', $this->orden($codigo)['estado']);
        $this->assertSame('2', $this->orden($codigo)['mp_payment_id']);
    }

    // ========================================================= conciliación

    public function testLaConciliacionAcreditaUnPagoQueNoAviso()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];

        $this->assertSame([$codigo], OrdenesDeProductos::sinConciliar($this->db));

        $this->http->responde('/v1/payments/search', 200, ['results' => [[
            'id' => 777, 'status' => 'approved', 'external_reference' => $codigo, 'transaction_amount' => 2500.0,
        ]]]);
        $r = CompraProductoHandler::conciliar($this->db, $codigo, $this->http, $this->mailer);

        $this->assertTrue($r['acreditada']);
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
        $this->assertSame('777', $this->orden($codigo)['mp_payment_id']);
        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame([], OrdenesDeProductos::sinConciliar($this->db));
    }

    /** Las de entradas y las de productos no se mezclan en la conciliación. */
    public function testLaConciliacionDeEntradasNoVeLasDeProductos()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $this->comprar($linkId);

        $this->assertSame([], \Entradas::sinConciliar($this->db));
    }

    public function testLaPantallaDeVueltaConciliaYNoMuestraDatosPersonales()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->http->responde('/v1/payments/search', 200, ['results' => [[
            'id' => 777, 'status' => 'approved', 'external_reference' => $codigo, 'transaction_amount' => 2500.0,
        ]]]);

        $res = CompraProductoHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http, $this->mailer);

        $this->assertStatus(200, $res);
        $this->assertSame('pagada', $res->body['orden']['estado']);
        $this->assertSame('Guía de viaje', $res->body['orden']['producto']);
        $this->assertStringNotContainsString('ana@test.local', json_encode($res->body));
    }

    // =============================================================== gratis

    public function testUnProductoGratisQuedaPagadoSinMercadoPagoYMandaElMail()
    {
        $res = $this->comprar($this->producto('0.00'));

        $this->assertStatus(201, $res);
        $orden = $this->orden($res->body['codigo']);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame('0.00', $orden['total']);
        $this->assertSame('0.00', $orden['comision']);
        $this->assertNotNull($orden['pagada_en']);
        $this->assertNotNull($orden['mail_enviado_en']);
        $this->assertSame([], $this->http->llamadas);
        $this->assertCount(1, $this->mailer->enviados);
    }

    public function testElSegundoPedidoDelMismoEmailReenviaElMailSinCrearOtraOrden()
    {
        $linkId = $this->producto('0.00');
        $primero = $this->comprar($linkId);
        // Pasado el mínimo entre reenvíos.
        $this->db->exec("UPDATE product_orders SET mail_enviado_en = NOW() - INTERVAL 1 HOUR");

        $segundo = $this->comprar($linkId, ['email' => 'ANA@test.local', 'nombre' => 'Otra persona']);

        $this->assertStatus(201, $segundo);
        $this->assertSame($primero->body['codigo'], $segundo->body['codigo']);
        $this->assertSame(1, $this->cantidadDeOrdenes());
        $this->assertCount(2, $this->mailer->enviados);
        $this->assertSame($this->mailer->enviados[0]['html'], $this->mailer->enviados[1]['html'], 'es el mismo link');
        $this->assertSame(2, (int) $this->orden($primero->body['codigo'])['mail_intentos']);
    }

    public function testOtroEmailPideSuPropiaOrden()
    {
        $linkId = $this->producto('0.00');

        $this->comprar($linkId);
        $this->comprar($linkId, ['email' => 'beto@test.local']);

        $this->assertSame(2, $this->cantidadDeOrdenes());
    }

    // ============================================================= descarga

    public function testConElTokenSeDescargaTantasVecesComoSeQuieraYSeCuenta()
    {
        $linkId = $this->producto();
        [$codigo, $token] = $this->compraPagada($linkId);

        foreach ([1, 2, 3] as $vez) {
            $res = $this->descargar($token);
            $this->assertStatus(200, $res);
            $this->assertSame('attachment; filename*=UTF-8\'\'gu%C3%ADa.pdf', $res->headers['Content-Disposition']);
            $this->assertSame((string) strlen('PDF de la guía'), $res->headers['Content-Length']);
        }

        $orden = $this->orden($codigo);
        $this->assertSame(3, (int) $orden['descargas']);
        $this->assertNotNull($orden['primera_descarga_en']);
        $this->assertNotNull($orden['ultima_descarga_en']);
    }

    /** El link del mail es el que descarga: el token sale de ahí, después del #. */
    public function testElLinkDelMailDescarga()
    {
        $this->compraPagada($this->producto());
        preg_match('#/descarga\#([a-f0-9]{64})"#', $this->mailer->enviados[0]['html'], $m);

        $this->assertNotEmpty($m);
        $this->assertStatus(200, $this->descargar($m[1]));
    }

    public function testConUnTokenInventadoNoSeDescarga()
    {
        $this->compraPagada($this->producto());

        $res = $this->descargar(str_repeat('0', 64));

        $this->assertStatus(404, $res);
        $this->assertNull($res->archivo);
        $this->assertSame(0, (int) $this->valor('SELECT SUM(descargas) FROM product_orders'));
    }

    public function testElTokenDeUnaOrdenSinPagarNoDescarga()
    {
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $res = $this->comprar($this->producto());

        $descarga = $this->descargar($res->body['token']);
        $info = CompraProductoHandler::descarga($this->db, $this->post(['token' => $res->body['token']]));

        $this->assertStatus(404, $descarga);
        $this->assertStatus(404, $info);
    }

    /** Cada token abre su producto y ningún otro. */
    public function testElTokenDeOtraOrdenBajaSuProductoYNoEste()
    {
        $guia = $this->producto('2500.00', 'Guía', 'contenido de la guía');
        $mapa = $this->producto('2500.00', 'Mapa', 'contenido del mapa');
        [, $tokenDeLaGuia] = $this->compraPagada($guia);

        $res = $this->descargar($tokenDeLaGuia);
        $rutaDelMapa = ProductosDigitales::rutaEnDisco($this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$mapa]));

        $this->assertNotSame($rutaDelMapa, $res->archivo);
        $this->assertSame('contenido de la guía', $this->disco->archivos[$res->archivo]);
    }

    public function testLaPantallaDeDescargaMuestraElProducto()
    {
        [, $token] = $this->compraPagada($this->producto());

        $res = CompraProductoHandler::descarga($this->db, $this->post(['token' => $token]));

        $this->assertStatus(200, $res);
        $this->assertSame('Guía de viaje', $res->body['producto']['titulo']);
        $this->assertSame('guía.pdf', $res->body['producto']['archivo_nombre']);
        $this->assertSame('https://rezonar.test/api/uploads/tapa.jpg', $res->body['producto']['imagen']);
    }

    /** Si quien vende reemplaza el archivo, el comprador baja el nuevo. */
    public function testSiElProductoSeReemplazaSeBajaElArchivoActual()
    {
        $linkId = $this->producto();
        [, $token] = $this->compraPagada($linkId);

        $subida = ProductosHandler::archivo($this->db, new Request('POST', [], ['link_id' => $linkId], $this->user($this->duenaId), [
            'archivo' => ['name' => 'guía-2027.pdf', 'type' => 'application/pdf', 'size' => 99, 'tmp_name' => '/tmp/nueva', 'error' => UPLOAD_ERR_OK],
        ]), $this->disco);
        $this->assertStatus(200, $subida);

        $res = $this->descargar($token);

        $this->assertStatus(200, $res);
        $this->assertSame('subido desde /tmp/nueva', $this->disco->archivos[$res->archivo]);
        $this->assertStringContainsString(rawurlencode('guía-2027.pdf'), $res->headers['Content-Disposition']);
    }

    // ============================================================== borrado

    public function testNoSePuedeBorrarUnProductoConVentasPagadas()
    {
        $linkId = $this->producto();
        $this->compraPagada($linkId);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(409, $res);
        $this->assertStringContainsString('ventas', $res->body['error']);
        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM links WHERE id = ?', [$linkId]));
        $this->assertSame([], $this->disco->borrados);
    }

    /** Lo que nunca se pagó no frena el borrado: se va con el producto. */
    public function testUnProductoConOrdenesSinPagarSePuedeBorrar()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $this->comprar($linkId);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    public function testNoSePuedeBorrarElGrupoNiLaPaginaDeUnProductoVendido()
    {
        $this->compraPagada($this->producto());

        $grupo = \GroupsHandler::detail($this->db, $this->delete(['id' => $this->grupoId], $this->user($this->duenaId)), $this->disco);
        $pagina = \PagesHandler::detail($this->db, $this->delete(['id' => $this->paginaId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(409, $grupo);
        $this->assertStatus(409, $pagina);
        $this->assertSame(1, $this->cantidadDeOrdenes());
    }

    // ================================================================= mail

    /** Si el SMTP estaba caído al pagar, el cron lo reintenta. */
    public function testElCronReintentaElMailQueNoSalio()
    {
        $this->mailer->fallarCon('SMTP caído');
        $res = $this->comprar($this->producto('0.00'));
        $orden = $this->orden($res->body['codigo']);
        $this->assertNull($orden['mail_enviado_en']);
        $this->assertSame('SMTP caído', $orden['mail_error']);

        $r = CorreoProductos::enviarPendientes($this->db, new FakeMailer());

        $this->assertSame(['enviados' => 1, 'fallidos' => 0], $r);
        $this->assertNotNull($this->orden($res->body['codigo'])['mail_enviado_en']);
    }
}
