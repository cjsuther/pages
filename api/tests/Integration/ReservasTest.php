<?php

namespace Tests\Integration;

use CheckoutHandler;
use ClientesHandler;
use Cobros;
use CodigoQR;
use Entradas;
use EntradasHandler;
use LinksHandler;
use Puerta;
use PuertaHandler;
use Tests\Support\FakeHttpClient;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * La reserva y la venta de entradas, contra una MariaDB de verdad.
 *
 * Es la parte que no se puede romper: cuando falla, la gente no puede entrar a
 * un show y quien organiza se entera por WhatsApp. El 29/9 se cayó durante 16
 * horas por una tabla que no existía en producción, y los tests unitarios
 * pasaban porque FakePdo no sabe de tablas. Acá cada consulta corre sobre el
 * esquema que arman las migraciones, y lo que se afirma es lo que quedó
 * escrito en la base, no sólo lo que respondió el handler.
 *
 * Mercado Pago y el correo son dobles: nunca sale nada a la red.
 */
class ReservasTest extends IntegracionTestCase
{
    /** @var int */
    private $duenaId;
    /** @var int */
    private $paginaId;
    /** @var int */
    private $grupoId;
    /** @var FakeHttpClient */
    private $http;
    /** @var FakeMailer */
    private $mailer;

    /**
     * La librería del QR emite avisos de deprecación en PHP 8.5 la primera vez
     * que se carga. Con beStrictAboutOutputDuringTests, eso volvería "risky" al
     * primer test que mande una entrada. Se carga acá, sin salida.
     */
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

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId);
        $this->http = new FakeHttpClient();
        $this->mailer = new FakeMailer();
    }

    // ============================================================ fixtures

    /** Un evento con venta configurada por el mismo endpoint que usa el editor. */
    private function eventoConVenta(array $config = [], array $evento = [])
    {
        $linkId = $this->evento($this->grupoId, $evento);

        $res = EntradasHandler::config($this->db, $this->post(array_merge([
            'activo' => 1, 'capacidad' => 10, 'precio' => 0, 'max_por_compra' => 10,
        ], $config), $this->user($this->duenaId), ['link_id' => $linkId]));

        $this->assertStatus(200, $res);

        return $linkId;
    }

    /** Conecta Mercado Pago como lo deja el OAuth: con split de comisión. */
    private function conectarPorOAuth($pageId = null)
    {
        $r = Cobros::guardarDesdeOAuth($this->db, $pageId ?: $this->paginaId, [
            'user_id'       => '987654',
            'access_token'  => 'APP_USR-1234567890-token-de-prueba',
            'refresh_token' => 'TG-refresh-de-prueba',
            'public_key'    => 'APP_USR-public-key',
            'modo'          => 'produccion',
            'expira_en'     => gmdate('Y-m-d H:i:s', time() + 90 * 86400),
        ]);

        $this->assertTrue($r['ok']);
    }

    /** Una credencial pegada a mano: cobra, pero Mercado Pago ignora la comisión. */
    private function conectarAMano()
    {
        $this->insertar('page_payment_settings', [
            'page_id'              => $this->paginaId,
            'access_token_cifrado' => \Cripto::cifrar('APP_USR-manual-token'),
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
            'transaction_details' => ['net_received_amount' => round($total * 0.85, 2)],
            'fee_details' => [
                ['type' => 'mercadopago_fee', 'amount' => round($total * 0.05, 2)],
                ['type' => 'application_fee', 'amount' => round($total * 0.10, 2)],
            ],
            'money_release_date' => '2026-10-10T10:00:00.000-04:00',
        ], $extra));
    }

    private function comprar($linkId, array $datos = [])
    {
        return CheckoutHandler::comprar($this->db, $this->post(array_merge([
            'link_id' => $linkId,
            'nombre' => 'Ana Compradora',
            'email' => 'ana@test.local',
            'telefono' => '11 5555-1234',
            'cantidad' => 1,
        ], $datos)), $this->http, $this->mailer);
    }

    private function aviso($codigo, $pagoId = '555001')
    {
        return CheckoutHandler::aviso(
            $this->db,
            $this->post(['type' => 'payment', 'data' => ['id' => $pagoId]], null, ['orden' => $codigo]),
            $this->http,
            $this->mailer
        );
    }

    private function orden($codigo)
    {
        return $this->fila('SELECT * FROM ticket_orders WHERE codigo = ?', [$codigo]);
    }

    private function cantidadDeOrdenes()
    {
        return (int) $this->valor('SELECT COUNT(*) FROM ticket_orders');
    }

    private function planoDeUnaFila($butacas = 3)
    {
        return [
            'ancho' => 10, 'alto' => 6,
            'elementos' => [['tipo' => 'fila', 'nombre' => 'A', 'butacas' => $butacas, 'desde' => 1, 'x' => 0, 'y' => 0]],
        ];
    }

    // ===================================================== reserva gratis

    /**
     * La reserva de un evento sin precio se confirma en el acto, y queda atada
     * al registro del evento: es lo que la hace sobrevivir si el evento se
     * borra. Es exactamente el paso que se cayó el 29/9.
     */
    public function testUnaReservaGratisQuedaConfirmadaYAtadaAlRegistroDelEvento()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 50], ['text' => 'Corta la Semana']);

        $res = $this->comprar($linkId, ['cantidad' => 2]);

        $this->assertStatus(201, $res);
        $this->assertSame('pagada', $res->body['estado']);
        $this->assertNull($res->body['url']);

        $orden = $this->orden($res->body['codigo']);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame(2, (int) $orden['cantidad']);
        $this->assertSame('0.00', $orden['total']);
        $this->assertNotNull($orden['pagada_en']);
        $this->assertNull($orden['reserva_vence_en']);
        $this->assertSame('11 5555-1234', $orden['telefono']);

        $registro = $this->fila('SELECT * FROM event_records WHERE id = ?', [$orden['record_id']]);
        $this->assertNotNull($registro, 'la orden tiene que apuntar a un registro que exista');
        $this->assertSame($linkId, (int) $registro['link_id']);
        $this->assertSame($this->paginaId, (int) $registro['page_id']);
        $this->assertSame('Corta la Semana', $registro['titulo']);

        $this->assertSame('organizador', $this->valor(
            'SELECT rol FROM event_record_pages WHERE record_id = ? AND page_id = ?',
            [$registro['id'], $this->paginaId]
        ));
    }

    /** Dos reservas del mismo evento comparten registro: no se crea uno por compra. */
    public function testLasReservasDeUnMismoEventoCompartenRegistro()
    {
        $linkId = $this->eventoConVenta();

        $a = $this->comprar($linkId, ['email' => 'a@test.local']);
        $b = $this->comprar($linkId, ['email' => 'b@test.local']);

        $this->assertSame($this->orden($a->body['codigo'])['record_id'], $this->orden($b->body['codigo'])['record_id']);
        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM event_records'));
        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM event_record_pages'));
    }

    public function testLaReservaGratisMandaLaEntradaPorMail()
    {
        $linkId = $this->eventoConVenta([], ['text' => 'Noche de Jazz']);

        $res = $this->comprar($linkId);

        $mensaje = $this->mailer->mensajePara('ana@test.local');
        $this->assertNotNull($mensaje, 'la entrada tiene que salir por mail');
        $this->assertSame('Tu entrada para Noche de Jazz', $mensaje['asunto']);
        $this->assertStringContainsString($res->body['codigo'], $mensaje['texto']);

        $orden = $this->orden($res->body['codigo']);
        $this->assertNotNull($orden['mail_enviado_en']);
        $this->assertSame(1, (int) $orden['mail_intentos']);
    }

    /** Si el correo falla, la reserva queda hecha y el mail pendiente para el cron. */
    public function testSiElMailFallaLaReservaQuedaIgual()
    {
        $linkId = $this->eventoConVenta();
        $this->mailer->fallarCon('SMTP caído');

        $res = $this->comprar($linkId);

        $this->assertStatus(201, $res);
        $orden = $this->orden($res->body['codigo']);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertNull($orden['mail_enviado_en']);
        $this->assertSame('SMTP caído', $orden['mail_error']);
    }

    public function testLaReservaDescuentaDelCupo()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 5]);

        $this->comprar($linkId, ['cantidad' => 3]);

        $disponibilidad = Entradas::disponibilidad($this->db, $linkId);
        $this->assertSame(5, $disponibilidad['capacidad']);
        $this->assertSame(2, $disponibilidad['disponibles']);
        $this->assertSame(2, $disponibilidad['max_por_compra']);
        $this->assertFalse($disponibilidad['agotado']);
        $this->assertTrue($disponibilidad['es_gratis']);
    }

    // ============================================================ rechazos

    public function testSinCupoNoSeEscribeNingunaOrden()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 2]);
        $this->comprar($linkId, ['cantidad' => 2]);

        $res = $this->comprar($linkId, ['email' => 'tarde@test.local']);

        $this->assertStatus(400, $res);
        $this->assertSame('Se agotaron las entradas', $res->body['error']);
        $this->assertSame(1, $this->cantidadDeOrdenes());
        $this->assertTrue(Entradas::disponibilidad($this->db, $linkId)['agotado']);
    }

    public function testSiQuedanMenosDeLasPedidasLoDiceSinVenderNinguna()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 3]);
        $this->comprar($linkId, ['cantidad' => 2]);

        $res = $this->comprar($linkId, ['cantidad' => 2, 'email' => 'otra@test.local']);

        $this->assertStatus(400, $res);
        $this->assertSame('Sólo quedan 1 entradas', $res->body['error']);
        $this->assertSame(2, Entradas::ocupadas($this->db, $linkId));
    }

    public function testNoSePuedePasarDelMaximoPorCompra()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 100, 'max_por_compra' => 4]);

        $res = $this->comprar($linkId, ['cantidad' => 5]);

        $this->assertStatus(400, $res);
        $this->assertSame('El máximo por compra es 4', $res->body['error']);
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    /** Los datos inválidos se rechazan antes de abrir la transacción: no queda nada escrito. */
    public function testConDatosInvalidosNoSeEscribeNada()
    {
        $linkId = $this->eventoConVenta();

        $casos = [
            [['nombre' => '  '], 'Falta el nombre y apellido'],
            [['email' => 'no-es-un-mail'], 'El email no es válido'],
            [['email' => ''], 'El email no es válido'],
            [['telefono' => '12'], 'El teléfono no es válido'],
            [['cantidad' => 0], 'Hay que pedir al menos una entrada'],
        ];

        foreach ($casos as list($datos, $error)) {
            $res = $this->comprar($linkId, $datos);
            $this->assertStatus(400, $res);
            $this->assertSame($error, $res->body['error']);
        }

        $this->assertSame(0, $this->cantidadDeOrdenes());
        $this->assertSame(0, (int) $this->valor('SELECT COUNT(*) FROM event_records'));
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testElTelefonoEsOpcional()
    {
        $linkId = $this->eventoConVenta();

        $res = $this->comprar($linkId, ['telefono' => '']);

        $this->assertStatus(201, $res);
        $this->assertSame('', $this->orden($res->body['codigo'])['telefono']);
    }

    public function testUnEventoConLaVentaPausadaNoVende()
    {
        $linkId = $this->eventoConVenta(['activo' => 0]);

        $res = $this->comprar($linkId);

        $this->assertStatus(400, $res);
        $this->assertSame('Este evento no vende entradas', $res->body['error']);
        $this->assertNull(Entradas::disponibilidad($this->db, $linkId));
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    public function testUnEventoSinVentaConfiguradaNoVende()
    {
        $linkId = $this->evento($this->grupoId);

        $res = $this->comprar($linkId);

        $this->assertStatus(400, $res);
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    /** Un link común no es un evento, aunque alguien le mande su id. */
    public function testUnLinkQueNoEsEventoNoVende()
    {
        $links = $this->grupo($this->paginaId, 'links', 'Redes');
        $linkId = $this->evento($links);
        $this->insertar('event_ticketing', ['link_id' => $linkId, 'activo' => 1, 'capacidad' => 10, 'precio' => 0]);

        $res = $this->comprar($linkId);

        $this->assertStatus(404, $res);
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    public function testSinLinkIdNoHayCompra()
    {
        $this->assertStatus(400, CheckoutHandler::comprar($this->db, $this->post(['nombre' => 'x']), $this->http, $this->mailer));
        $this->assertStatus(404, $this->comprar(999999));
        $this->assertStatus(405, CheckoutHandler::comprar($this->db, $this->get(), $this->http, $this->mailer));
    }

    // ========================================================= compra paga

    /**
     * La compra paga toma el cupo por 15 minutos mientras la persona paga en
     * Mercado Pago: si se tomara al volver, el evento se sobrevendería.
     */
    public function testUnaCompraPagaReservaElCupoMientrasSePaga()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 12000, 'capacidad' => 3]);
        $this->preferenciaOk();

        $res = $this->comprar($linkId, ['cantidad' => 2]);

        $this->assertStatus(201, $res);
        $this->assertSame('reservada', $res->body['estado']);
        $this->assertSame(24000.0, $res->body['total']);
        $this->assertStringContainsString('pref-123', $res->body['url']);

        $orden = $this->orden($res->body['codigo']);
        $this->assertSame('reservada', $orden['estado']);
        $this->assertSame('pref-123', $orden['mp_preference_id']);
        $this->assertSame('12000.00', $orden['precio_unitario']);
        $this->assertSame('24000.00', $orden['total']);
        $this->assertNull($orden['pagada_en']);
        $this->assertNotNull($orden['record_id']);

        // Vence dentro de 15 minutos, medido por la base, que es quien decide.
        $this->assertSame('1', (string) $this->valor(
            'SELECT reserva_vence_en > NOW() AND reserva_vence_en <= NOW() + INTERVAL 16 MINUTE FROM ticket_orders WHERE codigo = ?',
            [$res->body['codigo']]
        ));

        $this->assertSame(2, Entradas::ocupadas($this->db, $linkId));
        $this->assertSame([], $this->mailer->enviados, 'la entrada sale recién cuando se paga');
    }

    /** Lo que se le manda a Mercado Pago: el precio, la referencia y a dónde avisar. */
    public function testLaPreferenciaLlevaLaOrdenYLaUrlDeAviso()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 5000], ['text' => 'Recital']);
        $this->preferenciaOk();

        $res = $this->comprar($linkId, ['cantidad' => 3]);

        $cuerpo = $this->http->jsonDe('/checkout/preferences');
        $this->assertSame($res->body['codigo'], $cuerpo['external_reference']);
        $this->assertSame(3, $cuerpo['items'][0]['quantity']);
        $this->assertEquals(5000, $cuerpo['items'][0]['unit_price']);
        $this->assertSame('https://rezonar.test/api/public/aviso-pago.php?orden=' . $res->body['codigo'], $cuerpo['notification_url']);
    }

    /** Con OAuth se pide la comisión y queda congelada en la orden. */
    public function testConOAuthSeCongelaLaComisionEnLaOrden()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();

        $res = $this->comprar($linkId, ['cantidad' => 2]);

        $orden = $this->orden($res->body['codigo']);
        $this->assertSame('2000.00', $orden['comision'], '10% de 20.000 con PLATFORM_FEE_PERCENT de test');
        $this->assertSame('10.00', $orden['comision_porcentaje']);
        $this->assertEquals(2000, $this->http->jsonDe('/checkout/preferences')['marketplace_fee']);
    }

    /** Con una credencial pegada a mano Mercado Pago ignora la comisión: no se pide ni se anota. */
    public function testConCredencialManualNoSePideComision()
    {
        $this->conectarAMano();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();

        $res = $this->comprar($linkId);

        $this->assertStatus(201, $res);
        $orden = $this->orden($res->body['codigo']);
        $this->assertSame('0.00', $orden['comision']);
        $this->assertSame('0.00', $orden['comision_porcentaje']);
        $this->assertArrayNotHasKey('marketplace_fee', $this->http->jsonDe('/checkout/preferences'));
    }

    /** Sin link de pago la orden no sirve: se cancela para no retener cupo 15 minutos. */
    public function testSiMercadoPagoRechazaLaPreferenciaSeLiberaElCupo()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000, 'capacidad' => 1]);
        $this->http->responde('/checkout/preferences', 400, ['message' => 'invalid']);

        $res = $this->comprar($linkId);

        $this->assertStatus(502, $res);
        $this->assertSame('cancelada', $this->valor('SELECT estado FROM ticket_orders'));
        $this->assertSame(0, Entradas::ocupadas($this->db, $linkId));
    }

    public function testSinCredencialesDeCobroNoSeVendeYSeLiberaElCupo()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000, 'capacidad' => 1]);
        Cobros::borrar($this->db, $this->paginaId);

        $res = $this->comprar($linkId);

        $this->assertStatus(503, $res);
        $this->assertSame('cancelada', $this->valor('SELECT estado FROM ticket_orders'));
        $this->assertSame(0, Entradas::ocupadas($this->db, $linkId));
    }

    /** Poner precio sin Mercado Pago conectado dejaría el checkout roto. */
    public function testNoSePuedePonerPrecioSinMercadoPagoConectado()
    {
        $linkId = $this->evento($this->grupoId);

        $res = EntradasHandler::config($this->db, $this->post(
            ['activo' => 1, 'capacidad' => 10, 'precio' => 5000],
            $this->user($this->duenaId),
            ['link_id' => $linkId]
        ));

        $this->assertStatus(400, $res);
        $this->assertFalse($this->valor('SELECT 1 FROM event_ticketing WHERE link_id = ?', [$linkId]));
    }

    // =============================================================== aviso

    /** El aviso aprobado acredita con los números que dice Mercado Pago y manda la entrada. */
    public function testElAvisoAprobadoAcreditaYMandaLaEntrada()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago($codigo, 10000);

        $res = $this->aviso($codigo);

        $this->assertStatus(200, $res);
        $this->assertSame('pago acreditado', $res->body['motivo']);

        $orden = $this->orden($codigo);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame('555001', $orden['mp_payment_id']);
        $this->assertNotNull($orden['pagada_en']);
        $this->assertSame('8500.00', $orden['mp_neto']);
        $this->assertSame('1500.00', $orden['mp_comisiones']);
        $this->assertSame('1000.00', $orden['mp_comision_cobrada']);
        $this->assertStringStartsWith('2026-10-10 ', $orden['acreditacion_en']);
        $this->assertNotNull($orden['mail_enviado_en']);
        $this->assertNotNull($this->mailer->mensajePara('ana@test.local'));
    }

    /**
     * Mercado Pago manda la fecha de acreditación con su zona (-04:00) y la
     * base de producción corre en UTC. Se guardaba sin convertir: 10:00 -04:00
     * quedaba como 10:00 UTC, cuatro horas antes de lo que dijo Mercado Pago.
     */
    public function testLaFechaDeAcreditacionSeGuardaEnLaZonaDeLaBase()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago($codigo, 10000);
        $this->aviso($codigo);

        $this->assertSame('2026-10-10 14:00:00', $this->orden($codigo)['acreditacion_en']);
    }

    /** Mercado Pago reintenta los avisos: el segundo no puede acreditar ni mandar otra entrada. */
    public function testElAvisoRepetidoNoAcreditaDosVeces()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago($codigo, 10000);
        $this->pagoDeMercadoPago($codigo, 10000);

        $this->aviso($codigo);
        $res = $this->aviso($codigo);

        $this->assertSame('ya estaba pagada', $res->body['motivo']);
        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame(1, (int) $this->orden($codigo)['mail_intentos']);
    }

    public function testElAvisoRechazadoLiberaElCupo()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000, 'capacidad' => 1]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago($codigo, 10000, 'rejected');

        $res = $this->aviso($codigo);

        $this->assertSame('pago rechazado', $res->body['motivo']);
        $this->assertSame('rechazada', $this->orden($codigo)['estado']);
        $this->assertSame(0, Entradas::ocupadas($this->db, $linkId));
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testUnPagoEnCursoMantieneLaReserva()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago($codigo, 10000, 'in_process');

        $this->assertSame('pago todavía en curso', $this->aviso($codigo)->body['motivo']);
        $this->assertSame('reservada', $this->orden($codigo)['estado']);
        $this->assertSame(1, Entradas::ocupadas($this->db, $linkId));
    }

    /** El monto lo dice Mercado Pago: si no coincide con la orden, algo se manipuló. */
    public function testUnPagoPorOtroMontoNoAcredita()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago($codigo, 10);

        $res = $this->aviso($codigo);

        $this->assertSame('el monto no coincide con la orden', $res->body['motivo']);
        $this->assertSame('reservada', $this->orden($codigo)['estado']);
    }

    public function testUnPagoDeOtraOrdenNoAcredita()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->pagoDeMercadoPago('OTRACOSA', 10000);

        $this->assertSame('el pago es de otra orden', $this->aviso($codigo)->body['motivo']);
        $this->assertSame('reservada', $this->orden($codigo)['estado']);
    }

    /** Un aviso que no aplica responde 200 igual: si no, Mercado Pago reintenta para siempre. */
    public function testLosAvisosQueNoAplicanRespondenDoscientos()
    {
        $this->assertSame('orden inexistente', $this->aviso('NOEXISTE')->body['motivo']);

        $res = CheckoutHandler::aviso($this->db, $this->post([], null, ['orden' => 'X']), $this->http, $this->mailer);
        $this->assertStatus(200, $res);
        $this->assertSame('aviso sin datos utilizables', $res->body['motivo']);
    }

    /** Si Mercado Pago no contesta, conviene que reintente: 503. */
    public function testSiNoSePuedeConsultarElPagoPideQueReintente()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->http->responde('/v1/payments/', 500, '');

        $this->assertStatus(503, $this->aviso($codigo));
        $this->assertSame('reservada', $this->orden($codigo)['estado']);
    }

    // ================================================== vencimiento y orden

    /**
     * Una reserva vencida deja de ocupar lugar sin que nadie la limpie: la
     * consulta la ignora por fecha. Se vence desde la base, con su propio NOW().
     */
    public function testUnaReservaVencidaNoOcupaLugar()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000, 'capacidad' => 1]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->assertTrue(Entradas::disponibilidad($this->db, $linkId)['agotado']);

        $this->db->prepare('UPDATE ticket_orders SET reserva_vence_en = NOW() - INTERVAL 1 MINUTE WHERE codigo = ?')
            ->execute([$codigo]);

        $this->assertSame(0, Entradas::ocupadas($this->db, $linkId));

        $this->preferenciaOk();
        $otra = $this->comprar($linkId, ['email' => 'otra@test.local']);
        $this->assertStatus(201, $otra);
    }

    /** Pagar tarde se acredita igual: dejar afuera a quien pagó sería peor. */
    public function testUnPagoQueLlegaDespuesDelVencimientoSeAcreditaIgual()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->db->prepare('UPDATE ticket_orders SET reserva_vence_en = NOW() - INTERVAL 1 MINUTE WHERE codigo = ?')
            ->execute([$codigo]);
        $this->pagoDeMercadoPago($codigo, 10000);

        $this->assertSame('pago acreditado', $this->aviso($codigo)->body['motivo']);
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
    }

    /** La pantalla a la que vuelve el comprador: sin datos de contacto, con el estado real. */
    public function testLaOrdenSeConsultaSinDatosDeContacto()
    {
        $linkId = $this->eventoConVenta([], ['text' => 'Show']);
        $codigo = $this->comprar($linkId, ['cantidad' => 2])->body['codigo'];

        $res = CheckoutHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http, $this->mailer);

        $this->assertStatus(200, $res);
        $this->assertSame('pagada', $res->body['orden']['estado']);
        $this->assertSame(2, $res->body['orden']['cantidad']);
        $this->assertSame('Show', $res->body['orden']['evento']);
        $this->assertSame('La Sala', $res->body['orden']['pagina']);
        $this->assertArrayNotHasKey('email', $res->body['orden']);
        $this->assertArrayNotHasKey('telefono', $res->body['orden']);

        $this->assertStatus(404, CheckoutHandler::orden($this->db, $this->get(['codigo' => 'NOEXISTE'])));
        $this->assertStatus(400, CheckoutHandler::orden($this->db, $this->get()));
    }

    public function testUnaReservaVencidaSeMuestraComoVencida()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->db->prepare('UPDATE ticket_orders SET reserva_vence_en = NOW() - INTERVAL 1 MINUTE WHERE codigo = ?')
            ->execute([$codigo]);
        $this->http->responde('/v1/payments/search', 200, ['results' => []]);

        $res = CheckoutHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http, $this->mailer);

        $this->assertSame('vencida', $res->body['orden']['estado']);
        $this->assertSame('reservada', $this->orden($codigo)['estado'], 'en la base no se toca');
    }

    /**
     * Si el aviso no llegó, la pantalla del comprador le pregunta a Mercado
     * Pago por la referencia y acredita ahí mismo.
     */
    public function testLaOrdenConciliaUnPagoCuyoAvisoNoLlego()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->http->responde('/v1/payments/search', 200, ['results' => [[
            'id' => 777, 'status' => 'approved', 'external_reference' => $codigo, 'transaction_amount' => 10000,
        ]]]);

        $res = CheckoutHandler::orden($this->db, $this->get(['codigo' => $codigo]), $this->http, $this->mailer);

        $this->assertSame('pagada', $res->body['orden']['estado']);
        $orden = $this->orden($codigo);
        $this->assertSame('pagada', $orden['estado']);
        $this->assertSame('777', $orden['mp_payment_id']);
        $this->assertNotNull($this->mailer->mensajePara('ana@test.local'));
        $this->assertSame([], Entradas::sinConciliar($this->db), 'ya no queda para conciliar');
    }

    public function testLasReservasSinPagoQuedanParaConciliar()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000]);
        $this->preferenciaOk();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $this->comprar($linkId, ['email' => 'gratis@test.local', 'cantidad' => 1]);

        $this->assertSame([$codigo], Entradas::sinConciliar($this->db));
    }

    // ================================================================ plano

    /** Con plano no se pide cantidad: se eligen lugares, y cada uno queda anotado. */
    public function testConPlanoSeEligenLugaresYQuedanAnotados()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(3)]);
        $this->assertSame(3, (int) $this->valor('SELECT capacidad FROM event_ticketing WHERE link_id = ?', [$linkId]),
            'con plano la capacidad es la cantidad de lugares');

        $res = $this->comprar($linkId, ['lugares' => ['f:A:1', 'f:A:2'], 'cantidad' => 99]);

        $this->assertStatus(201, $res);
        $orden = $this->orden($res->body['codigo']);
        $this->assertSame(2, (int) $orden['cantidad'], 'la cantidad sale de los lugares elegidos');

        $lugares = $this->db->query('SELECT order_id, link_id, lugar FROM ticket_order_lugares ORDER BY id')->fetchAll();
        $this->assertCount(2, $lugares);
        $this->assertSame(['f:A:1', 'f:A:2'], array_column($lugares, 'lugar'));
        $this->assertSame((string) $orden['id'], (string) $lugares[0]['order_id']);
        $this->assertSame((string) $linkId, (string) $lugares[0]['link_id']);

        $disponibilidad = Entradas::disponibilidad($this->db, $linkId);
        $this->assertSame(['f:A:1', 'f:A:2'], $disponibilidad['ocupados']);
        $this->assertSame(1, $disponibilidad['disponibles']);
    }

    /** Si alguien se adelantó, se rechaza con los ocupados de ahora para refrescar el plano. */
    public function testUnLugarYaTomadoSeRechazaConLosOcupados()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(3)]);
        $this->comprar($linkId, ['lugares' => ['f:A:2']]);

        $res = $this->comprar($linkId, ['lugares' => ['f:A:2', 'f:A:3'], 'email' => 'tarde@test.local']);

        $this->assertStatus(409, $res);
        $this->assertStringContainsString('Alguien acaba de tomar', $res->body['error']);
        $this->assertSame(['f:A:2'], $res->body['ocupados']);
        $this->assertSame(1, $this->cantidadDeOrdenes());
        $this->assertSame(1, (int) $this->valor('SELECT COUNT(*) FROM ticket_order_lugares'));
    }

    public function testUnLugarQueNoExisteSeRechaza()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(3)]);

        $res = $this->comprar($linkId, ['lugares' => ['f:Z:9']]);

        $this->assertStatus(400, $res);
        $this->assertStringContainsString('no existe en el plano', $res->body['error']);
        $this->assertSame(0, $this->cantidadDeOrdenes());
    }

    public function testConPlanoHayQueElegirLugares()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(3)]);

        $res = $this->comprar($linkId, ['cantidad' => 1]);

        $this->assertStatus(400, $res);
        $this->assertSame('Elegí tus lugares en el plano', $res->body['error']);
    }

    public function testSinPlanoNoSeAceptanLugares()
    {
        $linkId = $this->eventoConVenta();

        $res = $this->comprar($linkId, ['lugares' => ['f:A:1']]);

        $this->assertStatus(400, $res);
        $this->assertSame('Este evento no tiene lugares numerados', $res->body['error']);
    }

    /** Un lugar vendido no puede desaparecer del plano: la persona llega con esa butaca. */
    public function testNoSePuedeSacarDelPlanoUnLugarVendido()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(3)]);
        $this->comprar($linkId, ['lugares' => ['f:A:3']]);

        $res = EntradasHandler::config($this->db, $this->post(
            ['activo' => 1, 'capacidad' => 2, 'precio' => 0, 'plano' => $this->planoDeUnaFila(2)],
            $this->user($this->duenaId),
            ['link_id' => $linkId]
        ));

        $this->assertStatus(400, $res);
        $this->assertStringContainsString('no se pueden sacar del plano', $res->body['error']);
        $this->assertSame(3, (int) $this->valor('SELECT capacidad FROM event_ticketing WHERE link_id = ?', [$linkId]));
    }

    public function testNoSePuedeBajarLaCapacidadPorDebajoDeLoVendido()
    {
        $linkId = $this->eventoConVenta(['capacidad' => 10]);
        $this->comprar($linkId, ['cantidad' => 4]);

        $res = EntradasHandler::config($this->db, $this->post(
            ['activo' => 1, 'capacidad' => 3, 'precio' => 0],
            $this->user($this->duenaId),
            ['link_id' => $linkId]
        ));

        $this->assertStatus(400, $res);
        $this->assertSame('Ya hay 4 entradas tomadas: la capacidad no puede ser menor', $res->body['error']);
    }

    // ============================================================ cancelar

    /** Cancelar libera el cupo y los lugares, sin tocar ningún contador. */
    public function testCancelarDevuelveElCupoYLosLugares()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(2)]);
        $codigo = $this->comprar($linkId, ['lugares' => ['f:A:1', 'f:A:2']])->body['codigo'];
        $this->assertTrue(Entradas::disponibilidad($this->db, $linkId)['agotado']);

        $res = EntradasHandler::cancelar($this->db, $this->post(['codigo' => $codigo], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['cancelada']);
        $orden = $this->orden($codigo);
        $this->assertSame('cancelada', $orden['estado']);
        $this->assertNotNull($orden['cancelada_en']);
        $this->assertSame(0, Entradas::ocupadas($this->db, $linkId));
        $this->assertSame([], Entradas::lugaresOcupados($this->db, $linkId));

        // Y el mismo lugar se puede volver a vender.
        $this->assertStatus(201, $this->comprar($linkId, ['lugares' => ['f:A:1'], 'email' => 'nueva@test.local']));
    }

    public function testCancelarDosVecesNoLiberaElDoble()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $pedido = $this->post(['codigo' => $codigo], $this->user($this->duenaId));

        EntradasHandler::cancelar($this->db, $pedido);
        $res = EntradasHandler::cancelar($this->db, $pedido);

        $this->assertStatus(409, $res);
        $this->assertSame('ya estaba cancelada', $res->body['error']);
    }

    /** Los datos de los compradores son de terceros: nadie más que quien administra cancela. */
    public function testOtraPersonaNoPuedeCancelar()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $otra = $this->usuario('otra@test.local', 'Otra');

        $res = EntradasHandler::cancelar($this->db, $this->post(['codigo' => $codigo], $this->user($otra, 'otra@test.local')));

        $this->assertStatus(403, $res);
        $this->assertSame('pagada', $this->orden($codigo)['estado']);
    }

    // ============================================================== ventas

    public function testLasVentasDelEventoCierranConLoQueHayEnLaBase()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 10000, 'capacidad' => 20]);
        $this->preferenciaOk();
        $pagada = $this->comprar($linkId, ['cantidad' => 2])->body['codigo'];
        $this->pagoDeMercadoPago($pagada, 20000);
        $this->aviso($pagada);
        $this->preferenciaOk();
        $this->comprar($linkId, ['email' => 'pendiente@test.local']);

        $res = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertSame(20, $res->body['capacidad']);
        $this->assertCount(2, $res->body['ordenes']);
        $this->assertSame(2, $res->body['resumen']['vendidas']);
        $this->assertSame(1, $res->body['resumen']['reservadas']);
        $this->assertEquals(20000, $res->body['resumen']['recaudado']);
        $this->assertEquals(2000, $res->body['resumen']['comision']);

        $otra = $this->usuario('otra@test.local');
        $this->assertStatus(403, EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId], $this->user($otra))));
    }

    public function testLasVentasSeBajanEnExcel()
    {
        $linkId = $this->eventoConVenta();
        $this->comprar($linkId);

        $res = EntradasHandler::ventas($this->db, $this->get(['link_id' => $linkId, 'formato' => 'excel'], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertStringStartsWith('PK', $res->raw, 'un xlsx es un zip');
    }

    /** Desactivar la venta con entradas vendidas pide confirmación explícita. */
    public function testDesactivarLaVentaConEntradasVendidasPideConfirmar()
    {
        $linkId = $this->eventoConVenta();
        $this->comprar($linkId);
        $duena = $this->user($this->duenaId);

        $this->assertStatus(409, EntradasHandler::config($this->db, $this->delete(['link_id' => $linkId], $duena)));
        $this->assertNotFalse($this->valor('SELECT 1 FROM event_ticketing WHERE link_id = ?', [$linkId]));

        $confirmado = new \Request('DELETE', ['confirmar' => true], ['link_id' => $linkId], $duena, [], ['Authorization' => 'Bearer x']);
        $this->assertStatus(200, EntradasHandler::config($this->db, $confirmado));
        $this->assertFalse($this->valor('SELECT 1 FROM event_ticketing WHERE link_id = ?', [$linkId]));
        $this->assertSame('pagada', $this->valor('SELECT estado FROM ticket_orders'), 'las compras no se tocan');
    }

    // ================================================ el evento se borra

    /**
     * Borrar un evento no se lleva a quienes compraron: la compra queda
     * colgada de su registro, con el link en null. Es lo que agregó
     * migration_clientes.sql; antes era ON DELETE CASCADE.
     */
    public function testBorrarElEventoConservaLasComprasYSusLugares()
    {
        $linkId = $this->eventoConVenta(['plano' => $this->planoDeUnaFila(3)], ['text' => 'Show que se cae']);
        $codigo = $this->comprar($linkId, ['lugares' => ['f:A:1']])->body['codigo'];
        $recordId = $this->orden($codigo)['record_id'];

        $res = LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertFalse($this->valor('SELECT 1 FROM links WHERE id = ?', [$linkId]));

        $orden = $this->orden($codigo);
        $this->assertNotNull($orden, 'la compra no se puede borrar con el evento');
        $this->assertNull($orden['link_id']);
        $this->assertSame($recordId, $orden['record_id']);
        $this->assertSame('pagada', $orden['estado']);

        $registro = $this->fila('SELECT * FROM event_records WHERE id = ?', [$recordId]);
        $this->assertNull($registro['link_id']);
        $this->assertSame('Show que se cae', $registro['titulo']);

        $lugar = $this->fila('SELECT * FROM ticket_order_lugares');
        $this->assertSame('f:A:1', $lugar['lugar']);
        $this->assertNull($lugar['link_id']);
    }

    /** El registro guarda el último título: es el que se ve si el evento se borra. */
    public function testEditarElEventoActualizaSuRegistro()
    {
        $linkId = $this->eventoConVenta([], ['text' => 'Nombre viejo', 'event_latitude' => -34.6, 'event_longitude' => -58.4]);
        $codigo = $this->comprar($linkId)->body['codigo'];

        $res = LinksHandler::detail($this->db, $this->put(['text' => 'Nombre nuevo'], $this->user($this->duenaId), ['id' => $linkId]));

        $this->assertStatus(200, $res);
        $this->assertSame('Nombre nuevo', $this->valor(
            'SELECT titulo FROM event_records WHERE id = ?',
            [$this->orden($codigo)['record_id']]
        ));
    }

    /** Los clientes de un evento borrado siguen apareciendo, marcados como tal. */
    public function testLosClientesDeUnEventoBorradoSiguenListados()
    {
        $linkId = $this->eventoConVenta([], ['text' => 'Show borrado']);
        $this->comprar($linkId, ['email' => 'Fiel@Test.local', 'nombre' => 'Clienta Fiel', 'cantidad' => 2]);
        LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($this->duenaId)));

        $res = ClientesHandler::index($this->db, $this->get(['page_id' => $this->paginaId], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertCount(1, $res->body['clientes']);
        $cliente = $res->body['clientes'][0];
        $this->assertSame('Clienta Fiel', $cliente['nombre']);
        $this->assertSame(2, $cliente['entradas']);
        $this->assertSame(1, $cliente['reservas']);
        $this->assertTrue($cliente['participaciones'][0]['eliminado']);

        $this->assertCount(1, $res->body['eventos']);
        $this->assertSame('Show borrado', $res->body['eventos'][0]['titulo']);
        $this->assertTrue($res->body['eventos'][0]['eliminado']);
    }

    public function testLaMismaPersonaConOtroEmailEnMayusculasEsUnSoloCliente()
    {
        $linkId = $this->eventoConVenta();
        $this->comprar($linkId, ['email' => 'ana@test.local']);
        $this->comprar($linkId, ['email' => 'ANA@Test.Local']);

        $res = ClientesHandler::index($this->db, $this->get(['page_id' => $this->paginaId], $this->user($this->duenaId)));

        $this->assertCount(1, $res->body['clientes']);
        $this->assertSame(2, $res->body['clientes'][0]['entradas']);
    }

    public function testLosClientesSeExportanAExcel()
    {
        $linkId = $this->eventoConVenta();
        $this->comprar($linkId);

        $res = ClientesHandler::index($this->db, $this->get(['page_id' => $this->paginaId, 'formato' => 'excel'], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertStringStartsWith('PK', $res->raw);
    }

    public function testOtraPersonaNoVeLosClientes()
    {
        $otra = $this->usuario('otra@test.local');

        $this->assertStatus(403, ClientesHandler::index($this->db, $this->get(['page_id' => $this->paginaId], $this->user($otra))));
    }

    // ======================================================= colaboración

    /**
     * La colaboración comparte el público: la página que colabora ve a quienes
     * compraron. Y si la colaboración se deshace, deja de verlos.
     */
    public function testLaPaginaQueColaboraVeALosClientes()
    {
        $otraDuena = $this->usuario('colabora@test.local', 'Colabora');
        $otraPagina = $this->pagina($otraDuena, 'la-banda', 'La Banda');
        $linkId = $this->eventoConVenta();
        $colaboracion = $this->insertar('event_collaborations', [
            'link_id' => $linkId,
            'collaborator_page_id' => $otraPagina,
            'requester_page_id' => $this->paginaId,
            'status' => 'accepted',
        ]);

        $codigo = $this->comprar($linkId)->body['codigo'];

        $this->assertSame('colaborador', $this->valor(
            'SELECT rol FROM event_record_pages WHERE record_id = ? AND page_id = ?',
            [$this->orden($codigo)['record_id'], $otraPagina]
        ));

        $res = ClientesHandler::index($this->db, $this->get(['page_id' => $otraPagina], $this->user($otraDuena, 'colabora@test.local')));
        $this->assertCount(1, $res->body['clientes']);
        $this->assertSame('colaborador', $res->body['clientes'][0]['participaciones'][0]['rol']);

        // Se deshace la colaboración y la siguiente venta sincroniza el registro.
        $this->db->prepare('DELETE FROM event_collaborations WHERE id = ?')->execute([$colaboracion]);
        $this->comprar($linkId, ['email' => 'otro@test.local']);

        $res = ClientesHandler::index($this->db, $this->get(['page_id' => $otraPagina], $this->user($otraDuena, 'colabora@test.local')));
        $this->assertSame([], $res->body['clientes']);
    }

    // =============================================================== puerta

    private function clavePuerta($linkId)
    {
        $res = PuertaHandler::link($this->db, $this->post([], $this->user($this->duenaId), ['link_id' => $linkId]));
        $this->assertStatus(200, $res);

        return Puerta::claveDelEvento($this->db, $linkId);
    }

    private function puerta($clave, $accion, $codigo = '', $cantidad = 1)
    {
        return PuertaHandler::puerta($this->db, $this->post([
            'clave' => $clave, 'accion' => $accion, 'codigo' => $codigo, 'cantidad' => $cantidad,
        ]));
    }

    /** En la puerta se marca quién entró, de a una persona por vez, sin pasarse de lo comprado. */
    public function testEnLaPuertaSeMarcaQuienEntro()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId, ['cantidad' => 2])->body['codigo'];
        $clave = $this->clavePuerta($linkId);

        $this->assertSame('valida', $this->puerta($clave, 'mirar', $codigo)->body['resultado']);

        $res = $this->puerta($clave, 'ingresar', 'https://rezon.ar/entrada/' . strtolower($codigo));
        $this->assertTrue($res->body['ok'], 'el QR trae la URL de la orden');
        $this->assertSame(1, (int) $this->orden($codigo)['ingresadas']);
        $this->assertNotNull($this->orden($codigo)['ingreso_en']);

        $this->assertTrue($this->puerta($clave, 'ingresar', $codigo)->body['ok']);
        $res = $this->puerta($clave, 'ingresar', $codigo);
        $this->assertFalse($res->body['ok'], 'no puede entrar más gente de la que compró');
        $this->assertSame('ya_entro', $res->body['resultado']);
        $this->assertSame(2, (int) $this->orden($codigo)['ingresadas']);

        $estado = $this->puerta($clave, 'estado')->body;
        $this->assertSame(['entradas' => 2, 'ingresadas' => 2, 'compras' => 1], $estado['resumen']);
        $this->assertArrayNotHasKey('email', $estado['ordenes'][0], 'la puerta no ve datos de contacto');
    }

    public function testEnLaPuertaSeDeshaceUnIngresoPorError()
    {
        $linkId = $this->eventoConVenta();
        $codigo = $this->comprar($linkId)->body['codigo'];
        $clave = $this->clavePuerta($linkId);
        $this->puerta($clave, 'ingresar', $codigo);

        $this->assertTrue($this->puerta($clave, 'deshacer', $codigo)->body['ok']);

        $orden = $this->orden($codigo);
        $this->assertSame(0, (int) $orden['ingresadas']);
        $this->assertNull($orden['ingreso_en']);
    }

    public function testLaPuertaReconoceEntradasQueNoSirven()
    {
        $this->conectarPorOAuth();
        $linkId = $this->eventoConVenta(['precio' => 1000]);
        $otroEvento = $this->eventoConVenta([], ['text' => 'Otro show']);
        $this->preferenciaOk();
        $sinPagar = $this->comprar($linkId)->body['codigo'];
        $deOtro = $this->comprar($otroEvento)->body['codigo'];
        $clave = $this->clavePuerta($linkId);

        $this->assertSame('no_pagada', $this->puerta($clave, 'mirar', $sinPagar)->body['resultado']);
        $this->assertFalse($this->puerta($clave, 'ingresar', $sinPagar)->body['ok']);

        $res = $this->puerta($clave, 'mirar', $deOtro);
        $this->assertSame('otro_evento', $res->body['resultado']);
        $this->assertSame('Otro show', $res->body['evento']);
        $this->assertNull($res->body['orden']);

        $this->assertSame('no_existe', $this->puerta($clave, 'mirar', 'NOEXISTE00')->body['resultado']);
    }

    /** Generar un link nuevo deja afuera al anterior. */
    public function testUnaClaveDePuertaRevocadaNoAbre()
    {
        $linkId = $this->eventoConVenta();
        $vieja = $this->clavePuerta($linkId);
        $nueva = $this->clavePuerta($linkId);

        $this->assertNotSame($vieja, $nueva);
        $this->assertStatus(404, $this->puerta($vieja, 'estado'));
        $this->assertStatus(200, $this->puerta($nueva, 'estado'));

        PuertaHandler::link($this->db, $this->delete(['link_id' => $linkId], $this->user($this->duenaId)));
        $this->assertStatus(404, $this->puerta($nueva, 'estado'));
    }
}
