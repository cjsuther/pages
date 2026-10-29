<?php

namespace Tests\Integration;

use Cobros;
use CompraProductoHandler;
use LinksHandler;
use PedidosHandler;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Las formas de entrega de un físico contra una MariaDB de verdad (#53):
 * el producto con envío, retiro y coordinar, y un pedido con retiro y otro
 * para coordinar, creados y acreditados. El retiro queda en la orden como
 * estaba al comprar.
 */
class FormasDeEntregaTest extends IntegracionTestCase
{
    const DIRECCION = 'Av. Corrientes 1234, C1043 CABA, Argentina';
    const MAPS = 'https://maps.google.com/?cid=123';
    const INDICACIONES = 'Lunes a viernes de 10 a 18, timbre 3';

    private $duenaId;
    private $paginaId;
    private $grupoId;
    /** @var FakeHttpClient */
    private $http;
    /** @var FakeMailer */
    private $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId, 'productos', 'Tienda');
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();

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

    /** Un disco con las tres formas, envío de $700 y stock 10. */
    private function disco($precio = '3000')
    {
        $r = LinksHandler::index($this->db, $this->post([
            'group_id' => $this->grupoId, 'url' => '', 'text' => 'Disco', 'tipo' => 'fisico',
            'precio' => $precio, 'costo_envio' => '700', 'stock' => '10',
            'entrega_envio' => true, 'entrega_retiro' => true, 'entrega_coordinar' => true,
            'retiro_direccion' => self::DIRECCION, 'retiro_latitud' => -34.6037, 'retiro_longitud' => -58.3816,
            'retiro_maps_url' => self::MAPS, 'retiro_indicaciones' => self::INDICACIONES,
        ], $this->user($this->duenaId)));
        $this->assertStatus(201, $r);

        return (int) $r->body['link']['id'];
    }

    private function comprar($linkId, $forma, array $extra = [])
    {
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);

        return CompraProductoHandler::comprar($this->db, $this->post(array_merge([
            'link_id'       => $linkId,
            'nombre'        => 'Ana Compradora',
            'email'         => 'ana@test.local',
            'telefono'      => '11 5555-1234',
            'cantidad'      => 2,
            'forma_entrega' => $forma,
        ], $extra)), $this->http, $this->mailer);
    }

    private function acreditar($codigo, $total, $pagoId)
    {
        $this->http->responde('/v1/payments/', 200, [
            'id' => $pagoId, 'status' => 'approved', 'external_reference' => $codigo, 'transaction_amount' => $total,
        ]);

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

    // ================================================================ tests

    public function testElProductoGuardaSusFormasYElPuntoDeRetiro()
    {
        $linkId = $this->disco();

        $fila = $this->fila('SELECT * FROM digital_products WHERE link_id = ?', [$linkId]);
        $this->assertSame(['1', '1', '1'], [(string) $fila['entrega_envio'], (string) $fila['entrega_retiro'], (string) $fila['entrega_coordinar']]);
        $this->assertSame(self::DIRECCION, $fila['retiro_direccion']);
        $this->assertSame('-34.60370000', $fila['retiro_latitud']);
        $this->assertSame('-58.38160000', $fila['retiro_longitud']);
        $this->assertSame(self::MAPS, $fila['retiro_maps_url']);
        $this->assertSame(self::INDICACIONES, $fila['retiro_indicaciones']);

        // Destildar todo es un error y no cambia nada.
        $r = LinksHandler::detail($this->db, $this->put(
            ['entrega_envio' => false, 'entrega_retiro' => false, 'entrega_coordinar' => false],
            $this->user($this->duenaId),
            ['id' => $linkId]
        ));
        $this->assertStatus(400, $r);
        $this->assertSame('1', (string) $this->valor('SELECT entrega_envio FROM digital_products WHERE link_id = ?', [$linkId]));
    }

    /** Un físico creado sin decir nada queda con sólo envío, como los de antes. */
    public function testUnFisicoSinFormasQuedaConSoloEnvio()
    {
        $r = LinksHandler::index($this->db, $this->post([
            'group_id' => $this->grupoId, 'url' => '', 'text' => 'Remera', 'tipo' => 'fisico', 'precio' => '100',
        ], $this->user($this->duenaId)));

        $fila = $this->fila('SELECT * FROM digital_products WHERE link_id = ?', [(int) $r->body['link']['id']]);
        $this->assertSame(['1', '0', '0'], [(string) $fila['entrega_envio'], (string) $fila['entrega_retiro'], (string) $fila['entrega_coordinar']]);
    }

    public function testUnPedidoConRetiroSeCreaSinEnvioYSeAcreditaConLaFotoDelRetiro()
    {
        $linkId = $this->disco();

        // La dirección y los horarios que lleguen se ignoran.
        $compra = $this->comprar($linkId, 'retiro', [
            'envio'    => ['calle' => 'Av. Siempreviva 742', 'localidad' => 'X', 'provincia' => 'Buenos Aires', 'codigo_postal' => '1414'],
            'horarios' => ['dias' => ['lunes'], 'franjas' => ['tarde']],
        ]);
        $this->assertStatus(201, $compra);
        $codigo = $compra->body['codigo'];
        $this->assertSame(6000.0, $compra->body['total']);

        $orden = $this->orden($codigo);
        $this->assertSame('retiro', $orden['forma_entrega']);
        $this->assertSame('6000.00', $orden['total']);
        $this->assertSame('0.00', $orden['costo_envio']);
        $this->assertSame([self::DIRECCION, self::MAPS, self::INDICACIONES],
            [$orden['retiro_direccion'], $orden['retiro_maps_url'], $orden['retiro_indicaciones']]);
        $this->assertFalse($this->valor('SELECT order_id FROM product_order_envios WHERE order_id = ?', [$orden['id']]));
        $this->assertSame(['Disco'], array_column($this->http->jsonDe('/checkout/preferences')['items'], 'title'));

        $this->acreditar($codigo, 6000.0, '778001');

        $orden = $this->orden($codigo);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame('ingresado', $orden['estado_pedido']);
        $this->assertSame(8, (int) $this->valor('SELECT stock FROM digital_products WHERE link_id = ?', [$linkId]));
        $this->assertStringContainsString('Lo retirás en ' . self::DIRECCION, $this->mailer->mensajePara('ana@test.local')['texto']);

        // Si el producto cambia de lugar, el pedido no.
        $r = LinksHandler::detail($this->db, $this->put(
            ['retiro_direccion' => 'Otro lugar 99', 'retiro_maps_url' => 'https://maps.google.com/?cid=9'],
            $this->user($this->duenaId),
            ['id' => $linkId]
        ));
        $this->assertStatus(200, $r);

        $publica = CompraProductoHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http)->body['orden'];
        $this->assertSame('retiro', $publica['forma_entrega']);
        $this->assertSame(self::DIRECCION, $publica['retiro']['direccion']);
        $this->assertSame(self::MAPS, $publica['retiro']['maps_url']);
        $this->assertNull($publica['envio']);
        $this->assertSame('Con fecha de retiro', $publica['linea_de_tiempo'][1]['etiqueta']);

        // Quien vende lo pasa a Listo para retirar: sin empresa ni seguimiento.
        $r = PedidosHandler::estado($this->db, $this->post(
            ['codigo' => $codigo, 'estado' => 'enviado', 'empresa' => 'Andreani'],
            $this->user($this->duenaId)
        ), $this->mailer);
        $this->assertStatus(200, $r);
        $this->assertNull($this->orden($codigo)['envio_empresa']);
        $this->assertSame('Listo para retirar', end($r->body['pedido']['historial'])['etiqueta']);
    }

    public function testUnPedidoParaCoordinarSeCreaYSeAcredita()
    {
        $linkId = $this->disco();

        $compra = $this->comprar($linkId, 'coordinar');
        $this->assertStatus(201, $compra);
        $codigo = $compra->body['codigo'];

        $orden = $this->orden($codigo);
        $this->assertSame('coordinar', $orden['forma_entrega']);
        $this->assertSame('6000.00', $orden['total']);
        $this->assertNull($orden['retiro_direccion']);

        $this->acreditar($codigo, 6000.0, '778101');

        $this->assertSame('pagada', $this->orden($codigo)['estado']);
        $this->assertStringContainsString(
            'Quien vende te va a contactar al 11 5555-1234',
            $this->mailer->mensajePara('ana@test.local')['texto']
        );
        $this->assertStringContainsString(
            'Coordiná la entrega con Ana Compradora al 11 5555-1234',
            $this->mailer->mensajePara('duena@test.local')['texto']
        );

        $ventas = $this->get(['page_id' => $this->paginaId], $this->user($this->duenaId));
        $ordenes = \ProductosHandler::ventas($this->db, $ventas)->body['ordenes'];
        $this->assertSame('coordinar', $ordenes[0]['forma_entrega']);
    }

    /** Con envío, se cobra el envío como hasta ahora. */
    public function testUnPedidoConEnvioSumaElEnvio()
    {
        $linkId = $this->disco();

        $compra = $this->comprar($linkId, 'envio', [
            'envio'    => ['calle' => 'Av. Siempreviva 742', 'localidad' => 'Springfield', 'provincia' => 'Buenos Aires', 'codigo_postal' => '1414'],
            'horarios' => ['dias' => ['lunes'], 'franjas' => ['tarde']],
        ]);

        $this->assertSame(6700.0, $compra->body['total']);
        $this->assertSame('envio', $this->orden($compra->body['codigo'])['forma_entrega']);
    }

    /** Producto gratis y retiro: total 0, no pasa por Mercado Pago. */
    public function testUnPedidoGratisConRetiroNacePagado()
    {
        $linkId = $this->disco('0');

        $r = $this->comprar($linkId, 'retiro');

        $this->assertStatus(201, $r);
        $orden = $this->orden($r->body['codigo']);
        $this->assertSame(['pagada', 'ingresado', '0.00'], [$orden['estado'], $orden['estado_pedido'], $orden['total']]);
        $this->assertFalse($this->http->llamoA('/checkout/preferences'));
    }

    /** La migración deja como envío los pedidos de físicos que ya existían. */
    public function testLaMigracionDejaComoEnvioLosPedidosViejos()
    {
        $linkId = $this->disco();
        $codigo = $this->comprar($linkId, 'coordinar')->body['codigo'];
        $this->db->exec("UPDATE product_orders SET forma_entrega = NULL");

        $this->db->exec(file_get_contents(dirname(__DIR__, 3) . '/migration_formas_de_entrega.sql'));

        $this->assertSame('envio', $this->orden($codigo)['forma_entrega']);
    }
}
