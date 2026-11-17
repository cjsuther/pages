<?php

namespace Tests\Integration;

use CheckoutHandler;
use Clientes;
use Cobros;
use CodigoQR;
use Entradas;
use EntradasHandler;
use InvitadosHandler;
use ModificarEntradas;
use Puerta;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;
use VentasCompartidas;

/**
 * Invitados sin costo contra una MariaDB de verdad.
 *
 * Lo que se afirma es lo que quedó en la base y lo que ven las otras
 * pantallas: que el invitado no cobra ni deja comisión, que ocupa lugar, que
 * en la puerta vale una sola vez, que anulado ya no vale, y que las ventas y
 * los reportes no lo cuentan como vendido.
 */
class InvitadosTest extends IntegracionTestCase
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

    /** Un evento que cobra, con la cuenta de Mercado Pago conectada. */
    private function eventoQueCobra(array $config = [])
    {
        $this->conectarPorOAuth();

        return $this->eventoConVenta(array_merge(['precio' => 10000, 'cargos_al_comprador' => false], $config));
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

    private function invitar($linkId, array $datos = [], $userId = null)
    {
        return InvitadosHandler::invitados(
            $this->db,
            $this->post(array_merge(['link_id' => $linkId, 'nombre' => 'Ana Invitada'], $datos), $this->user($userId ?: $this->duenaId)),
            $this->mailer
        );
    }

    /** Invita y devuelve el código. */
    private function invitado($linkId, array $datos = [])
    {
        $res = $this->invitar($linkId, $datos);
        $this->assertStatus(201, $res);

        return $res->body['invitado']['codigo'];
    }

    private function accion($codigo, $accion, $userId = null)
    {
        return InvitadosHandler::invitado(
            $this->db,
            $this->post(['codigo' => $codigo, 'accion' => $accion], $this->user($userId ?: $this->duenaId)),
            $this->mailer
        );
    }

    /** Una compra del público, pagada como la deja el aviso de Mercado Pago. */
    private function compraPagada($linkId, $cantidad = 1)
    {
        $res = CheckoutHandler::comprar($this->db, $this->post([
            'link_id' => $linkId, 'nombre' => 'Beto Comprador', 'email' => 'beto@test.local', 'cantidad' => $cantidad,
        ]), $this->http, $this->mailer);
        $this->assertStatus(201, $res);
        $codigo = $res->body['codigo'];

        Entradas::acreditarPago($this->db, $codigo, 'pago-' . $codigo, 'approved', ['comision_plataforma' => 1000.0, 'neto' => 8000.0]);

        return $codigo;
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM ticket_orders WHERE codigo = ?', [$codigo]);
    }

    private function ventas($linkId)
    {
        $res = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)));
        $this->assertStatus(200, $res);

        return $res->body;
    }

    // ============================================== sin cobro ni comisión

    /**
     * En un evento que cobra y suma comisiones a quien compra, el invitado
     * sale en 0: sin Mercado Pago, sin comisión de Rezonar ni de Mercado
     * Pago, sin cargos. Y nace confirmado, con su mail.
     */
    public function testUnInvitadoNoGeneraCobroNiComision()
    {
        $linkId = $this->eventoQueCobra();
        // La llave de comisiones a cargo de quien compra, prendida en el
        // evento: al invitado no se le suma nada igual.
        $this->db->prepare('UPDATE event_ticketing SET cargos_al_comprador = 1 WHERE link_id = ?')->execute([$linkId]);

        $res = $this->invitar($linkId, ['email' => 'ana@test.local', 'cantidad' => 3]);

        $this->assertStatus(201, $res);
        $orden = $this->orden($res->body['invitado']['codigo']);

        $this->assertSame('invitacion', $orden['origen']);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame(3, (int) $orden['cantidad']);
        $this->assertSame(0.0, (float) $orden['total']);
        $this->assertSame(0.0, (float) $orden['precio_unitario']);
        $this->assertSame(0.0, (float) $orden['comision']);
        $this->assertSame(0.0, (float) $orden['cargo_mercadopago']);
        $this->assertSame(0.0, (float) $orden['cargo_plataforma']);
        $this->assertNull($orden['mp_preference_id']);
        $this->assertNull($orden['mp_payment_id']);
        $this->assertSame($this->duenaId, (int) $orden['invitado_por']);
        $this->assertNotNull($orden['mail_enviado_en']);

        // Ni una llamada a Mercado Pago.
        $this->assertSame([], $this->http->llamadas);

        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame('ana@test.local', $this->mailer->enviados[0]['para']);
        $this->assertStringContainsString('invitación', $this->mailer->enviados[0]['asunto']);

        // El cron no lo conciliaría ni le buscaría desglose de pago.
        $this->assertNotContains($orden['codigo'], Entradas::sinConciliar($this->db));
        $this->assertNotContains($orden['codigo'], array_column(Entradas::pagadasSinDesglose($this->db), 'codigo'));
    }

    /** Las ventas lo muestran, marcado, pero no suma a lo vendido ni a la plata. */
    public function testLasVentasSeparanALosInvitadosDeLoVendido()
    {
        $linkId = $this->eventoQueCobra();
        $this->compraPagada($linkId, 2);
        $antes = $this->ventas($linkId)['resumen'];

        $codigo = $this->invitado($linkId, ['cantidad' => 3]);

        $ventas = $this->ventas($linkId);

        $this->assertSame(2, $ventas['resumen']['vendidas']);
        $this->assertSame(3, $ventas['resumen']['invitados']);
        $this->assertSame(20000.0, $ventas['resumen']['recaudado']);

        // Ni un peso, ni una comisión, ni una "venta sin dato" de más.
        foreach (['vendidas', 'recaudado', 'precio_entradas', 'cargos_al_comprador', 'comision', 'comision_cobrada',
                  'comision_sin_dato', 'comision_mercadopago', 'neto', 'acreditado', 'por_acreditar', 'ventas_sin_dato', 'por_tipo'] as $campo) {
            $this->assertSame($antes[$campo], $ventas['resumen'][$campo], $campo);
        }

        $invitado = array_values(array_filter($ventas['ordenes'], function ($o) use ($codigo) {
            return $o['codigo'] === $codigo;
        }))[0];
        $this->assertTrue($invitado['invitado']);

        // El listado de eventos de la página cuenta igual.
        $eventos = EntradasHandler::eventos($this->db, $this->get(['page_id' => $this->paginaId], $this->user($this->duenaId)))->body['eventos'];
        $this->assertSame(2, $eventos[0]['vendidas']);
        $this->assertSame(3, $eventos[0]['invitados']);
        $this->assertSame(20000.0, $eventos[0]['recaudado']);

        // Y el link compartido: vendidas aparte, pero el lugar está ocupado.
        $compartido = VentasCompartidas::estado($this->db, $linkId)['venta'];
        $this->assertSame(2, $compartido['vendidas']);
        $this->assertSame(3, $compartido['invitados']);
        $this->assertSame(1, $compartido['compras']);
        $this->assertSame(5, $compartido['disponibles']);
    }

    public function testElExcelMarcaAlInvitado()
    {
        $linkId = $this->eventoQueCobra();
        $this->compraPagada($linkId);
        $this->invitado($linkId);

        $res = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId, 'formato' => 'excel'], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $xlsx = tempnam(sys_get_temp_dir(), 'rz');
        file_put_contents($xlsx, $res->raw);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($xlsx));
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml') . $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        unlink($xlsx);

        $this->assertStringContainsString('Origen', $hoja);
        $this->assertStringContainsString('Invitado', $hoja);
        $this->assertStringContainsString('Venta', $hoja);
    }

    /** El reporte de comisiones de la plataforma no ve a los invitados. */
    public function testElReporteDeComisionesNoCuentaInvitados()
    {
        $linkId = $this->eventoQueCobra();
        $this->compraPagada($linkId);
        $this->invitado($linkId, ['cantidad' => 4]);

        $plataforma = $this->usuario('plataforma@test', 'Plataforma');
        \Plataforma::olvidar();
        $res = \PlataformaHandler::comisiones($this->db, $this->get([], $this->user($plataforma, 'plataforma@test')));

        $this->assertStatus(200, $res);
        $this->assertSame(1, $res->body['por_tipo']['entradas']['ventas']);
        $this->assertSame(10000.0, $res->body['por_tipo']['entradas']['recaudado']);
    }

    // ================================================================ cupo

    /** Descuentan de la capacidad: no se puede sobrevender regalando. */
    public function testLosInvitadosDescuentanDeLaCapacidad()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 5]);
        $this->compraPagada($linkId, 2);

        $res = $this->invitar($linkId, ['cantidad' => 4]);
        $this->assertStatus(409, $res);
        $this->assertStringContainsString('Sólo quedan 3', $res->body['error']);

        $this->invitado($linkId, ['cantidad' => 3]);

        $this->assertSame(5, Entradas::ocupadas($this->db, $linkId));
        $this->assertTrue(Entradas::disponibilidad($this->db, $linkId)['agotado']);

        // Y la venta del público ya no tiene lugar.
        $compra = CheckoutHandler::comprar($this->db, $this->post([
            'link_id' => $linkId, 'nombre' => 'Tarde', 'email' => 'tarde@test.local', 'cantidad' => 1,
        ]), $this->http, $this->mailer);
        $this->assertStatus(400, $compra);
        $this->assertStringContainsString('agotaron', $compra->body['error']);
    }

    public function testSePuedeInvitarConLaVentaCerrada()
    {
        $linkId = $this->eventoConVenta(['activo' => 0]);

        $this->assertStatus(201, $this->invitar($linkId));
    }

    public function testLaListaDiceCuantoQueda()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 10]);
        $this->compraPagada($linkId, 2);
        $this->invitado($linkId, ['cantidad' => 3]);

        $lista = InvitadosHandler::invitados($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)))->body;

        $this->assertCount(1, $lista['invitados']);
        $this->assertSame(3, $lista['resumen']['lugares']);
        $this->assertSame(10, $lista['resumen']['capacidad']);
        $this->assertSame(5, $lista['resumen']['disponibles']);
        $this->assertNull($lista['resumen']['max_invitados']);
    }

    public function testElLimiteDeInvitados()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 100]);

        $res = InvitadosHandler::invitados($this->db, $this->put(['link_id' => $linkId, 'max_invitados' => 4], $this->user($this->duenaId)));
        $this->assertStatus(200, $res);
        $this->assertSame(4, $res->body['resumen']['max_invitados']);

        $this->invitado($linkId, ['cantidad' => 3]);

        $res = $this->invitar($linkId, ['cantidad' => 2]);
        $this->assertStatus(409, $res);
        $this->assertStringContainsString('Quedan 1 lugares para invitados', $res->body['error']);

        // No puede bajar de lo que ya se dio.
        $this->assertStatus(409, InvitadosHandler::invitados($this->db, $this->put(['link_id' => $linkId, 'max_invitados' => 2], $this->user($this->duenaId))));

        // Sin límite otra vez.
        $res = InvitadosHandler::invitados($this->db, $this->put(['link_id' => $linkId, 'max_invitados' => null], $this->user($this->duenaId)));
        $this->assertStatus(200, $res);
        $this->assertNull($res->body['resumen']['max_invitados']);
        $this->assertStatus(201, $this->invitar($linkId, ['cantidad' => 5]));
    }

    /** Guardar la configuración de entradas no borra el límite. */
    public function testGuardarLasEntradasNoTocaElLimite()
    {
        $linkId = $this->eventoConVenta();
        InvitadosHandler::invitados($this->db, $this->put(['link_id' => $linkId, 'max_invitados' => 7], $this->user($this->duenaId)));

        $this->assertStatus(200, EntradasHandler::config($this->db, $this->post(
            ['activo' => 1, 'capacidad' => 20, 'precio' => 0, 'max_por_compra' => 10],
            $this->user($this->duenaId),
            ['link_id' => $linkId]
        )));

        $this->assertSame(7, (int) $this->valor('SELECT max_invitados FROM event_ticketing WHERE link_id = ?', [$linkId]));
    }

    // ============================================================= tipos

    public function testConTiposElInvitadoUsaElCupoDeSuTipoSinPrecio()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['tipos' => [
            ['id' => 'general', 'nombre' => 'General', 'precio' => 8000, 'cupo' => null],
            ['id' => 'vip', 'nombre' => 'VIP', 'precio' => 20000, 'cupo' => 2],
        ]]);

        $res = $this->invitar($linkId, ['cantidad' => 2]);
        $this->assertStatus(400, $res);
        $this->assertStringContainsString('tipo', $res->body['error']);

        $codigo = $this->invitado($linkId, ['tipo' => 'vip', 'cantidad' => 2]);

        $item = $this->fila('SELECT i.* FROM ticket_order_items i INNER JOIN ticket_orders o ON o.id = i.order_id WHERE o.codigo = ?', [$codigo]);
        $this->assertSame('vip', $item['tipo']);
        $this->assertSame(0.0, (float) $item['precio_unitario']);
        $this->assertSame(2, Entradas::ocupadasPorTipo($this->db, $linkId)['vip']);

        $this->assertStatus(409, $this->invitar($linkId, ['tipo' => 'vip']));

        // El desglose por tipo de las ventas no lo cuenta como vendido.
        $this->assertSame([], $this->ventas($linkId)['resumen']['por_tipo']);
    }

    // ============================================================= plano

    public function testConPlanoElInvitadoTomaLugares()
    {
        $linkId = $this->eventoConVenta(['plano' => [
            'ancho' => 10, 'alto' => 6,
            'elementos' => [['tipo' => 'fila', 'nombre' => 'A', 'butacas' => 4, 'desde' => 1, 'x' => 0, 'y' => 0]],
        ]]);
        $lugares = Entradas::configDelEvento($this->db, $linkId)['plano'] === null ? [] : \Plano::lugares(Entradas::configDelEvento($this->db, $linkId)['plano']);

        $res = $this->invitar($linkId);
        $this->assertStatus(409, $res);
        $this->assertStringContainsString('plano', $res->body['error']);

        $codigo = $this->invitado($linkId, ['lugares' => [$lugares[0], $lugares[1]]]);

        $this->assertSame(2, (int) $this->orden($codigo)['cantidad']);
        $this->assertEqualsCanonicalizing([$lugares[0], $lugares[1]], Entradas::lugaresOcupados($this->db, $linkId));

        $res = $this->invitar($linkId, ['lugares' => [$lugares[1]]]);
        $this->assertStatus(409, $res);
        $this->assertArrayHasKey('ocupados', $res->body);
    }

    // ============================================================ puerta

    /** En la puerta vale como cualquier entrada, y una sola vez. */
    public function testEnLaPuertaValeUnaSolaVez()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->invitado($linkId, ['cantidad' => 2]);

        $mirada = Puerta::mirar($this->db, $linkId, CodigoQR::urlDeLaOrden($codigo));
        $this->assertSame(Puerta::VALIDA, $mirada['resultado']);
        $this->assertTrue($mirada['orden']['invitado']);

        $this->assertTrue(Puerta::ingresar($this->db, $linkId, $codigo, 2)['ok']);

        $otra = Puerta::ingresar($this->db, $linkId, $codigo, 1);
        $this->assertFalse($otra['ok']);
        $this->assertSame(Puerta::YA_ENTRO, $otra['resultado']);

        // La lista de la puerta lo trae marcado, y la de invitados dice que entró.
        $lista = Puerta::lista($this->db, $linkId);
        $this->assertTrue($lista['ordenes'][0]['invitado']);

        $invitados = InvitadosHandler::invitados($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)))->body;
        $this->assertSame(2, $invitados['invitados'][0]['ingresadas']);
        $this->assertSame(2, $invitados['resumen']['ingresaron']);
    }

    public function testEnOtroEventoNoVale()
    {
        $linkId = $this->eventoConVenta();
        $otro = $this->eventoConVenta();
        $codigo = $this->invitado($linkId);

        $this->assertSame(Puerta::OTRO_EVENTO, Puerta::mirar($this->db, $otro, $codigo)['resultado']);
    }

    // ===================================================== anular y reenviar

    /** Anulado, su QR ya no vale y el lugar vuelve al cupo. */
    public function testAnularInvalidaElQrYLiberaElLugar()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 2]);
        $codigo = $this->invitado($linkId, ['cantidad' => 2]);
        $this->assertSame(2, Entradas::ocupadas($this->db, $linkId));

        $res = $this->accion($codigo, 'anular');

        $this->assertStatus(200, $res);
        $this->assertSame('anulado', $res->body['invitados'][0]['estado']);
        $this->assertSame(0, Entradas::ocupadas($this->db, $linkId));

        $mirada = Puerta::mirar($this->db, $linkId, $codigo);
        $this->assertSame(Puerta::NO_PAGADA, $mirada['resultado']);
        $this->assertFalse(Puerta::ingresar($this->db, $linkId, $codigo, 1)['ok']);

        // Anular dos veces no libera el doble.
        $this->assertStatus(409, $this->accion($codigo, 'anular'));
    }

    public function testLaAccionDeInvitadosNoCancelaCompras()
    {
        $linkId = $this->eventoConVenta();
        $compra = $this->compraPagada($linkId);

        $this->assertStatus(404, $this->accion($compra, 'anular'));
        $this->assertSame('pagada', $this->orden($compra)['estado']);
    }

    public function testReenviarMandaLaMismaEntradaOtraVez()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->invitado($linkId, ['email' => 'ana@test.local']);
        $this->assertCount(1, $this->mailer->enviados);

        $res = $this->accion($codigo, 'reenviar');

        $this->assertStatus(200, $res);
        $this->assertCount(2, $this->mailer->enviados);
        $this->assertStringContainsString($codigo, $this->mailer->enviados[1]['texto']);
    }

    public function testReenviarAUnAnuladoNo()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->invitado($linkId, ['email' => 'ana@test.local']);
        $this->accion($codigo, 'anular');

        $this->assertStatus(409, $this->accion($codigo, 'reenviar'));
        $this->assertCount(1, $this->mailer->enviados);
    }

    /** Sin email no se manda nada, ni ahora ni desde el cron. */
    public function testSinEmailNoHayMailNiDespues()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->invitado($linkId);

        $this->assertSame([], $this->mailer->enviados);
        $this->assertNotContains($codigo, \CorreoEntradas::pendientes($this->db));
        $this->assertSame('', $this->orden($codigo)['email']);
    }

    public function testUnInvitadoNoSeModificaComoCompra()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->invitado($linkId);

        $r = ModificarEntradas::datosParaEditar($this->db, $codigo);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('invitados', $r['error']);

        // Y por el POST, que lee la orden bloqueada por su cuenta.
        $res = EntradasHandler::modificar($this->db, $this->post(['codigo' => $codigo, 'cantidad' => 5], $this->user($this->duenaId)), $this->mailer);
        $this->assertStatus(409, $res);
        $this->assertSame(1, (int) $this->orden($codigo)['cantidad']);
    }

    // =========================================================== permisos

    public function testUnAdministradorDeLaPaginaPuedeInvitar()
    {
        $linkId = $this->eventoConVenta();
        $admin = $this->usuario('admin@test.local', 'Admin');
        $this->insertar('page_admins', ['page_id' => $this->paginaId, 'user_id' => $admin, 'status' => 'accepted', 'invited_by' => $this->duenaId]);

        $this->assertStatus(201, $this->invitar($linkId, [], $admin));
    }

    public function testUnAdministradorPendienteNo()
    {
        $linkId = $this->eventoConVenta();
        $admin = $this->usuario('admin@test.local', 'Admin');
        $this->insertar('page_admins', ['page_id' => $this->paginaId, 'user_id' => $admin, 'status' => 'pending', 'invited_by' => $this->duenaId]);

        $this->assertStatus(403, $this->invitar($linkId, [], $admin));
    }

    /**
     * Una página colaboradora muestra el evento, pero no lo organiza: no
     * puede regalar lugares de la capacidad de otro.
     */
    public function testUnaPaginaColaboradoraNoPuedeInvitar()
    {
        $linkId = $this->eventoConVenta();
        $socia = $this->usuario('socia@test.local', 'Socia');
        $paginaSocia = $this->pagina($socia, 'la-socia', 'La Socia');
        $this->insertar('event_collaborations', [
            'link_id' => $linkId, 'collaborator_page_id' => $paginaSocia, 'requester_page_id' => $this->paginaId, 'status' => 'accepted',
        ]);

        $this->assertStatus(403, $this->invitar($linkId, [], $socia));
        $this->assertStatus(403, InvitadosHandler::invitados($this->db, $this->get(['link_id' => $linkId], $this->user($socia))));

        $codigo = $this->invitado($linkId);
        $this->assertStatus(403, $this->accion($codigo, 'anular', $socia));
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
    }

    /** El acceso de soporte de la plataforma tampoco: es una decisión de la página. */
    public function testLaPlataformaNoInvitaPorLaPagina()
    {
        $linkId = $this->eventoConVenta();
        $plataforma = $this->usuario('plataforma@test', 'Plataforma');
        \Plataforma::olvidar();

        $this->assertStatus(403, $this->invitar($linkId, [], $plataforma));
    }

    // ===================================================== otras pantallas

    /** La página pública de la entrada dice que es una invitación. */
    public function testLaEntradaPublicaDiceQueEsInvitacion()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->invitado($linkId);

        $res = CheckoutHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http, $this->mailer);

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['orden']['invitacion']);
        $this->assertSame('pagada', $res->body['orden']['estado']);
        $this->assertSame(0.0, $res->body['orden']['total']);
    }

    public function testClientesNoJuntaInvitadosSinEmail()
    {
        $linkId = $this->eventoConVenta();
        $this->invitado($linkId, ['nombre' => 'Uno']);
        $this->invitado($linkId, ['nombre' => 'Dos']);
        $this->invitado($linkId, ['nombre' => 'Tres', 'email' => 'tres@test.local']);

        $emails = array_column(Clientes::dePagina($this->db, $this->paginaId)['clientes'], 'email');

        $this->assertNotContains('', $emails);
        $this->assertContains('tres@test.local', $emails);
    }
}
