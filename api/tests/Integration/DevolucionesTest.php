<?php

namespace Tests\Integration;

use CheckoutHandler;
use Cobros;
use CodigoQR;
use Devoluciones;
use DevolucionesHandler;
use Entradas;
use EntradasHandler;
use Puerta;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Devoluciones contra una MariaDB de verdad, con Mercado Pago simulado por un
 * HttpClient doble (nunca se sale a la red).
 *
 * Lo que se afirma es lo que quedó: el monto que se le pidió a Mercado Pago
 * (sólo el neto, nunca más), el intento con su motivo, la compra anulada o con
 * menos entradas, el cupo, el panel y el mail.
 *
 * Los números son los del análisis: una entrada de $10.000 con MP 4,39 % + IVA
 * y Rezonar 1 % deja $9.368,81 en la cuenta del organizador.
 */
class DevolucionesTest extends IntegracionTestCase
{
    const NETO = 9368.81;

    private $duenaId;
    private $paginaId;
    private $grupoId;
    /** @var FakeHttpClient */
    private $http;
    /** @var FakeMailer */
    private $mailer;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        ob_start();
        CodigoQR::png('https://rezonar.test/entrada/PRECARGA');
        ob_end_clean();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Devoluciones::forzar(true);
        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId);
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();
        $this->conectarPorOAuth();
    }

    protected function tearDown(): void
    {
        Devoluciones::forzar(null);
        parent::tearDown();
    }

    // ============================================================ fixtures

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

    private function eventoPago(array $config = [])
    {
        $linkId = $this->evento($this->grupoId, ['text' => 'Noche de Prueba']);

        $res = EntradasHandler::config($this->db, $this->post(array_merge([
            'activo' => 1, 'capacidad' => 10, 'precio' => 10000, 'max_por_compra' => 10,
        ], $config), $this->user($this->duenaId), ['link_id' => $linkId]));

        $this->assertStatus(200, $res);

        return $linkId;
    }

    /** Compra por el endpoint del público y la deja pagada, como el aviso de MP. */
    private function compraPagada($linkId, array $datos = [], $neto = self::NETO)
    {
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);

        $res = CheckoutHandler::comprar($this->db, $this->post(array_merge([
            'link_id' => $linkId,
            'nombre'  => 'Ana Compradora',
            'email'   => 'ana@test.local',
            'cantidad' => 1,
        ], $datos)), $this->http, $this->mailer);

        $this->assertStatus(201, $res);
        $codigo = $res->body['codigo'];

        $this->db->prepare("
            UPDATE ticket_orders
            SET estado = 'pagada', pagada_en = NOW(), mp_payment_id = ?, mp_neto = ?,
                mp_comision_cobrada = comision, mp_comisiones = total - ?
            WHERE codigo = ?
        ")->execute(['pago-' . $codigo, $neto, $neto, $codigo]);

        $this->http = new FakeHttpClient();
        $this->mailer->enviados = [];

        return $codigo;
    }

    /** Lo que contesta Mercado Pago al consultar el pago. */
    private function mpTieneElPago($codigo, $neto = self::NETO, $devuelto = 0, $estado = 'approved')
    {
        $orden = $this->orden($codigo);

        $this->http->responde('/v1/payments/pago-' . $codigo, 200, [
            'id' => 'pago-' . $codigo,
            'status' => $estado,
            'external_reference' => $codigo,
            'transaction_amount' => (float) $orden['total'],
            'transaction_amount_refunded' => $devuelto,
            'transaction_details' => ['net_received_amount' => $neto],
            'fee_details' => [
                ['type' => 'mercadopago_fee', 'amount' => round((float) $orden['total'] - $neto - (float) $orden['comision'], 2)],
                ['type' => 'application_fee', 'amount' => (float) $orden['comision']],
            ],
        ]);
    }

    private function mpDevuelve($codigo, $status = 201, $cuerpo = null)
    {
        $this->http->responde('/v1/payments/pago-' . $codigo . '/refunds', $status,
            $cuerpo === null ? ['id' => 777, 'amount' => self::NETO, 'status' => 'approved'] : $cuerpo);
    }

    /** Lo que se le pidió a Mercado Pago que devuelva, o null si no se pidió. */
    private function montoPedido()
    {
        foreach ($this->http->llamadas as $llamada) {
            if ($llamada['method'] === 'POST' && strpos($llamada['url'], '/refunds') !== false) {
                return $llamada['json']['amount'];
            }
        }

        return null;
    }

    private function devolver($codigo, array $pedido, $userId = null)
    {
        return DevolucionesHandler::devolucion(
            $this->db,
            $this->post(array_merge(['codigo' => $codigo], $pedido), $this->user($userId ?: $this->duenaId)),
            $this->http,
            $this->mailer
        );
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM ticket_orders WHERE codigo = ?', [$codigo]);
    }

    private function intentos($codigo)
    {
        $stmt = $this->db->prepare('
            SELECT d.* FROM ticket_order_devoluciones d
            INNER JOIN ticket_orders o ON o.id = d.order_id
            WHERE o.codigo = ? ORDER BY d.id');
        $stmt->execute([$codigo]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ===================================================== compra completa

    public function testLaPantallaMuestraElDesgloseYCuantoSePuedeDevolver()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);

        $res = DevolucionesHandler::devolucion($this->db, $this->get(['codigo' => $codigo], $this->user($this->duenaId)), $this->http);

        $this->assertStatus(200, $res);
        $desglose = $res->body['desglose'];
        $this->assertSame(10000.0, $desglose['cobrado']);
        $this->assertSame(1000.0, $desglose['comision_plataforma'], '10 % de los tests');
        $this->assertSame(self::NETO, $desglose['neto']);
        $this->assertSame(self::NETO, $desglose['devolvible']);
        $this->assertNull($res->body['aviso']);
        $this->assertSame([], $res->body['intentos']);
        $this->assertNull($this->montoPedido(), 'mirar no devuelve nada');
    }

    /** El organizador devuelve sólo lo que le llegó: ni un peso de las comisiones. */
    public function testDevolverLaCompraCompletaDevuelveElNetoYAnulaLaEntrada()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId);
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo);
        $this->http->responde('/v1/payments/pago-' . $codigo, 200, [
            'status' => 'refunded', 'transaction_amount' => 10000, 'transaction_amount_refunded' => self::NETO,
            'transaction_details' => ['net_received_amount' => 0],
            'fee_details' => [['type' => 'application_fee', 'amount' => 1000]],
        ]);

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertStatus(200, $res);
        $this->assertSame('aprobada', $res->body['estado']);
        $this->assertSame(self::NETO, $this->montoPedido(), 'parcial por el neto, nunca el total');

        $cabeceras = $this->http->cabecerasDe('/refunds');
        $this->assertNotEmpty(array_filter($cabeceras, function ($c) { return strpos($c, 'X-Idempotency-Key: rz-dev-') === 0; }));
        $this->assertContains('Authorization: Bearer APP_USR-1234567890-token-de-prueba', $cabeceras, 'token OAuth del vendedor');

        $orden = $this->orden($codigo);
        $this->assertSame('cancelada', $orden['estado']);
        $this->assertSame('9368.81', $orden['devuelto']);
        $this->assertNotNull($orden['devuelta_en']);
        $this->assertNull($orden['devolucion_error']);
        $this->assertSame(10, 10 - Entradas::ocupadas($this->db, $linkId), 'se liberó el cupo');
        $this->assertFalse(Puerta::ingresar($this->db, $linkId, $codigo, 1)['ok'], 'el QR ya no entra');

        $intento = $this->intentos($codigo)[0];
        $this->assertSame('aprobada', $intento['estado']);
        $this->assertSame('777', $intento['mp_refund_id']);
        $this->assertSame((string) $this->duenaId, (string) $intento['user_id']);
        $this->assertNotNull($intento['mail_enviado_en']);

        // El reparto que informó Mercado Pago después, para verificar el riesgo.
        $reparto = json_decode($intento['reparto'], true);
        // Vuelve de un JSON: 1000.0 puede leerse como entero.
        $this->assertEquals(1000.0, $reparto['pago']['application_fee']);
        $this->assertEquals(1000.0, $reparto['comision_plataforma_antes']);
        $this->assertFalse($reparto['toco_comision_plataforma']);

        $mail = $this->mailer->mensajePara('ana@test.local');
        $this->assertSame('Te devolvieron $9.368,81 de tu compra para Noche de Prueba', $mail['asunto']);
        $this->assertStringContainsString('quedó anulada', $mail['texto']);
    }

    /** Si Mercado Pago le descontó una parte a la comisión de la plataforma, queda a la vista. */
    public function testSiElReembolsoTocaLaComisionDeLaPlataformaQuedaRegistrado()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo);
        $this->http->responde('/v1/payments/pago-' . $codigo, 200, [
            'status' => 'approved', 'status_detail' => 'partially_refunded',
            'fee_details' => [['type' => 'application_fee', 'amount' => 63.19]],
        ]);

        $this->assertStatus(200, $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]));

        $reparto = json_decode($this->intentos($codigo)[0]['reparto'], true);
        $this->assertTrue($reparto['toco_comision_plataforma']);
    }

    public function testSimularNoTocaNadaNiLlamaAlReembolso()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);

        $res = $this->devolver($codigo, ['modo' => 'completa', 'simular' => true]);

        $this->assertStatus(200, $res);
        $this->assertSame(self::NETO, $res->body['monto']);
        $this->assertNull($this->montoPedido());
        $this->assertSame([], $this->intentos($codigo));
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
    }

    /** Si entre que miró y confirmó cambió el monto, se le muestra el nuevo y no se devuelve. */
    public function testUnMontoConfirmadoQueNoCoincideNoSeDevuelve()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => 10000]);

        $this->assertStatus(409, $res);
        $this->assertSame(self::NETO, $res->body['monto']);
        $this->assertNull($this->montoPedido());
        $this->assertSame([], $this->intentos($codigo), 'no es un intento: es una confirmación vieja');
    }

    // ===================================================== por entrada

    public function testDevolverDeAUnaTerminaDevolviendoExactamenteElNeto()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId, ['cantidad' => 3], 28106.43);

        // Primera: un tercio, hacia abajo.
        $this->mpTieneElPago($codigo, 28106.43);
        $this->mpDevuelve($codigo, 201, ['id' => 1, 'amount' => 9368.81, 'status' => 'approved']);
        $res = $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 1, 'confirmacion' => 9368.81]);
        $this->assertStatus(200, $res);
        $this->assertSame(9368.81, $this->montoPedido());

        $orden = $this->orden($codigo);
        $this->assertSame('pagada', $orden['estado'], 'la compra sigue, con menos entradas');
        $this->assertSame(2, (int) $orden['cantidad']);
        $this->assertSame(8, 10 - Entradas::ocupadas($this->db, $linkId));
        $this->assertStringContainsString('2 entradas', $this->mailer->mensajePara('ana@test.local')['texto']);

        // Segunda: la mitad de lo que queda (18737,62 / 2).
        $this->http = new FakeHttpClient();
        $this->mpTieneElPago($codigo, 28106.43, 9368.81);
        $this->mpDevuelve($codigo, 201, ['id' => 2, 'amount' => 9368.81, 'status' => 'approved']);
        $this->assertStatus(200, $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 1, 'confirmacion' => 9368.81]));

        // La última se lleva el resto y anula la compra.
        $this->http = new FakeHttpClient();
        $this->mpTieneElPago($codigo, 28106.43, 18737.62);
        $this->mpDevuelve($codigo, 201, ['id' => 3, 'amount' => 9368.81, 'status' => 'approved']);
        $simulada = $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 1, 'simular' => true]);
        $this->assertSame('completa', $simulada->body['modo']);
        $this->assertSame(9368.81, $simulada->body['monto']);

        $this->http = new FakeHttpClient();
        $this->mpTieneElPago($codigo, 28106.43, 18737.62);
        $this->mpDevuelve($codigo, 201, ['id' => 3, 'amount' => 9368.81, 'status' => 'approved']);
        $this->assertStatus(200, $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 1, 'confirmacion' => 9368.81]));

        $orden = $this->orden($codigo);
        $this->assertSame('cancelada', $orden['estado']);
        $this->assertSame('28106.43', $orden['devuelto'], 'la suma es el neto, al centavo');
    }

    public function testConTiposSeDevuelveLaParteDeLoQueValen()
    {
        $linkId = $this->eventoPago(['tipos' => [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 8000, 'cupo' => null],
            ['id' => 'jubilados', 'nombre' => 'Jubilados', 'precio' => 5000, 'cupo' => null],
        ]]);
        $codigo = $this->compraPagada($linkId, ['tipos' => ['general' => 1, 'jubilados' => 1]], 12000);
        $this->mpTieneElPago($codigo, 12000);
        $this->mpDevuelve($codigo, 201, ['id' => 5, 'amount' => 4615.38, 'status' => 'approved']);

        $res = $this->devolver($codigo, ['modo' => 'entradas', 'tipos' => ['jubilados' => 1], 'confirmacion' => 4615.38]);

        $this->assertStatus(200, $res);
        // 12000 × 5000 / 13000, hacia abajo.
        $this->assertSame(4615.38, $this->montoPedido());
        $items = Entradas::itemsDeLaOrden($this->db, (int) $this->orden($codigo)['id']);
        $this->assertCount(1, $items);
        $this->assertSame('general', $items[0]['tipo']);
    }

    public function testConPlanoSeLiberanLosLugaresDevueltos()
    {
        $linkId = $this->eventoPago(['plano' => [
            'ancho' => 10, 'alto' => 6,
            'elementos' => [['tipo' => 'fila', 'nombre' => 'A', 'butacas' => 4, 'desde' => 1, 'x' => 0, 'y' => 0]],
        ]]);
        $codigo = $this->compraPagada($linkId, ['lugares' => ['f:A:1', 'f:A:2']], 18737.62);
        $this->mpTieneElPago($codigo, 18737.62);
        $this->mpDevuelve($codigo, 201, ['id' => 9, 'amount' => 9368.81, 'status' => 'approved']);

        $res = $this->devolver($codigo, ['modo' => 'entradas', 'lugares' => ['f:A:2'], 'confirmacion' => 9368.81]);

        $this->assertStatus(200, $res);
        $this->assertSame(9368.81, $this->montoPedido());
        $this->assertSame(['f:A:1'], Entradas::lugaresDeLaOrden($this->db, (int) $this->orden($codigo)['id']));
        $this->assertNotContains('f:A:2', Entradas::lugaresOcupados($this->db, $linkId), 'el lugar vuelve a estar libre');
    }

    public function testUnLugarQueNoEsDeLaCompraEsUnPedidoMalFormado()
    {
        $linkId = $this->eventoPago(['plano' => [
            'ancho' => 10, 'alto' => 6,
            'elementos' => [['tipo' => 'fila', 'nombre' => 'A', 'butacas' => 4, 'desde' => 1, 'x' => 0, 'y' => 0]],
        ]]);
        $codigo = $this->compraPagada($linkId, ['lugares' => ['f:A:1']]);
        $this->mpTieneElPago($codigo);

        $this->assertStatus(400, $this->devolver($codigo, ['modo' => 'entradas', 'lugares' => ['f:A:3'], 'confirmacion' => 1]));
        $this->assertSame([], $this->intentos($codigo));
    }

    /** La plata salió pero la compra cambió en el medio: se registra igual y queda anotado. */
    public function testSiLaCompraCambiaMientrasSeDevuelveLaPlataQuedaRegistrada()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId);
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo, 0, '');
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        // Mientras estaba incierta, la persona entró al evento.
        $this->db->prepare('UPDATE ticket_orders SET ingresadas = 1 WHERE codigo = ?')->execute([$codigo]);

        $this->http = new FakeHttpClient();
        $this->http->responde('/refunds', 200, [['id' => 31, 'amount' => self::NETO, 'status' => 'approved']]);
        Devoluciones::conciliar($this->db, $this->http, $this->mailer);

        $intento = $this->intentos($codigo)[0];
        $this->assertSame('aprobada', $intento['estado']);
        $this->assertStringContainsString('Entró gente', $intento['entradas_error']);
        $this->assertSame('9368.81', $this->orden($codigo)['devuelto']);
        $this->assertSame('pagada', $this->orden($codigo)['estado'], 'no se anula a alguien que ya está adentro');
    }

    public function testPedirSacarMasEntradasDeLasQueTieneEsUnPedidoMalFormado()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);

        $res = $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 5, 'confirmacion' => 1]);

        $this->assertStatus(400, $res);
        $this->assertSame([], $this->intentos($codigo));
    }

    // ===================================================== bloqueos

    public function testLasEntradasUsadasNoSeDevuelvenYQuedaElMotivo()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId);
        $this->db->prepare('UPDATE ticket_orders SET ingresadas = 1 WHERE codigo = ?')->execute([$codigo]);

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertStatus(409, $res);
        $this->assertSame(Devoluciones::MOTIVOS['usadas'], $res->body['error']);
        $this->assertNull($this->montoPedido());

        $intento = $this->intentos($codigo)[0];
        $this->assertSame('rechazada', $intento['estado']);
        $this->assertSame('validacion', $intento['origen_rechazo']);
        $this->assertSame(Devoluciones::MOTIVOS['usadas'], $intento['motivo_rechazo']);
        $this->assertCount(1, $res->body['intentos'], 'la pantalla recibe el historial');

        $orden = $this->orden($codigo);
        $this->assertSame(Devoluciones::MOTIVOS['usadas'], $orden['devolucion_error']);
        $this->assertNotNull($orden['devolucion_error_en']);
        $this->assertSame('pagada', $orden['estado']);
    }

    /** Un doble clic no deja dos rechazos iguales. */
    public function testElMismoBloqueoRepetidoSeAnotaUnaSolaVez()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->db->prepare('UPDATE ticket_orders SET ingresadas = 1 WHERE codigo = ?')->execute([$codigo]);

        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertCount(1, $this->intentos($codigo));
    }

    public function testSacarEntradasYaUsadasTambienSeBloquea()
    {
        $codigo = $this->compraPagada($this->eventoPago(), ['cantidad' => 2], 18737.62);
        $this->db->prepare('UPDATE ticket_orders SET ingresadas = 2 WHERE codigo = ?')->execute([$codigo]);
        $this->mpTieneElPago($codigo, 18737.62);

        $res = $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 1, 'confirmacion' => 9368.81]);

        $this->assertStatus(409, $res);
        $this->assertNull($this->montoPedido());
        $this->assertSame('rechazada', $this->intentos($codigo)[0]['estado']);
    }

    public function testUnaReservaSinCostoNoTieneNadaParaDevolver()
    {
        $linkId = $this->eventoPago(['precio' => 0]);
        $res = CheckoutHandler::comprar($this->db, $this->post([
            'link_id' => $linkId, 'nombre' => 'Ana', 'email' => 'ana@test.local', 'cantidad' => 1,
        ]), $this->http, $this->mailer);
        $codigo = $res->body['codigo'];

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => 0]);

        $this->assertStatus(409, $res);
        $this->assertSame(Devoluciones::MOTIVOS['sin_costo'], $res->body['error']);
        $this->assertSame(Devoluciones::MOTIVOS['sin_costo'], $this->orden($codigo)['devolucion_error']);
    }

    /** Tope: si Mercado Pago ya devolvió el neto (por fuera), no se pide nada más. */
    public function testNuncaSeDevuelveMasQueElNeto()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo, self::NETO, self::NETO);

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertStatus(409, $res);
        $this->assertSame(Devoluciones::MOTIVOS['nada'], $res->body['error']);
        $this->assertNull($this->montoPedido());
    }

    public function testConLasDevolucionesApagadasElIntentoQuedaRegistrado()
    {
        Devoluciones::forzar(false);
        $codigo = $this->compraPagada($this->eventoPago());

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertStatus(403, $res);
        $this->assertSame(Devoluciones::MOTIVOS['apagadas'], $this->intentos($codigo)[0]['motivo_rechazo']);
        $this->assertNull($this->montoPedido());
    }

    public function testQuienNoAdministraLaPaginaNoPuedeNiDejarRastro()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $otro = $this->usuario('otro@test.local', 'Otro');

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO], $otro);

        $this->assertStatus(403, $res);
        $this->assertSame([], $this->intentos($codigo));
    }

    // ===================================================== Mercado Pago dice que no

    public function testUnRechazoDeMercadoPagoQuedaConSuCodigoYSuMotivo()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo, 400, [
            'message' => 'Insufficient balance', 'error' => 'bad_request', 'status' => 400,
            'cause' => [['code' => 'insufficient_amount', 'description' => 'Insufficient balance to refund']],
        ]);

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertStatus(409, $res);
        $this->assertStringContainsString('saldo suficiente', $res->body['error']);

        $intento = $this->intentos($codigo)[0];
        $this->assertSame('rechazada', $intento['estado']);
        $this->assertSame('mercadopago', $intento['origen_rechazo']);
        $this->assertSame('insufficient_amount', $intento['mp_error_codigo']);
        $this->assertSame('Insufficient balance to refund', $intento['mp_error_mensaje']);
        $this->assertSame('400', (string) $intento['mp_status_http']);

        $orden = $this->orden($codigo);
        $this->assertSame('pagada', $orden['estado'], 'la entrada sigue valiendo');
        $this->assertSame('0.00', $orden['devuelto']);
        $this->assertStringContainsString('saldo suficiente', $orden['devolucion_error']);
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testDespuesDeUnRechazoSePuedeVolverAIntentarYElErrorSeLimpia()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo, 400, ['cause' => [['code' => 3024, 'description' => 'Partial refund unsupported']]]);
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo);
        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertStatus(200, $res);
        $this->assertNull($this->orden($codigo)['devolucion_error']);
        $this->assertCount(2, $this->intentos($codigo));

        // Las dos claves de idempotencia son distintas: son dos intentos.
        $claves = array_column($this->intentos($codigo), 'idempotency_key');
        $this->assertNotSame($claves[0], $claves[1]);
    }

    // ===================================================== no se sabe

    /** Un corte con Mercado Pago no se reintenta: queda incierta y bloquea otro pedido. */
    public function testUnCorteDejaLaDevolucionInciertaYNoDejaPedirOtra()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo, 0, '');

        $res = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->assertStatus(202, $res);
        $this->assertSame('incierta', $res->body['estado']);
        $this->assertSame('incierta', $this->intentos($codigo)[0]['estado']);
        $this->assertSame('pagada', $this->orden($codigo)['estado'], 'no se anula hasta saber');

        $this->http = new FakeHttpClient();
        $this->mpTieneElPago($codigo);
        $otra = $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => 0]);

        $this->assertStatus(409, $otra);
        $this->assertNull($this->montoPedido(), 'no se pide un segundo reembolso');
        $this->assertCount(1, $this->intentos($codigo), 'chocar con una en curso no es un intento');
    }

    public function testLaConciliacionConfirmaLaInciertaSiMercadoPagoLaTiene()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId);
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo, 503, '');
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $this->http = new FakeHttpClient();
        $this->http->responde('/v1/payments/pago-' . $codigo . '/refunds', 200, [
            ['id' => 888, 'amount' => self::NETO, 'status' => 'approved'],
        ]);
        $this->http->responde('/v1/payments/pago-' . $codigo, 200, ['status' => 'refunded', 'fee_details' => []]);

        $r = Devoluciones::conciliar($this->db, $this->http, $this->mailer);

        $this->assertSame(1, $r['aprobadas']);
        foreach ($this->http->llamadas as $llamada) {
            $this->assertSame('GET', $llamada['method'], 'la conciliación nunca pide un reembolso');
        }

        $intento = $this->intentos($codigo)[0];
        $this->assertSame('aprobada', $intento['estado']);
        $this->assertSame('888', $intento['mp_refund_id']);
        $this->assertSame('cancelada', $this->orden($codigo)['estado']);
        $this->assertSame('9368.81', $this->orden($codigo)['devuelto']);
        $this->assertCount(1, $this->mailer->enviados);

        // Otra corrida no vuelve a sumar ni a mandar el mail.
        $this->http = new FakeHttpClient();
        $this->assertSame(0, Devoluciones::conciliar($this->db, $this->http, $this->mailer)['revisadas']);
        $this->assertSame('9368.81', $this->orden($codigo)['devuelto']);
        $this->assertCount(1, $this->mailer->enviados);
    }

    public function testUnaInciertaQueMercadoPagoNoTieneSeDescartaPasadoUnRato()
    {
        $codigo = $this->compraPagada($this->eventoPago());
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo, 0, '');
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        // Todavía es reciente: se espera.
        $this->http = new FakeHttpClient();
        $this->http->responde('/refunds', 200, []);
        $this->assertSame(0, Devoluciones::conciliar($this->db, $this->http, $this->mailer)['rechazadas']);
        $this->assertSame('incierta', $this->intentos($codigo)[0]['estado']);

        // Una hora después, Mercado Pago sigue sin tenerla: no se hizo.
        $this->db->exec("UPDATE ticket_order_devoluciones SET created_at = NOW() - INTERVAL 1 HOUR");
        $this->http = new FakeHttpClient();
        $this->http->responde('/refunds', 200, []);
        $this->assertSame(1, Devoluciones::conciliar($this->db, $this->http, $this->mailer)['rechazadas']);

        $intento = $this->intentos($codigo)[0];
        $this->assertSame('rechazada', $intento['estado']);
        $this->assertStringContainsString('no se hizo', $intento['motivo_rechazo']);
        $this->assertStringContainsString('no se hizo', $this->orden($codigo)['devolucion_error']);
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
    }

    /** Un reembolso que ya es de otro intento no se le asigna a éste. */
    public function testLaConciliacionNoUsaUnReembolsoQueYaEsDeOtroIntento()
    {
        $codigo = $this->compraPagada($this->eventoPago(), ['cantidad' => 2], 18737.62);
        $this->mpTieneElPago($codigo, 18737.62);
        $this->mpDevuelve($codigo, 201, ['id' => 1, 'amount' => 9368.81, 'status' => 'approved']);
        $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 1, 'confirmacion' => 9368.81]);

        $this->http = new FakeHttpClient();
        $this->mpTieneElPago($codigo, 18737.62, 9368.81);
        $this->mpDevuelve($codigo, 0, '');
        $this->devolver($codigo, ['modo' => 'entradas', 'cantidad' => 1, 'confirmacion' => 9368.81]);

        $this->http = new FakeHttpClient();
        $this->http->responde('/refunds', 200, [['id' => 1, 'amount' => 9368.81, 'status' => 'approved']]);

        $this->assertSame(0, Devoluciones::conciliar($this->db, $this->http, $this->mailer)['aprobadas']);
        $this->assertSame('incierta', $this->intentos($codigo)[1]['estado']);
    }

    // ===================================================== panel y Excel

    public function testElPanelRestaLoDevueltoDeLoQueTeQueda()
    {
        $linkId = $this->eventoPago();
        $devuelta = $this->compraPagada($linkId);
        $otra = $this->compraPagada($linkId, [], self::NETO);
        $this->mpTieneElPago($devuelta);
        $this->mpDevuelve($devuelta);
        $this->assertStatus(200, $this->devolver($devuelta, ['modo' => 'completa', 'confirmacion' => self::NETO]));

        $res = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)));
        $resumen = $res->body['resumen'];

        $this->assertTrue($res->body['devoluciones']);
        $this->assertSame(1, $resumen['vendidas'], 'la devuelta ya no es una entrada vendida');
        $this->assertSame(20000.0, $resumen['recaudado'], 'lo cobrado se cobró');
        $this->assertSame(self::NETO, $resumen['devuelto']);
        $this->assertSame(self::NETO, $resumen['neto'], 'te queda sólo la otra');
        $this->assertSame(2000.0, $resumen['comision_cobrada'], 'la comisión de Rezonar no se toca');

        $porCodigo = array_column($res->body['ordenes'], null, 'codigo');
        $this->assertSame(self::NETO, $porCodigo[$devuelta]['devuelto']);
        $this->assertSame(0.0, $porCodigo[$otra]['devuelto']);
    }

    public function testElPanelMuestraElUltimoIntentoFallido()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId);
        $this->db->prepare('UPDATE ticket_orders SET ingresadas = 1 WHERE codigo = ?')->execute([$codigo]);
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $res = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)));
        $orden = $res->body['ordenes'][0];

        $this->assertSame(Devoluciones::MOTIVOS['usadas'], $orden['devolucion_error']);
        $this->assertNotNull($orden['devolucion_error_en']);
    }

    public function testElListadoDeEventosSigueContandoLoCobradoDeUnaDevuelta()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId);
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo);
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $eventos = Entradas::eventosConEntradas($this->db, $this->paginaId, ['estado' => 'todos']);

        $this->assertSame(10000.0, $eventos[0]['recaudado']);
        $this->assertSame(0, (int) $eventos[0]['vendidas']);
    }

    public function testElExcelTieneLoDevueltoYLoQueQueda()
    {
        $linkId = $this->eventoPago();
        $codigo = $this->compraPagada($linkId);
        $this->mpTieneElPago($codigo);
        $this->mpDevuelve($codigo);
        $this->devolver($codigo, ['modo' => 'completa', 'confirmacion' => self::NETO]);

        $res = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId, 'formato' => 'excel'], $this->user($this->duenaId)));
        $hoja = $this->hoja($res->raw);

        $this->assertStringContainsString('Devuelto', $hoja);
        $this->assertStringContainsString('Te queda', $hoja);
        $this->assertStringContainsString('9368.81', $hoja);
    }

    private function hoja($xlsx)
    {
        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($archivo, $xlsx);
        $zip = new \ZipArchive();
        $zip->open($archivo);
        $contenido = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($archivo);

        return $contenido;
    }
}
