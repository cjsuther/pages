<?php

namespace Tests\Integration;

use CheckoutHandler;
use Cobros;
use CodigoQR;
use Entradas;
use EntradasHandler;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Modificar una compra de entradas contra una MariaDB de verdad: cantidad,
 * tipos y lugares, sin volver a cobrar, y el mail a quien compró.
 *
 * Lo que se afirma es lo que quedó en la base: la cantidad, los renglones,
 * los lugares, el historial, y que el total y la comisión siguen siendo lo
 * que se pagó.
 */
class ModificacionDeComprasTest extends IntegracionTestCase
{
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

        // La librería del QR avisa deprecaciones la primera vez que se carga.
        ob_start();
        CodigoQR::png('https://rezonar.test/entrada/PRECARGA');
        ob_end_clean();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId);
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();
    }

    // ============================================================ fixtures

    private function eventoConVenta(array $config = [])
    {
        $linkId = $this->evento($this->grupoId, ['text' => 'Noche de Prueba']);

        $res = EntradasHandler::config($this->db, $this->post(array_merge([
            'activo' => 1, 'capacidad' => 10, 'precio' => 0, 'max_por_compra' => 10,
        ], $config), $this->user($this->duenaId), ['link_id' => $linkId]));

        $this->assertStatus(200, $res);

        return $linkId;
    }

    private function tipos()
    {
        return [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 8000, 'cupo' => null],
            ['id' => 'jubilados', 'nombre' => 'Jubilados', 'precio' => 5000, 'cupo' => 2],
        ];
    }

    private function planoDeUnaFila($butacas = 4)
    {
        return [
            'ancho' => 10, 'alto' => 6,
            'elementos' => [['tipo' => 'fila', 'nombre' => 'A', 'butacas' => $butacas, 'desde' => 1, 'x' => 0, 'y' => 0]],
        ];
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
        $this->http->responde('/checkout/preferences', 201, [
            'id' => 'pref-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-123',
        ]);
    }

    /** Una compra hecha por el mismo endpoint del público. Devuelve el código. */
    private function comprar($linkId, array $datos = [], $email = 'ana@test.local')
    {
        $res = CheckoutHandler::comprar($this->db, $this->post(array_merge([
            'link_id' => $linkId,
            'nombre'  => 'Ana Compradora',
            'email'   => $email,
            'cantidad' => 1,
        ], $datos)), $this->http, $this->mailer);

        $this->assertStatus(201, $res);

        return $res->body['codigo'];
    }

    /** Lo que deja el aviso de Mercado Pago, sin pasar por él. */
    private function marcarPagada($codigo, $comision = 1600)
    {
        $this->db->prepare("
            UPDATE ticket_orders
            SET estado = 'pagada', pagada_en = NOW(), mp_payment_id = ?, comision = ?, comision_porcentaje = 10
            WHERE codigo = ?
        ")->execute(['pago-' . $codigo, $comision, $codigo]);
    }

    private function modificar($codigo, array $cambio, $userId = null)
    {
        return EntradasHandler::modificar(
            $this->db,
            $this->post(array_merge(['codigo' => $codigo], $cambio), $this->user($userId ?: $this->duenaId)),
            $this->mailer
        );
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM ticket_orders WHERE codigo = ?', [$codigo]);
    }

    private function lugares($codigo)
    {
        $stmt = $this->db->prepare('
            SELECT tl.lugar FROM ticket_order_lugares tl
            INNER JOIN ticket_orders o ON o.id = tl.order_id
            WHERE o.codigo = ? ORDER BY tl.lugar');
        $stmt->execute([$codigo]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function items($codigo)
    {
        $stmt = $this->db->prepare('
            SELECT i.tipo, i.cantidad FROM ticket_order_items i
            INNER JOIN ticket_orders o ON o.id = i.order_id
            WHERE o.codigo = ? ORDER BY i.id');
        $stmt->execute([$codigo]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    // ===================================================== cantidad

    public function testSubirUnaReservaGratisLaActualizaYAvisaPorMail()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 2]);
        $this->mailer->enviados = [];

        $res = $this->modificar($codigo, ['cantidad' => 4]);

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['mail']['enviado']);
        $this->assertSame(4, $res->body['despues']['cantidad']);

        $orden = $this->orden($codigo);
        $this->assertSame(4, (int) $orden['cantidad']);
        $this->assertSame('0.00', $orden['total'], 'no se cobra nada');
        $this->assertNotNull($orden['modificada_en']);
        $this->assertSame(6, 10 - Entradas::ocupadas($this->db, $linkId));

        $mail = $this->mailer->mensajePara('ana@test.local');
        $this->assertSame('Cambios en tu entrada para Noche de Prueba', $mail['asunto']);
        $this->assertStringContainsString('4 entradas', $mail['texto']);
        $this->assertStringContainsString($codigo, $mail['texto'], 'el mismo código de siempre');
        $this->assertStringContainsString('TU ENTRADA ACTUALIZADA', $mail['html']);

        $historial = $this->fila('SELECT * FROM ticket_order_modificaciones WHERE order_id = ?', [$orden['id']]);
        $this->assertSame((string) $this->duenaId, (string) $historial['user_id']);
        $this->assertSame(2, json_decode($historial['antes'], true)['cantidad']);
        $this->assertSame(4, json_decode($historial['despues'], true)['cantidad']);
        $this->assertNotNull($historial['mail_enviado_en']);
    }

    public function testBajarLaCantidadLiberaElCupo()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 5]);
        $codigo = $this->comprar($linkId, ['cantidad' => 4]);

        $this->assertStatus(200, $this->modificar($codigo, ['cantidad' => 1]));

        $this->assertSame(1, (int) $this->orden($codigo)['cantidad']);
        $this->assertSame(4, Entradas::disponibilidad($this->db, $linkId)['disponibles']);
    }

    public function testNoPuedeCrecerMasAllaDeLaCapacidad()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 3]);
        $this->comprar($linkId, ['cantidad' => 2], 'otra@test.local');
        $codigo = $this->comprar($linkId, ['cantidad' => 1]);

        $res = $this->modificar($codigo, ['cantidad' => 2]);

        $this->assertStatus(409, $res);
        $this->assertSame('No quedan entradas: la compra no puede crecer', $res->body['error']);
        $this->assertSame(1, (int) $this->orden($codigo)['cantidad']);
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM ticket_order_modificaciones'));
    }

    /** Lo que ya tenía la compra cuenta como suyo: 2 de 3 con 1 libre puede pasar a 3. */
    public function testLoQueYaTeniaLaCompraCuentaComoDisponible()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 3]);
        $codigo = $this->comprar($linkId, ['cantidad' => 2]);

        $this->assertStatus(200, $this->modificar($codigo, ['cantidad' => 3]));
        $this->assertSame(3, (int) $this->orden($codigo)['cantidad']);
    }

    /** Si alguien achicó la capacidad por debajo de lo vendido, bajar una compra igual se puede. */
    public function testBajarSePuedeAunqueElEventoEsteSobrevendido()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 10]);
        $codigo = $this->comprar($linkId, ['cantidad' => 5]);
        $this->db->prepare('UPDATE event_ticketing SET capacidad = 2 WHERE link_id = ?')->execute([$linkId]);

        $this->assertStatus(200, $this->modificar($codigo, ['cantidad' => 4]));
    }

    public function testNoPuedeQuedarConMenosEntradasQueLasQueYaIngresaron()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 3]);
        $this->db->prepare('UPDATE ticket_orders SET ingresadas = 2 WHERE codigo = ?')->execute([$codigo]);

        $res = $this->modificar($codigo, ['cantidad' => 1]);

        $this->assertStatus(409, $res);
        $this->assertStringContainsString('Ya entraron 2 personas', $res->body['error']);
        $this->assertStatus(200, $this->modificar($codigo, ['cantidad' => 2]));
    }

    public function testCeroEntradasNoEsModificarSinoCancelar()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 2]);

        $res = $this->modificar($codigo, ['cantidad' => 0]);

        $this->assertStatus(400, $res);
        $this->assertStringContainsString('cancelala', $res->body['error']);
    }

    public function testSinCambiosNoSeGuardaNiSeMandaMail()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 2]);
        $this->mailer->enviados = [];

        $res = $this->modificar($codigo, ['cantidad' => 2]);

        $this->assertStatus(400, $res);
        $this->assertSame('No hay cambios para guardar', $res->body['error']);
        $this->assertSame([], $this->mailer->enviados);
        $this->assertNull($this->orden($codigo)['modificada_en']);
    }

    // ===================================================== estados y permisos

    /** Una compra que espera el pago no: Mercado Pago cobraría lo de antes. */
    public function testUnaCompraPendienteDePagoNoSeModifica()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 5000]);
        $codigo = $this->comprar($linkId, ['cantidad' => 1]);
        $this->assertSame('reservada', $this->orden($codigo)['estado']);

        $res = $this->modificar($codigo, ['cantidad' => 2]);

        $this->assertStatus(409, $res);
        $this->assertSame('Sólo se pueden modificar compras pagadas o reservas confirmadas', $res->body['error']);
    }

    public function testUnaCompraCanceladaNoSeModifica()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 1]);
        Entradas::cancelar($this->db, $codigo);

        $this->assertStatus(409, $this->modificar($codigo, ['cantidad' => 2]));
    }

    public function testSoloQuienAdministraLaPaginaPuedeModificar()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 1]);
        $otra = $this->usuario('otra@test.local', 'Otra');

        $res = $this->modificar($codigo, ['cantidad' => 5], $otra);

        $this->assertStatus(403, $res);
        $this->assertSame(1, (int) $this->orden($codigo)['cantidad']);

        $sinSesion = EntradasHandler::modificar($this->db, $this->post(['codigo' => $codigo, 'cantidad' => 5]), $this->mailer);
        $this->assertStatus(401, $sinSesion);
    }

    /** Si el mail falla, el cambio queda igual y el historial anota por qué. */
    public function testSiElMailFallaLaModificacionQuedaIgual()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 1]);
        $this->mailer->fallarCon('SMTP caído');

        $res = $this->modificar($codigo, ['cantidad' => 3]);

        $this->assertStatus(200, $res);
        $this->assertFalse($res->body['mail']['enviado']);
        $this->assertSame(3, (int) $this->orden($codigo)['cantidad']);
        $this->assertSame('SMTP caído', $this->valor('SELECT mail_error FROM ticket_order_modificaciones'));
    }

    // ===================================================== pagadas y tipos

    /**
     * Lo cobrado no cambia: total, comisión y pago quedan como se pagaron. Y
     * el desglose por tipo sigue sumando lo recaudado.
     */
    public function testEnUnaCompraPagadaElTotalYLaComisionNoCambian()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['tipos' => $this->tipos()]);
        $codigo = $this->comprar($linkId, ['tipos' => ['general' => 2]]);
        $this->marcarPagada($codigo);
        $antes = $this->orden($codigo);
        $this->assertSame('16000.00', $antes['total']);

        $res = $this->modificar($codigo, ['tipos' => ['general' => 1, 'jubilados' => 2]]);

        $this->assertStatus(200, $res);
        $despues = $this->orden($codigo);
        $this->assertSame(3, (int) $despues['cantidad']);
        $this->assertSame($antes['total'], $despues['total']);
        $this->assertSame($antes['comision'], $despues['comision']);
        $this->assertSame($antes['mp_payment_id'], $despues['mp_payment_id']);
        $this->assertSame(['general' => 1, 'jubilados' => 2], $this->items($codigo));

        $resumen = Entradas::ventasDelEvento($this->db, $linkId)['resumen'];
        $this->assertEquals(16000, $resumen['recaudado']);
        $this->assertEquals(16000, array_sum(array_column($resumen['por_tipo'], 'recaudado')),
            'el desglose por tipo cierra con lo cobrado');
        $this->assertSame(3, $resumen['vendidas']);
    }

    public function testElCupoDeUnTipoSeRespetaSinContarLoDeLaPropiaCompra()
    {
        $linkId = $this->eventoConVenta(['tipos' => [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 0, 'cupo' => null],
            ['id' => 'jubilados', 'nombre' => 'Jubilados', 'precio' => 0, 'cupo' => 2],
        ]]);
        $this->comprar($linkId, ['tipos' => ['jubilados' => 1]], 'otra@test.local');
        $codigo = $this->comprar($linkId, ['tipos' => ['general' => 1]]);

        $res = $this->modificar($codigo, ['tipos' => ['general' => 1, 'jubilados' => 2]]);
        $this->assertStatus(409, $res);
        $this->assertSame('Sólo quedan 1 entradas "Jubilados" para esta compra', $res->body['error']);

        $this->assertStatus(200, $this->modificar($codigo, ['tipos' => ['jubilados' => 1]]));
        $this->assertSame(['jubilados' => 1], $this->items($codigo));
    }

    // ===================================================== lugares

    public function testSeCambianLosLugaresYLosViejosQuedanLibres()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(4)]);
        $codigo = $this->comprar($linkId, ['lugares' => ['f:A:1', 'f:A:2']]);

        $res = $this->modificar($codigo, ['lugares' => ['f:A:2', 'f:A:3', 'f:A:4']]);

        $this->assertStatus(200, $res);
        $this->assertSame(['f:A:2', 'f:A:3', 'f:A:4'], $this->lugares($codigo));
        $this->assertSame(3, (int) $this->orden($codigo)['cantidad']);
        $this->assertSame(['f:A:2', 'f:A:3', 'f:A:4'], Entradas::lugaresOcupados($this->db, $linkId));

        $mail = $this->mailer->mensajePara('ana@test.local');
        $this->assertStringContainsString('Lugares:', $mail['texto']);
    }

    public function testUnLugarDeOtraCompraNoSePuedeTomar()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(4)]);
        $this->comprar($linkId, ['lugares' => ['f:A:3']], 'otra@test.local');
        $codigo = $this->comprar($linkId, ['lugares' => ['f:A:1']]);

        $res = $this->modificar($codigo, ['lugares' => ['f:A:3']]);

        $this->assertStatus(409, $res);
        $this->assertSame(['f:A:3'], $res->body['ocupados']);
        $this->assertSame(['f:A:1'], $this->lugares($codigo));
    }

    public function testParaEditarLosLugaresPropiosNoFiguranOcupados()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(4)]);
        $this->comprar($linkId, ['lugares' => ['f:A:3']], 'otra@test.local');
        $codigo = $this->comprar($linkId, ['lugares' => ['f:A:1', 'f:A:2']]);

        $res = EntradasHandler::modificar($this->db, $this->get(['codigo' => $codigo], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertSame(['f:A:3'], $res->body['ocupados']);
        $this->assertSame(['f:A:1', 'f:A:2'], $res->body['orden']['lugares']);
        $this->assertSame(3, $res->body['disponibles'], 'las 4 menos la de la otra compra');
        $this->assertNotNull($res->body['plano']);
    }

    // ===================================================== bordes

    public function testUnLugarQueNoExisteEnElPlanoSeRechaza()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(2)]);
        $codigo = $this->comprar($linkId, ['lugares' => ['f:A:1']]);

        $res = $this->modificar($codigo, ['lugares' => ['f:Z:9']]);

        $this->assertStatus(400, $res);
        $this->assertStatus(400, $this->modificar($codigo, ['cantidad' => 2]), 'con plano se eligen lugares');
    }

    public function testConUnSoloTipoAlcanzaConLaCantidad()
    {
        $linkId = $this->eventoConVenta(['tipos' => [['id' => 'general', 'nombre' => 'General', 'precio' => 0]]]);
        $codigo = $this->comprar($linkId, ['cantidad' => 1]);

        $this->assertStatus(200, $this->modificar($codigo, ['cantidad' => 3]));
        $this->assertSame(['general' => 3], $this->items($codigo));
    }

    public function testConVariosTiposHayQueDecirCuantasDeCadaUno()
    {
        $linkId = $this->eventoConVenta(['tipos' => [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 0],
            ['id' => 'vip', 'nombre' => 'VIP', 'precio' => 0],
        ]]);
        $codigo = $this->comprar($linkId, ['tipos' => ['general' => 1]]);

        $this->assertStatus(400, $this->modificar($codigo, ['cantidad' => 3]));
        $this->assertStatus(400, $this->modificar($codigo, ['tipos' => 'tres']));
        $this->assertStatus(409, $this->modificar($codigo, ['tipos' => ['no-existe' => 1]]));
    }

    public function testSiElEventoYaNoTieneEntradasConfiguradasNoSeModifica()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 1]);
        Entradas::borrarConfig($this->db, $linkId);

        $this->assertStatus(409, $this->modificar($codigo, ['cantidad' => 2]));

        $res = EntradasHandler::modificar($this->db, $this->get(['codigo' => $codigo], $this->user($this->duenaId)));
        $this->assertStatus(409, $res);
    }

    /** Para editar, cada tipo dice cuántas puede tener esta compra contando las suyas. */
    public function testParaEditarCadaTipoDiceCuantasLeQuedanAEstaCompra()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 10, 'tipos' => [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 0, 'cupo' => null],
            ['id' => 'jubilados', 'nombre' => 'Jubilados', 'precio' => 0, 'cupo' => 3],
        ]]);
        $this->comprar($linkId, ['tipos' => ['jubilados' => 2, 'general' => 4]], 'otra@test.local');
        $codigo = $this->comprar($linkId, ['tipos' => ['jubilados' => 1]]);

        $res = EntradasHandler::modificar($this->db, $this->get(['codigo' => $codigo], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertSame(4, $res->body['disponibles']);
        $porId = array_column($res->body['tipos'], 'disponibles', 'id');
        $this->assertSame(['general' => 4, 'jubilados' => 1], $porId);

        $pendiente = EntradasHandler::modificar($this->db, $this->get(['codigo' => 'NOEXISTE00'], $this->user($this->duenaId)));
        $this->assertStatus(404, $pendiente);
    }
}
