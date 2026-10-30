<?php

namespace Tests\Integration;

use Cobros;
use CompraProductoHandler;
use LinksHandler;
use OrdenesDeProductos;
use PDOException;
use Tests\Support\BaseDescartable;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * La compra de un físico contra una MariaDB de verdad (#43): la orden con su
 * foto y su envío, y el stock que se descuenta al acreditar, dentro de la
 * misma transacción que marca la orden pagada.
 */
class PedidosFisicosTest extends IntegracionTestCase
{
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

    // ============================================================ fixtures

    /** Una remera Talle (S, M) / Color (Negro), con stock de M/Negro. */
    private function remera($stockMNegro, $precio = '2500', $envio = '500')
    {
        $r = LinksHandler::index($this->db, $this->post([
            'group_id' => $this->grupoId, 'url' => '', 'text' => 'Remera', 'tipo' => 'fisico',
            'precio' => $precio, 'costo_envio' => $envio,
            'caracteristicas' => [
                ['nombre' => 'Talle', 'opciones' => ['S', 'M']],
                ['nombre' => 'Color', 'opciones' => ['Negro']],
            ],
            'variantes' => [['opciones' => ['M', 'Negro'], 'stock' => $stockMNegro]],
        ], $this->user($this->duenaId)));
        $this->assertStatus(201, $r);

        return (int) $r->body['link']['id'];
    }

    private function opcion($nombre)
    {
        return (int) $this->valor('SELECT id FROM product_attribute_options WHERE nombre = ?', [$nombre]);
    }

    private function comprar($linkId, $cantidad, $email = 'ana@test.local')
    {
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);

        return CompraProductoHandler::comprar($this->db, $this->post([
            'link_id'  => $linkId,
            'nombre'   => 'Ana Compradora',
            'email'    => $email,
            'telefono' => '11 5555-1234',
            'cantidad' => $cantidad,
            'opciones' => [$this->opcion('M'), $this->opcion('Negro')],
            'envio'    => [
                'calle' => 'Av. Corrientes 1234', 'localidad' => 'CABA',
                'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => '1043',
            ],
            'horarios' => ['dias' => ['lunes'], 'franjas' => ['tarde']],
        ]), $this->http, $this->mailer);
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

    private function stockMNegro()
    {
        return (int) $this->valor('SELECT stock FROM product_variants WHERE clave = ?', [
            min($this->opcion('M'), $this->opcion('Negro')) . '-' . max($this->opcion('M'), $this->opcion('Negro')),
        ]);
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM product_orders WHERE codigo = ?', [$codigo]);
    }

    // ================================================================ tests

    public function testLaCompraGuardaLaFotoYElEnvioYAlAcreditarDescuentaElStock()
    {
        $linkId = $this->remera(5);

        $compra = $this->comprar($linkId, 2);
        $this->assertStatus(201, $compra);
        $codigo = $compra->body['codigo'];

        $orden = $this->orden($codigo);
        $this->assertSame('pendiente', $orden['estado']);
        $this->assertSame('5500.00', $orden['total']);
        $this->assertSame('Remera', $orden['producto_titulo']);
        $this->assertSame('Talle M · Color Negro', $orden['opciones_texto']);
        $this->assertSame(2, (int) $orden['cantidad']);
        $this->assertSame('2500.00', $orden['precio_unitario']);
        $this->assertSame('500.00', $orden['costo_envio']);
        $this->assertNotNull($orden['variante_id']);
        $this->assertSame('2', (string) $this->valor('SELECT COUNT(*) FROM product_order_options WHERE order_id = ?', [$orden['id']]));
        $this->assertSame('Av. Corrientes 1234', $this->valor('SELECT calle FROM product_order_envios WHERE order_id = ?', [$orden['id']]));
        // Con el pago pendiente, el stock sigue entero.
        $this->assertSame(5, $this->stockMNegro());

        $this->acreditar($codigo, 5500.0, '777001');

        $orden = $this->orden($codigo);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame('0', (string) $orden['sin_stock']);
        $this->assertSame(3, $this->stockMNegro());

        // Los dos mails: a quien compró y, sin email de contacto, a la dueña.
        $this->assertStringContainsString('Recibimos tu pedido', $this->mailer->mensajePara('ana@test.local')['asunto']);
        $this->assertStringContainsString('Pedido nuevo', $this->mailer->mensajePara('duena@test.local')['asunto']);
        $this->assertNotNull($orden['aviso_vendedor_en']);

        // El aviso repetido no descuenta de nuevo.
        $this->acreditar($codigo, 5500.0, '777001');
        $this->assertSame(3, $this->stockMNegro());
    }

    /** Dos compraron la última unidad a la vez: los dos pagaron, el segundo queda sin stock. */
    public function testConCarreraElSegundoQuedaPagadoYSinStock()
    {
        $linkId = $this->remera(1);

        $primera = $this->comprar($linkId, 1, 'ana@test.local')->body['codigo'];
        $segunda = $this->comprar($linkId, 1, 'beto@test.local')->body['codigo'];

        $this->acreditar($primera, 3000.0, '777101');
        $this->acreditar($segunda, 3000.0, '777102');

        $this->assertSame(['pagada', '0'], [$this->orden($primera)['estado'], (string) $this->orden($primera)['sin_stock']]);
        $this->assertSame(['pagada', '1'], [$this->orden($segunda)['estado'], (string) $this->orden($segunda)['sin_stock']]);
        $this->assertSame(0, $this->stockMNegro());
        $this->assertStringContainsString('no quedaba stock', $this->mailer->mensajePara('duena@test.local')['texto']);
    }

    /**
     * Mientras otra transacción tiene tomada la fila de stock, la acreditación
     * espera. Si no consigue el lock, no queda nada a medias: ni la orden
     * pagada ni el stock tocado.
     */
    public function testLaAcreditacionTomaLaFilaDeStockYEsAtomica()
    {
        $linkId = $this->remera(5);
        $codigo = $this->comprar($linkId, 2)->body['codigo'];
        $orden = $this->orden($codigo);

        $otra = BaseDescartable::conectar(self::BASE);
        $otra->beginTransaction();
        $otra->query('SELECT stock FROM product_variants FOR UPDATE')->fetchAll();

        $this->db->exec('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            OrdenesDeProductos::acreditarPago($this->db, $codigo, '777201', 'approved');
            $this->fail('La acreditación no esperó el lock de la fila de stock');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $otra->rollBack();
            $otra = null;
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame('pendiente', $this->orden($codigo)['estado']);
        $this->assertSame(5, $this->stockMNegro());

        // Con la fila libre, se acredita.
        $r = OrdenesDeProductos::acreditarPago($this->db, $codigo, '777201', 'approved');
        $this->assertTrue($r['acreditada']);
        $this->assertSame(3, $this->stockMNegro());
        $this->assertSame((int) $orden['id'], (int) $this->orden($codigo)['id']);
    }

    public function testUnPedidoGratisNacePagadoYDescuentaElStock()
    {
        $linkId = $this->remera(4, '0', '0');

        $r = $this->comprar($linkId, 3);

        $this->assertStatus(201, $r);
        $this->assertSame('pagada', $this->orden($r->body['codigo'])['estado']);
        $this->assertSame(1, $this->stockMNegro());
        $this->assertFalse($this->http->llamoA('/checkout/preferences'));
        $this->assertNotNull($this->mailer->mensajePara('ana@test.local'));
    }

    /** Si el producto cambia o se borra, el pedido sigue diciendo lo que se compró. */
    public function testLaFotoNoCambiaSiElProductoCambia()
    {
        $linkId = $this->remera(5);
        $codigo = $this->comprar($linkId, 1)->body['codigo'];

        LinksHandler::detail($this->db, $this->put(['text' => 'Remera nueva', 'precio' => '9999'], $this->user($this->duenaId), ['id' => $linkId]));

        $publica = CompraProductoHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http)->body['orden'];
        $this->assertSame('Remera', $publica['producto']);
        $this->assertSame(2500.0, $publica['precio_unitario']);
        $this->assertSame('Talle M · Color Negro', $publica['opciones']);
        $this->assertSame('Av. Corrientes 1…', $publica['envio']['calle']);
    }

    // ============================================= comisiones de la plataforma

    /**
     * Un físico va a su propia fila del reporte: no se suma a los productos
     * digitales, aunque viva en la misma tabla.
     */
    public function testElReporteDeComisionesCuentaLosFisicosAparte()
    {
        $linkId = $this->remera(5);
        $codigo = $this->comprar($linkId, 2)->body['codigo'];
        $this->acreditar($codigo, 5500.0, '777001');
        $this->db->exec('UPDATE product_orders SET mp_comision_cobrada = 0');
        LinksHandler::detail($this->db, $this->put(['text' => 'Remera nueva'], $this->user($this->duenaId), ['id' => $linkId]));

        $plataforma = $this->usuario('plataforma@test', 'Plataforma');
        \Plataforma::olvidar();
        $res = \PlataformaHandler::comisiones($this->db, $this->get([], $this->user($plataforma, 'plataforma@test')));

        $this->assertStatus(200, $res);
        $this->assertSame(0, $res->body['por_tipo']['productos']['ventas']);
        $this->assertSame(1, $res->body['por_tipo']['fisicos']['ventas']);
        $this->assertSame(5500.0, $res->body['por_tipo']['fisicos']['recaudado']);
        $this->assertSame($res->body['resumen'], $res->body['por_tipo']['fisicos']);
        $this->assertSame(1, $res->body['paginas'][0]['ventas_fisicos']);
        $this->assertSame(0, $res->body['paginas'][0]['ventas_productos']);
        $this->assertSame(1, $res->body['meses'][0]['ventas_fisicos']);

        $this->assertCount(1, $res->body['revisar']);
        $this->assertSame('fisico', $res->body['revisar'][0]['tipo']);
        $this->assertSame('Remera', $res->body['revisar'][0]['evento'], 'el nombre del pedido, no el de ahora');
    }
}
