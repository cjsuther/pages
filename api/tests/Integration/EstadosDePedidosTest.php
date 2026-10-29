<?php

namespace Tests\Integration;

use Cobros;
use CompraProductoHandler;
use LinksHandler;
use PedidosHandler;
use ProductosHandler;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Los estados de un pedido de un físico contra una MariaDB de verdad (#44):
 * entra Ingresado al acreditarse, quien vende lo mueve, cada cambio queda en
 * el historial y cancelar devuelve el stock.
 */
class EstadosDePedidosTest extends IntegracionTestCase
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

    /** Una remera Talle (M) con stock, comprada de a 2 y pagada. Devuelve el código. */
    private function pedidoPagado($stock = 5)
    {
        $r = LinksHandler::index($this->db, $this->post([
            'group_id' => $this->grupoId, 'url' => '', 'text' => 'Remera', 'tipo' => 'fisico',
            'precio' => '2500', 'costo_envio' => '500',
            'caracteristicas' => [['nombre' => 'Talle', 'opciones' => ['M']]],
            'variantes' => [['opciones' => ['M'], 'stock' => $stock]],
        ], $this->user($this->duenaId)));
        $this->assertStatus(201, $r);

        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-1', 'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-1',
        ]);

        $compra = CompraProductoHandler::comprar($this->db, $this->post([
            'link_id'  => (int) $r->body['link']['id'],
            'nombre'   => 'Ana Compradora',
            'email'    => 'ana@test.local',
            'telefono' => '11 5555-1234',
            'cantidad' => 2,
            'opciones' => [(int) $this->valor('SELECT id FROM product_attribute_options WHERE nombre = ?', ['M'])],
            'envio'    => [
                'calle' => 'Av. Corrientes 1234', 'localidad' => 'CABA',
                'provincia' => 'Ciudad Autónoma de Buenos Aires', 'codigo_postal' => '1043',
            ],
            'horarios' => ['dias' => ['lunes'], 'franjas' => ['tarde']],
        ]), $this->http, $this->mailer);
        $this->assertStatus(201, $compra);
        $codigo = $compra->body['codigo'];

        $this->http->responde('/v1/payments/', 200, [
            'id' => '880001', 'status' => 'approved', 'external_reference' => $codigo, 'transaction_amount' => 5500.0,
        ]);
        CompraProductoHandler::aviso(
            $this->db,
            $this->post(['type' => 'payment', 'data' => ['id' => '880001']], null, ['orden' => $codigo]),
            $this->http,
            $this->mailer
        );

        return $codigo;
    }

    private function stock()
    {
        return (int) $this->valor('SELECT stock FROM product_variants');
    }

    private function cambiar($codigo, array $datos, $userId = null)
    {
        return PedidosHandler::estado(
            $this->db,
            $this->post(['codigo' => $codigo] + $datos, $this->user($userId === null ? $this->duenaId : $userId)),
            $this->mailer
        );
    }

    private function historial($codigo)
    {
        $stmt = $this->db->prepare('
            SELECT h.estado, h.datos, h.user_id
            FROM product_order_historial h
            INNER JOIN product_orders o ON o.id = h.order_id
            WHERE o.codigo = ?
            ORDER BY h.id
        ');
        $stmt->execute([$codigo]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ================================================================ tests

    public function testDeIngresadoACanceladoConHistorialYStockDevuelto()
    {
        $codigo = $this->pedidoPagado(5);
        $this->assertSame(3, $this->stock());
        $this->assertSame('ingresado', $this->valor('SELECT estado_pedido FROM product_orders WHERE codigo = ?', [$codigo]));

        $fecha = date('Y-m-d', time() + 3 * 86400);
        $this->assertStatus(200, $this->cambiar($codigo, ['estado' => 'con_fecha', 'fecha' => $fecha, 'franja' => 'tarde']));

        $orden = $this->fila('SELECT * FROM product_orders WHERE codigo = ?', [$codigo]);
        $this->assertSame('con_fecha', $orden['estado_pedido']);
        $this->assertSame($fecha, $orden['entrega_fecha']);
        $this->assertSame('tarde', $orden['entrega_franja']);
        $this->assertStringContainsString('Tu pedido ya tiene fecha de entrega', $this->mailer->mensajePara('ana@test.local')['asunto']);

        // Corregir la fecha vuelve a avisar.
        $otra = date('Y-m-d', time() + 5 * 86400);
        $this->assertStatus(200, $this->cambiar($codigo, ['estado' => 'con_fecha', 'fecha' => $otra]));
        $this->assertStringContainsString('Cambió la fecha', $this->mailer->mensajePara('ana@test.local')['asunto']);

        // No se puede volver a Ingresado ni, una vez enviado, a Con fecha.
        $this->assertStatus(200, $this->cambiar($codigo, ['estado' => 'enviado', 'empresa' => 'Moto Juan']));
        $this->assertStatus(409, $this->cambiar($codigo, ['estado' => 'con_fecha', 'fecha' => $otra]));

        $r = $this->cambiar($codigo, ['estado' => 'cancelado', 'motivo' => 'Se mudó y no lo puede recibir']);
        $this->assertStatus(200, $r);
        $this->assertTrue($r->body['stock_devuelto']);
        $this->assertSame(5, $this->stock());
        $this->assertSame('Se mudó y no lo puede recibir', $this->valor('SELECT cancelado_motivo FROM product_orders WHERE codigo = ?', [$codigo]));
        // El pago no se toca: sigue pagada y nadie le pidió nada a Mercado Pago.
        $this->assertSame('pagada', $this->valor('SELECT estado FROM product_orders WHERE codigo = ?', [$codigo]));
        $this->assertFalse($this->http->llamoA('/refunds'));

        // Cancelado es final: el stock no se devuelve dos veces.
        $this->assertStatus(409, $this->cambiar($codigo, ['estado' => 'cancelado', 'motivo' => 'otra vez']));
        $this->assertSame(5, $this->stock());

        $historial = $this->historial($codigo);
        $this->assertSame(['ingresado', 'con_fecha', 'con_fecha', 'enviado', 'cancelado'], array_column($historial, 'estado'));
        $this->assertNull($historial[0]['user_id']);
        $this->assertSame($this->duenaId, (int) $historial[4]['user_id']);
        $this->assertSame(['motivo' => 'Se mudó y no lo puede recibir'], json_decode($historial[4]['datos'], true));
        $this->assertSame(['fecha' => $otra, 'franja' => null], json_decode($historial[2]['datos'], true));
    }

    public function testElDetalleTraeLaDireccionCompletaYElHistorialConQuien()
    {
        $codigo = $this->pedidoPagado();
        $this->cambiar($codigo, ['estado' => 'entregado']);

        $r = PedidosHandler::detalle($this->db, $this->get(['codigo' => $codigo], $this->user($this->duenaId)));

        $this->assertStatus(200, $r);
        $pedido = $r->body['pedido'];
        $this->assertSame('entregado', $pedido['estado_pedido']);
        $this->assertSame('Av. Corrientes 1234', $pedido['envio']['calle']);
        $this->assertSame('11 5555-1234', $pedido['telefono']);
        $this->assertSame('880001', $pedido['mp_payment_id']);
        $this->assertSame([], $pedido['siguientes']);
        $this->assertSame(['Sistema', 'Dueña'], array_column($pedido['historial'], 'quien'));

        // La pantalla pública ve la línea de tiempo, sin quién lo hizo.
        $publica = CompraProductoHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http);
        $this->assertSame('entregado', $publica->body['orden']['estado_pedido']);
        $this->assertSame(['ingresado', 'con_fecha', 'enviado', 'entregado'], array_column($publica->body['orden']['linea_de_tiempo'], 'estado'));
        $this->assertStringNotContainsString('Dueña', json_encode($publica->body, JSON_UNESCAPED_UNICODE));
    }

    public function testSoloLaDuenaYLosAdministradoresLoCambian()
    {
        $codigo = $this->pedidoPagado();
        $otra = $this->usuario('otra@test.local', 'Otra');
        $admin = $this->usuario('admin@test.local', 'Admin');
        $invitada = $this->usuario('invitada@test.local', 'Invitada');
        $this->insertar('page_admins', ['page_id' => $this->paginaId, 'user_id' => $admin, 'status' => 'accepted']);
        $this->insertar('page_admins', ['page_id' => $this->paginaId, 'user_id' => $invitada, 'status' => 'pending']);

        $this->assertStatus(403, $this->cambiar($codigo, ['estado' => 'entregado'], $otra));
        $this->assertStatus(403, $this->cambiar($codigo, ['estado' => 'entregado'], $invitada));
        $this->assertStatus(403, PedidosHandler::detalle($this->db, $this->get(['codigo' => $codigo], $this->user($otra))));
        $this->assertSame('ingresado', $this->valor('SELECT estado_pedido FROM product_orders WHERE codigo = ?', [$codigo]));

        $this->assertStatus(200, $this->cambiar($codigo, ['estado' => 'entregado'], $admin));
        $this->assertSame($admin, (int) $this->valor('SELECT user_id FROM product_order_historial ORDER BY id DESC LIMIT 1'));
    }

    public function testElExcelDePedidosTraeLaDireccion()
    {
        $this->pedidoPagado();

        $r = ProductosHandler::ventas($this->db, $this->get(
            ['page_id' => $this->paginaId, 'formato' => 'excel', 'tipo' => 'fisico', 'nombre' => 'La Sala'],
            $this->user($this->duenaId)
        ));

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('pedidos-la-sala.xlsx', implode("\n", $r->headers));

        $ruta = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($ruta, $r->raw);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($ruta) === true);
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($ruta);

        $this->assertStringContainsString('Av. Corrientes 1234', $hoja);
        $this->assertStringContainsString('Ingresado', $hoja);
        $this->assertStringContainsString('Talle M', $hoja);
    }
}
