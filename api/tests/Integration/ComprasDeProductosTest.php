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
            'link_id' => $linkId, 'titulo' => $titulo, 'page_id' => $this->paginaId,
            'precio' => $precio, 'archivo_ruta' => $ruta,
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
            'codigo', 'product_id', 'nombre', 'email', 'telefono', 'ip', 'total', 'comision', 'comision_porcentaje',
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

    /**
     * Un producto con una venta pagada y otra pendiente (otro comprador, que
     * quedó en el checkout de Mercado Pago).
     *
     * @return array{linkId: int, pagada: array, pendiente: array, ruta: string}
     */
    private function productoConPagadaYPendiente()
    {
        $linkId = $this->producto();
        [$codigoPagada, $tokenPagada] = $this->compraPagada($linkId);

        $this->preferenciaOk();
        $pendiente = $this->comprar($linkId, ['nombre' => 'Beto Pendiente', 'email' => 'beto@test.local']);
        $this->assertStatus(201, $pendiente);

        return [
            'linkId'    => $linkId,
            'pagada'    => ['codigo' => $codigoPagada, 'token' => $tokenPagada],
            'pendiente' => ['codigo' => $pendiente->body['codigo'], 'token' => $pendiente->body['token']],
            'ruta'      => $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]),
        ];
    }

    /** Lo que tiene que quedar en pie después de borrar el producto por cualquier camino. */
    private function assertLoVendidoSobrevive(array $caso)
    {
        $this->assertSame(2, $this->cantidadDeOrdenes());
        $this->assertSame('pagada', $this->orden($caso['pagada']['codigo'])['estado']);
        $this->assertSame('pendiente', $this->orden($caso['pendiente']['codigo'])['estado']);

        $producto = $this->fila('SELECT * FROM digital_products');
        $this->assertNotFalse($producto);
        $this->assertNull($producto['link_id']);
        $this->assertNotNull($producto['borrado_en']);
        $this->assertSame((int) $caso['linkId'], (int) $producto['link_original']);
        $this->assertSame('Guía de viaje', $producto['titulo']);
        $this->assertSame($caso['ruta'], $producto['archivo_ruta']);

        // El archivo sigue en disco y quien pagó lo baja.
        $this->assertSame([], $this->disco->borrados);
        $res = $this->descargar($caso['pagada']['token']);
        $this->assertStatus(200, $res);
        $this->assertSame('PDF de la guía', $this->disco->archivos[$res->archivo]);

        // La pantalla de descarga funciona sin el link: el título sale de la foto.
        $pantalla = CompraProductoHandler::descarga($this->db, $this->post(['token' => $caso['pagada']['token']]));
        $this->assertStatus(200, $pantalla);
        $this->assertSame('Guía de viaje', $pantalla->body['producto']['titulo']);
        $this->assertNull($pantalla->body['producto']['imagen']);

        // Y no acepta pedidos nuevos.
        $nuevo = $this->comprar($caso['linkId'], ['email' => 'carla@test.local']);
        $this->assertStatus(409, $nuevo);
        $this->assertStringContainsString('ya no está disponible', $nuevo->body['error']);
        $this->assertSame(2, $this->cantidadDeOrdenes());
    }

    /** La pendiente se acredita después con el aviso de Mercado Pago, y descarga. */
    private function assertLaPendienteSeAcreditaPorAviso(array $caso)
    {
        $codigo = $caso['pendiente']['codigo'];
        $this->pagoDeMercadoPago($codigo, 2500.0, 'approved', ['id' => 555002]);
        $mails = count($this->mailer->enviados);

        $r = $this->aviso($codigo, '555002');

        $this->assertSame('pago acreditado', $r->body['motivo']);
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
        $this->assertCount($mails + 1, $this->mailer->enviados);
        $this->assertStringContainsString('Guía de viaje', end($this->mailer->enviados)['asunto']);
        $this->assertStatus(200, $this->descargar($caso['pendiente']['token']));

        // Y la pantalla de vuelta muestra el producto borrado.
        $vuelta = CompraProductoHandler::orden($this->db, new Request('GET', [], ['codigo' => $codigo]), $this->http);
        $this->assertSame('pagada', $vuelta->body['orden']['estado']);
        $this->assertSame('Guía de viaje', $vuelta->body['orden']['producto']);
    }

    public function testBorrarUnProductoConVentasConservaOrdenesFilaYArchivo()
    {
        $caso = $this->productoConPagadaYPendiente();

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $caso['linkId']], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM links WHERE id = ?', [$caso['linkId']]));
        $this->assertLoVendidoSobrevive($caso);
        $this->assertLaPendienteSeAcreditaPorAviso($caso);
    }

    public function testBorrarElGrupoDeUnProductoConVentasConservaLoVendido()
    {
        $caso = $this->productoConPagadaYPendiente();

        $res = \GroupsHandler::detail($this->db, $this->delete(['id' => $this->grupoId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM link_groups WHERE id = ?', [$this->grupoId]));
        $this->assertLoVendidoSobrevive($caso);
        $this->assertSame((int) $this->paginaId, (int) $this->valor('SELECT page_id FROM digital_products'));
        $this->assertLaPendienteSeAcreditaPorAviso($caso);
    }

    /**
     * Borrar la página entera también conserva lo vendido. Lo que no se puede
     * es verificar un pago con Mercado Pago: sus credenciales se borran con
     * la página (page_payment_settings, ON DELETE CASCADE). La pendiente queda
     * guardada y se puede acreditar a mano (acreditarPago); el aviso no rompe.
     */
    public function testBorrarLaPaginaDeUnProductoConVentasConservaLoVendido()
    {
        $caso = $this->productoConPagadaYPendiente();

        $res = \PagesHandler::detail($this->db, $this->delete(['id' => $this->paginaId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM pages WHERE id = ?', [$this->paginaId]));
        $this->assertLoVendidoSobrevive($caso);
        $this->assertNull($this->valor('SELECT page_id FROM digital_products'));

        $codigo = $caso['pendiente']['codigo'];
        $this->pagoDeMercadoPago($codigo, 2500.0, 'approved', ['id' => 555002]);
        $aviso = $this->aviso($codigo, '555002');
        $this->assertStatus(200, $aviso);
        $this->assertSame('la página no tiene credenciales', $aviso->body['motivo']);

        $r = OrdenesDeProductos::acreditarPago($this->db, $codigo, '555002', 'approved');
        $this->assertTrue($r['acreditada']);
        $this->assertSame(['enviado' => true, 'motivo' => 'link enviado'], CorreoProductos::enviar($this->db, $codigo, $this->mailer));
        $this->assertStatus(200, $this->descargar($caso['pendiente']['token']));
    }

    /** Un producto sin ninguna orden se borra entero, con su archivo. */
    public function testUnProductoSinOrdenesSeBorraConSuArchivo()
    {
        $linkId = $this->producto();
        $ruta = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM digital_products'));
        $this->assertSame([ProductosDigitales::rutaEnDisco($ruta)], $this->disco->borrados);
    }

    /** En un grupo con productos vendidos y sin vender, cada uno sigue su regla. */
    public function testElGrupoBorraLosProductosSinOrdenesYConservaLosVendidos()
    {
        $vendido = $this->producto();
        $this->compraPagada($vendido);
        $sinVender = $this->producto('100.00', 'Otro');
        $rutaSinVender = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$sinVender]);

        $res = \GroupsHandler::detail($this->db, $this->delete(['id' => $this->grupoId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM digital_products'));
        $this->assertSame('Guía de viaje', $this->valor('SELECT titulo FROM digital_products'));
        $this->assertSame([ProductosDigitales::rutaEnDisco($rutaSinVender)], $this->disco->borrados);
    }

    /** Si sólo hay órdenes canceladas nadie puede descargar: la fila queda, el archivo no. */
    public function testConSoloOrdenesCanceladasSeBorraElArchivoPeroNoLaOrden()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        // Sin preferencia: falla Mercado Pago y la orden queda cancelada.
        $this->assertStatus(502, $this->comprar($linkId));
        $ruta = $this->valor('SELECT archivo_ruta FROM digital_products WHERE link_id = ?', [$linkId]);

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)), $this->disco);

        $this->assertStatus(200, $res);
        $this->assertSame(1, $this->cantidadDeOrdenes());
        $this->assertNull($this->valor('SELECT archivo_ruta FROM digital_products'));
        $this->assertSame([ProductosDigitales::rutaEnDisco($ruta)], $this->disco->borrados);
    }

    /** La base tampoco deja borrar a mano un producto con órdenes, ni en cascada. */
    public function testLaBaseNoBorraUnProductoConOrdenes()
    {
        $linkId = $this->producto();
        $this->compraPagada($linkId);

        // Borrar el link a mano (sin la API) no se lleva ni la fila ni la orden.
        $this->db->prepare('DELETE FROM links WHERE id = ?')->execute([$linkId]);
        $this->assertSame(1, $this->cantidadDeOrdenes());
        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM digital_products'));

        $this->expectException(\PDOException::class);
        $this->db->exec('DELETE FROM digital_products');
    }

    /** Lo que borró otra persona no se toca: ni la página ni sus productos. */
    public function testOtraPersonaNoBorraLaPaginaNiSusProductos()
    {
        $linkId = $this->producto();
        $otra = $this->usuario('otra@test.local', 'Otra');

        $res = \PagesHandler::detail($this->db, $this->delete(['id' => $this->paginaId], $this->user($otra, 'otra@test.local')), $this->disco);

        $this->assertStatus(404, $res);
        $this->assertSame((int) $linkId, (int) $this->valor('SELECT link_id FROM digital_products'));
        $this->assertSame([], $this->disco->borrados);
    }

    // =============================================================== oculto

    private function ocultar($linkId, $oculto = true)
    {
        return LinksHandler::detail($this->db, $this->put(['oculto' => $oculto], $this->user($this->duenaId), ['id' => $linkId]));
    }

    public function testUnProductoOcultoNoAceptaPedidosNuevos()
    {
        $pago = $this->producto();
        $gratis = $this->producto('0.00', 'Gratis');
        $this->conectarPorOAuth();

        $this->assertStatus(200, $this->ocultar($pago));
        $this->assertStatus(200, $this->ocultar($gratis));
        $this->assertSame('1', (string) $this->valor('SELECT oculto FROM digital_products WHERE link_id = ?', [$pago]));

        foreach ([$pago, $gratis] as $linkId) {
            $res = $this->comprar($linkId);
            $this->assertStatus(409, $res);
            $this->assertSame('Este producto ya no está disponible.', $res->body['error']);
        }

        $this->assertSame(0, $this->cantidadDeOrdenes());
        $this->assertSame([], $this->mailer->enviados);
        $this->assertSame([], $this->http->llamadas);

        // Al volver a mostrarlo, acepta pedidos otra vez.
        $this->assertStatus(200, $this->ocultar($gratis, false));
        $this->assertStatus(201, $this->comprar($gratis));
    }

    /** Ocultar no le quita nada a quien ya compró ni a quien está pagando. */
    public function testOcultarNoFrenaLoYaPedido()
    {
        $caso = $this->productoConPagadaYPendiente();

        $this->assertStatus(200, $this->ocultar($caso['linkId']));

        $this->assertStatus(200, $this->descargar($caso['pagada']['token']));
        $this->pagoDeMercadoPago($caso['pendiente']['codigo'], 2500.0, 'approved', ['id' => 555002]);
        $this->assertSame('pago acreditado', $this->aviso($caso['pendiente']['codigo'], '555002')->body['motivo']);
        $this->assertStatus(200, $this->descargar($caso['pendiente']['token']));
    }

    /** El editor ve si está oculto, y la página pública (parte 3) lo deja afuera. */
    public function testElEditorVeElOcultoYLosPublicosNoLoIncluyen()
    {
        $visible = $this->producto();
        $oculto = $this->producto('100.00', 'Oculto');
        $this->ocultar($oculto);

        $editor = ProductosDigitales::delGrupo($this->db, $this->grupoId);
        $publicos = ProductosDigitales::publicosDelGrupo($this->db, $this->grupoId);

        $this->assertSame(['0', '1'], array_map(function ($p) { return (string) $p['oculto']; }, $editor));
        $this->assertSame([(int) $visible], array_map(function ($p) { return (int) $p['id']; }, $publicos));
        $this->assertArrayNotHasKey('archivo_ruta', $publicos[0]);
    }

    /** El título queda copiado en el producto al crearlo y al editarlo. */
    public function testElTituloYLaPaginaQuedanCopiadosEnElProducto()
    {
        $res = LinksHandler::index($this->db, $this->post([
            'group_id' => $this->grupoId, 'url' => 'x', 'text' => 'Pack de fotos', 'precio' => '900',
        ], $this->user($this->duenaId)));
        $this->assertStatus(201, $res);
        $linkId = $res->body['link']['id'];

        $this->assertSame('Pack de fotos', $this->valor('SELECT titulo FROM digital_products WHERE link_id = ?', [$linkId]));
        $this->assertSame((int) $this->paginaId, (int) $this->valor('SELECT page_id FROM digital_products WHERE link_id = ?', [$linkId]));

        LinksHandler::detail($this->db, $this->put(['text' => 'Pack de fotos 2'], $this->user($this->duenaId), ['id' => $linkId]));
        $this->assertSame('Pack de fotos 2', $this->valor('SELECT titulo FROM digital_products WHERE link_id = ?', [$linkId]));
    }

    // ======================================================= límite gratis

    private function pedirGratisDesde($linkId, $email, $ip)
    {
        $req = $this->post(['link_id' => $linkId, 'nombre' => 'Ana', 'email' => $email]);
        $req->ip = $ip;

        return CompraProductoHandler::comprar($this->db, $req, $this->http, $this->mailer);
    }

    public function testCincoPedidosGratisPorHoraPorIp()
    {
        $a = $this->producto('0.00', 'A');
        $b = $this->producto('0.00', 'B');

        for ($i = 1; $i <= 5; $i++) {
            $this->assertStatus(201, $this->pedirGratisDesde($i % 2 ? $a : $b, "persona$i@test.local", '198.51.100.7'));
        }

        $res = $this->pedirGratisDesde($a, 'persona6@test.local', '198.51.100.7');
        $this->assertStatus(429, $res);
        $this->assertStringContainsString('demasiados pedidos', $res->body['error']);
        $this->assertSame(5, $this->cantidadDeOrdenes());
        $this->assertCount(5, $this->mailer->enviados);

        // Otra IP sigue pudiendo.
        $this->assertStatus(201, $this->pedirGratisDesde($a, 'persona6@test.local', '198.51.100.8'));

        // Y lo de hace más de una hora no cuenta.
        $this->db->exec('UPDATE product_orders SET creado_en = NOW() - INTERVAL 2 HOUR');
        $this->assertStatus(201, $this->pedirGratisDesde($a, 'persona7@test.local', '198.51.100.7'));
    }

    public function testTresPedidosGratisPorHoraPorEmailEntreTodosLosProductos()
    {
        $productos = [$this->producto('0.00', 'A'), $this->producto('0.00', 'B'), $this->producto('0.00', 'C'), $this->producto('0.00', 'D')];

        foreach (array_slice($productos, 0, 3) as $i => $linkId) {
            $this->assertStatus(201, $this->pedirGratisDesde($linkId, 'ana@test.local', '198.51.100.' . $i));
        }

        $res = $this->pedirGratisDesde($productos[3], 'ANA@test.local ', '198.51.100.9');
        $this->assertStatus(429, $res);
        $this->assertSame(3, $this->cantidadDeOrdenes());
        $this->assertSame(3, (int) $this->valor("SELECT COUNT(*) FROM product_orders WHERE email = 'ana@test.local'"));
        $this->assertSame('198.51.100.0', $this->valor('SELECT ip FROM product_orders ORDER BY id LIMIT 1'));
    }

    // ============================================== conciliación / códigos

    /** Una rechazada cuyo segundo intento aprobado no avisó se acredita en la conciliación. */
    public function testLaConciliacionAcreditaUnaRechazadaConUnAprobadoPosterior()
    {
        $linkId = $this->producto();
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago($codigo, 2500.0, 'rejected', ['id' => 1]);
        $this->aviso($codigo, '1');
        $this->assertSame('rechazada', $this->orden($codigo)['estado']);

        $this->assertSame([$codigo], OrdenesDeProductos::sinConciliar($this->db));

        $this->http->responde('/v1/payments/search', 200, ['results' => [
            ['id' => 2, 'status' => 'approved', 'external_reference' => $codigo, 'transaction_amount' => 2500.0],
            ['id' => 1, 'status' => 'rejected', 'external_reference' => $codigo, 'transaction_amount' => 2500.0],
        ]]);
        $r = CompraProductoHandler::conciliar($this->db, $codigo, $this->http, $this->mailer);

        $this->assertTrue($r['acreditada']);
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
        $this->assertSame('2', $this->orden($codigo)['mp_payment_id']);
        $this->assertSame([], OrdenesDeProductos::sinConciliar($this->db));

        // Fuera de la ventana, una rechazada ya no se revisa.
        $this->db->exec("UPDATE product_orders SET estado = 'rechazada', creado_en = NOW() - INTERVAL 61 DAY");
        $this->assertSame([], OrdenesDeProductos::sinConciliar($this->db));
    }

    /** La verificación del código corre sobre las dos tablas, para entradas y para productos. */
    public function testElCodigoLibreSeVerificaContraLasDosTablas()
    {
        $codigo = $this->comprar($this->producto('0.00'))->body['codigo'];

        $stmt = $this->db->prepare('
            SELECT 1 FROM ticket_orders WHERE codigo = ?
            UNION ALL
            SELECT 1 FROM product_orders WHERE codigo = ?
        ');
        $stmt->execute([$codigo, $codigo]);
        $this->assertSame('1', (string) $stmt->fetchColumn());

        foreach (['ticket_orders', 'product_orders'] as $tabla) {
            $nuevo = \Entradas::codigoLibre($this->db, $tabla);
            $this->assertMatchesRegularExpression('/^[A-F0-9]{12}$/', $nuevo);
            $this->assertNotSame($codigo, $nuevo);
        }
    }

    /** Un campo más largo que su columna es un 400, no un 500 de la base. */
    public function testLosCamposLargosDan400()
    {
        $linkId = $this->producto('0.00');

        $this->assertStatus(400, $this->comprar($linkId, ['nombre' => str_repeat('x', 151)]));
        $this->assertStatus(400, $this->comprar($linkId, ['telefono' => str_repeat('1', 51)]));
        $this->assertStatus(400, $this->comprar($linkId, ['email' => str_repeat('a', 250) . '@test.local']));
        $this->assertSame(0, $this->cantidadDeOrdenes());
        $this->assertStatus(201, $this->comprar($linkId, ['nombre' => str_repeat('ñ', 150)]));
    }

    // ========================================================= ventas (#19)

    private function ventas(array $query, $userId = null)
    {
        return ProductosHandler::ventas($this->db, $this->get($query, $this->user($userId ?: $this->duenaId)));
    }

    public function testElOrganizadorVeQuienDescargoYCuantasVeces()
    {
        $linkId = $this->producto();
        [$codigo, $token] = $this->compraPagada($linkId);

        $antes = $this->ventas(['link_id' => $linkId]);
        $this->assertStatus(200, $antes);
        $this->assertSame(0, $antes->body['ordenes'][0]['descargas']);
        $this->assertNull($antes->body['ordenes'][0]['primera_descarga_en']);
        $this->assertSame(['pagadas' => 1, 'recaudado' => 2500.0, 'descargaron' => 0], $antes->body['resumen']);

        $this->descargar($token);
        $despues = $this->ventas(['link_id' => $linkId]);

        $venta = $despues->body['ordenes'][0];
        $this->assertSame($codigo, $venta['codigo']);
        $this->assertSame('ana@test.local', $venta['email']);
        $this->assertSame(1, $venta['descargas']);
        $this->assertNotNull($venta['primera_descarga_en']);
        $this->assertSame(1, $despues->body['resumen']['descargaron']);

        // Ni el token ni el link de descarga, en ninguna forma.
        $json = json_encode($despues->body);
        $this->assertStringNotContainsString($token, $json);
        $this->assertStringNotContainsString(hash('sha256', $token), $json);
        $this->assertStringNotContainsString('descarga#', $json);
    }

    /** Las ventas de un producto son sólo las suyas. */
    public function testLasVentasDeUnProductoNoMezclanLasDeOtro()
    {
        $guia = $this->producto();
        $mapa = $this->producto('0.00', 'Mapa');
        $this->compraPagada($guia);
        $this->comprar($mapa, ['email' => 'beto@test.local']);

        $this->assertSame(['Guía de viaje'], array_column($this->ventas(['link_id' => $guia])->body['ordenes'], 'producto'));
        $this->assertSame(['Mapa'], array_column($this->ventas(['link_id' => $mapa])->body['ordenes'], 'producto'));
        $this->assertCount(2, $this->ventas(['page_id' => $this->paginaId])->body['ordenes']);
    }

    public function testOtraPersonaSinAccesoRecibe403EnLasVentas()
    {
        $linkId = $this->producto();
        $this->compraPagada($linkId);
        $otra = $this->usuario('otra@test.local', 'Otra');

        $this->assertStatus(403, $this->ventas(['link_id' => $linkId], $otra));
        $this->assertStatus(403, $this->ventas(['page_id' => $this->paginaId], $otra));
        $this->assertStatus(403, $this->ventas(['page_id' => $this->paginaId, 'formato' => 'excel'], $otra));
    }

    /** Un administrador aceptado de la página sí las ve. */
    public function testUnAdministradorDeLaPaginaVeLasVentas()
    {
        $linkId = $this->producto();
        $this->compraPagada($linkId);
        $admin = $this->usuario('admin@test.local', 'Admin');
        $this->insertar('page_admins', ['page_id' => $this->paginaId, 'user_id' => $admin, 'status' => 'accepted', 'invited_by' => $this->duenaId]);

        $this->assertStatus(200, $this->ventas(['link_id' => $linkId], $admin));
    }

    /** El producto borrado ya no tiene link: sus ventas siguen en la vista de la página. */
    public function testLaVistaPorPaginaMuestraLasVentasDeUnProductoBorrado()
    {
        $linkId = $this->producto();
        [$codigo] = $this->compraPagada($linkId);
        LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)), $this->disco);

        $res = $this->ventas(['page_id' => $this->paginaId]);

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['ordenes']);
        $venta = $res->body['ordenes'][0];
        $this->assertSame($codigo, $venta['codigo']);
        $this->assertSame('Guía de viaje', $venta['producto']);
        $this->assertTrue($venta['producto_borrado']);
        $this->assertNull($venta['link_id']);
        $this->assertSame(1, $res->body['resumen']['pagadas']);

        // Y la de otra página no ve nada.
        $otra = $this->usuario('otra@test.local', 'Otra');
        $suPagina = $this->pagina($otra, 'otra-sala', 'Otra Sala');
        $this->assertSame([], $this->ventas(['page_id' => $suPagina], $otra)->body['ordenes']);
    }

    public function testElReenvioMandaElMismoLinkDeLaCompra()
    {
        $linkId = $this->producto();
        [$codigo, $token] = $this->compraPagada($linkId);
        $this->db->exec('UPDATE product_orders SET mail_enviado_en = NOW() - INTERVAL 1 HOUR');
        $mailer = new FakeMailer();

        $res = ProductosHandler::reenviar($this->db, $this->post(['codigo' => $codigo], $this->user($this->duenaId)), $mailer);

        $this->assertStatus(200, $res);
        $this->assertCount(1, $mailer->enviados);
        $this->assertStringContainsString('/descarga#' . $token, $mailer->enviados[0]['html']);
        $this->assertStringNotContainsString($token, json_encode($res->body));
        $this->assertSame(1, $this->cantidadDeOrdenes());

        // Recién mandado, no se vuelve a mandar.
        $this->assertStatus(429, ProductosHandler::reenviar($this->db, $this->post(['codigo' => $codigo], $this->user($this->duenaId)), $mailer));
        $this->assertCount(1, $mailer->enviados);
    }

    public function testElReenvioFuncionaConElProductoBorradoYNoParaOtraPersona()
    {
        $linkId = $this->producto();
        [$codigo] = $this->compraPagada($linkId);
        LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)), $this->disco);
        $this->db->exec('UPDATE product_orders SET mail_enviado_en = NOW() - INTERVAL 1 HOUR');
        $otra = $this->usuario('otra@test.local', 'Otra');
        $mailer = new FakeMailer();

        $ajena = ProductosHandler::reenviar($this->db, $this->post(['codigo' => $codigo], $this->user($otra, 'otra@test.local')), $mailer);
        $propia = ProductosHandler::reenviar($this->db, $this->post(['codigo' => $codigo], $this->user($this->duenaId)), $mailer);

        $this->assertStatus(403, $ajena);
        $this->assertStatus(200, $propia);
        $this->assertCount(1, $mailer->enviados);
    }

    public function testUnaCompraSinPagarNoSeReenvia()
    {
        $this->conectarPorOAuth();
        $this->preferenciaOk();
        $codigo = $this->comprar($this->producto())->body['codigo'];

        $res = ProductosHandler::reenviar($this->db, $this->post(['codigo' => $codigo], $this->user($this->duenaId)), $this->mailer);

        $this->assertStatus(409, $res);
        $this->assertSame([], $this->mailer->enviados);
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
