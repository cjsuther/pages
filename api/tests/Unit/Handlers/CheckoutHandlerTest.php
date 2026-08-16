<?php

namespace Tests\Unit\Handlers;

use CheckoutHandler;
use Cripto;
use Request;
use Tests\Support\FakeHttpClient;
use Tests\Support\HandlerTestCase;

class CheckoutHandlerTest extends HandlerTestCase
{
    const TOKEN = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';
    const CODIGO = 'ABC123DEF456';

    // ------------------------------------------------------------- fixtures

    private function hayEventoQueVende(array $config = [])
    {
        $this->db->onSelect('lg.type = "eventos"', [['id' => 100, 'text' => 'Fiesta de fin de año']]);

        $this->db->onSelect('FROM event_ticketing WHERE link_id', [array_merge([
            'id' => 1, 'link_id' => 100, 'activo' => 1, 'capacidad' => 50,
            'precio' => '1500.00', 'moneda' => 'ARS', 'max_por_compra' => 10,
        ], $config)]);

        $this->db->onSelect('COALESCE(SUM(cantidad), 0)', [[0]]);
        $this->db->onWrite('INSERT INTO ticket_orders', 1);
    }

    private function hayCredencialesDeCobro($conectadoPor = 'oauth')
    {
        // Cobros resuelve la página del evento y después lee sus credenciales.
        $this->db->onSelect('lg.page_id', [[5]]);
        $this->db->onSelect('FROM page_payment_settings WHERE page_id', [[
            'page_id' => 5,
            'mp_user_id' => '987654321',
            'access_token_cifrado' => Cripto::cifrar(self::TOKEN),
            'refresh_token_cifrado' => null,
            'modo' => 'produccion',
            'conectado_por' => $conectadoPor,
            'token_expira_en' => date('Y-m-d H:i:s', time() + 86400 * 180),
            'verificado_en' => '2026-08-16 20:00:00',
        ]]);

        // eventoAdmiteSplit vuelve a resolver la página y la credencial.
        $this->db->onSelect('lg.page_id', [[5]]);
        $this->db->onSelect('FROM page_payment_settings WHERE page_id', [[
            'page_id' => 5,
            'conectado_por' => $conectadoPor,
            'access_token_cifrado' => Cripto::cifrar(self::TOKEN),
            'refresh_token_cifrado' => null,
            'token_expira_en' => date('Y-m-d H:i:s', time() + 86400 * 180),
        ]]);
    }

    private function pedido(array $overrides = [])
    {
        return new Request('POST', array_merge([
            'link_id'  => 100,
            'nombre'   => 'Ana Gómez',
            'email'    => 'ana@example.com',
            'telefono' => '+54 11 2233-4455',
            'cantidad' => 2,
        ], $overrides));
    }

    private function httpQueCreaLaPreferencia()
    {
        return (new FakeHttpClient())->responde('/checkout/preferences', 201, [
            'id' => 'PREF-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-123',
        ]);
    }

    // -------------------------------------------------------------- comprar

    public function testComprarDevuelveElLinkDePago()
    {
        $this->hayEventoQueVende();
        $this->hayCredencialesDeCobro();
        $this->db->onWrite('UPDATE ticket_orders SET comision', 1);

        $r = CheckoutHandler::comprar($this->db, $this->pedido(), $this->httpQueCreaLaPreferencia());

        $this->assertSame(201, $r->status);
        $this->assertStringContainsString('mercadopago', $r->body['url']);
        $this->assertSame('reservada', $r->body['estado']);
    }

    /** Sin cobro no hay checkout: la reserva queda confirmada en el acto. */
    public function testUnaReservaSinPrecioNoPasaPorMercadoPago()
    {
        $this->hayEventoQueVende(['precio' => '0.00']);
        $http = new FakeHttpClient();

        $r = CheckoutHandler::comprar($this->db, $this->pedido(), $http);

        $this->assertSame('pagada', $r->body['estado']);
        $this->assertNull($r->body['url']);
        $this->assertSame([], $http->llamadas, 'no tiene que hablar con Mercado Pago');
    }

    public function testNoSePuedeComprarDeUnEventoInexistente()
    {
        $r = CheckoutHandler::comprar($this->db, $this->pedido(), new FakeHttpClient());

        $this->assertSame(404, $r->status);
    }

    public function testLosDatosDelCompradorSeValidan()
    {
        $this->hayEventoQueVende();

        $r = CheckoutHandler::comprar($this->db, $this->pedido(['email' => 'roto']), new FakeHttpClient());

        $this->assertSame(400, $r->status);
    }

    public function testComprarExigePost()
    {
        $this->assertSame(405, CheckoutHandler::comprar($this->db, new Request('GET'), new FakeHttpClient())->status);
    }

    /**
     * El cupo se toma antes de ir a Mercado Pago. Si se tomara al volver, el
     * evento se sobrevendería con la gente ya pagada.
     */
    public function testElCupoSeTomaAntesDeIrAMercadoPago()
    {
        $this->hayEventoQueVende();
        $this->hayCredencialesDeCobro();

        CheckoutHandler::comprar($this->db, $this->pedido(), $this->httpQueCreaLaPreferencia());

        $this->assertTrue($this->db->ran('INSERT INTO ticket_orders'));
    }

    /**
     * Una orden reservada retiene cupo 15 minutos. Si el checkout no se pudo
     * crear, esa reserva bloquearía entradas por una compra que nunca empezó.
     */
    public function testSiFallaLaPreferenciaSeLiberaLaReserva()
    {
        $this->hayEventoQueVende();
        $this->hayCredencialesDeCobro();
        $http = (new FakeHttpClient())->responde('/checkout/preferences', 400, ['message' => 'error']);

        $r = CheckoutHandler::comprar($this->db, $this->pedido(), $http);

        $this->assertSame(502, $r->status);
        $this->assertStringContainsString("estado = 'cancelada'", $this->db->callsFor('UPDATE ticket_orders')[0]['sql']);
    }

    public function testSinCredencialesNoSeCobraYSeLiberaLaReserva()
    {
        $this->hayEventoQueVende();
        $this->db->onSelect('lg.page_id', [[5]]);

        $r = CheckoutHandler::comprar($this->db, $this->pedido(), new FakeHttpClient());

        $this->assertSame(503, $r->status);
        $this->assertTrue($this->db->ran("estado = 'cancelada'"));
    }


    // ----------------------------------------------------------------- split

    /**
     * El comprador paga el total; la comisión sale de lo que recibe el dueño.
     * Mandarla como un monto y no como porcentaje es lo que espera Mercado Pago.
     */
    public function testLaPreferenciaLlevaLaComisionDeLaPlataforma()
    {
        $this->hayEventoQueVende(['precio' => '1500.00']);
        $this->hayCredencialesDeCobro('oauth');
        $this->db->onWrite('UPDATE ticket_orders SET comision', 1);
        $http = $this->httpQueCreaLaPreferencia();

        CheckoutHandler::comprar($this->db, $this->pedido(['cantidad' => 2]), $http);
        $enviado = $http->jsonDe('/checkout/preferences');

        // 2 entradas de 1500 = 3000; el 10% son 300.
        $this->assertSame(300.0, $enviado['marketplace_fee']);
    }

    public function testElCompradorPagaElTotalSinRecargo()
    {
        $this->hayEventoQueVende(['precio' => '1500.00']);
        $this->hayCredencialesDeCobro('oauth');
        $this->db->onWrite('UPDATE ticket_orders SET comision', 1);
        $http = $this->httpQueCreaLaPreferencia();

        CheckoutHandler::comprar($this->db, $this->pedido(['cantidad' => 2]), $http);
        $item = $http->jsonDe('/checkout/preferences')['items'][0];

        $this->assertSame(1500.0, $item['unit_price'], 'la comisión no se le suma al comprador');
        $this->assertSame(2, $item['quantity']);
    }

    /**
     * Con una credencial pegada a mano Mercado Pago ignora la comisión. Mandarla
     * igual haría creer que se está cobrando algo que no se cobra.
     */
    public function testSinOauthNoSeMandaComision()
    {
        $this->hayEventoQueVende(['precio' => '1500.00']);
        $this->hayCredencialesDeCobro('manual');
        $this->db->onWrite('UPDATE ticket_orders SET comision', 1);
        $http = $this->httpQueCreaLaPreferencia();

        CheckoutHandler::comprar($this->db, $this->pedido(), $http);

        $this->assertArrayNotHasKey('marketplace_fee', $http->jsonDe('/checkout/preferences'));
    }

    public function testLaComisionQuedaCongeladaEnLaOrden()
    {
        $this->hayEventoQueVende(['precio' => '1500.00']);
        $this->hayCredencialesDeCobro('oauth');
        $this->db->onWrite('UPDATE ticket_orders SET comision', 1);

        CheckoutHandler::comprar($this->db, $this->pedido(['cantidad' => 2]), $this->httpQueCreaLaPreferencia());
        $params = $this->db->paramsFor('UPDATE ticket_orders SET comision');

        $this->assertSame(300.0, $params[0], 'el monto cobrado');
        $this->assertSame(10.0, $params[1], 'el porcentaje con el que se calculó');
    }

    public function testSinSplitSeGuardaComisionCero()
    {
        $this->hayEventoQueVende(['precio' => '1500.00']);
        $this->hayCredencialesDeCobro('manual');
        $this->db->onWrite('UPDATE ticket_orders SET comision', 1);

        CheckoutHandler::comprar($this->db, $this->pedido(), $this->httpQueCreaLaPreferencia());
        $params = $this->db->paramsFor('UPDATE ticket_orders SET comision');

        $this->assertSame(0.0, $params[0]);
        $this->assertSame(0.0, $params[1]);
    }

    /** Una reserva sin costo no tiene nada que repartir. */
    public function testUnaReservaSinPrecioNoGeneraComision()
    {
        $this->hayEventoQueVende(['precio' => '0.00']);

        CheckoutHandler::comprar($this->db, $this->pedido(), new FakeHttpClient());

        $this->assertSame(0, $this->db->countCalls('UPDATE ticket_orders SET comision'));
    }

    // ---------------------------------------------------------------- aviso

    private function hayOrdenPendiente(array $overrides = [])
    {
        $this->db->onSelect('FROM ticket_orders o', [array_merge([
            'id' => 1, 'codigo' => self::CODIGO, 'link_id' => 100,
            'cantidad' => 2, 'total' => '3000.00', 'estado' => 'reservada',
            'reserva_vence_en' => null, 'evento' => 'Fiesta', 'event_date' => '2026-12-01',
            'event_time' => '21:00:00', 'event_address' => 'Corrientes 1234',
            'pagina' => 'Mi Página', 'url_slug' => 'mi-pagina', 'moneda' => 'ARS',
            'nombre' => 'Ana Gómez',
        ], $overrides)]);
    }

    private function avisoDe($pagoId)
    {
        return new Request('POST', ['data' => ['id' => $pagoId]], ['orden' => self::CODIGO]);
    }

    private function httpConPago(array $pago)
    {
        return (new FakeHttpClient())->responde('/v1/payments/', 200, array_merge([
            'status' => 'approved',
            'external_reference' => self::CODIGO,
            'transaction_amount' => 3000.0,
        ], $pago));
    }

    public function testUnPagoAprobadoAcreditaLaOrden()
    {
        $this->hayOrdenPendiente();
        $this->hayCredencialesDeCobro();
        $this->db->onSelect('FROM ticket_orders WHERE codigo', [['codigo' => self::CODIGO, 'estado' => 'reservada']]);
        $this->db->onWrite('UPDATE ticket_orders', 1);

        $r = CheckoutHandler::aviso($this->db, $this->avisoDe('99'), $this->httpConPago([]));

        $this->assertSame(200, $r->status);
        $this->assertSame('pago acreditado', $r->body['motivo']);
    }

    /**
     * El aviso lo puede mandar cualquiera y sólo trae un id: el estado se le
     * pregunta a Mercado Pago con la credencial del dueño, nunca se cree lo que
     * viene en el cuerpo.
     */
    public function testElEstadoSeConsultaAMercadoPagoYNoSeLeeDelAviso()
    {
        $this->hayOrdenPendiente();
        $this->hayCredencialesDeCobro();
        $http = $this->httpConPago(['status' => 'rejected']);

        $aviso = new Request('POST', ['data' => ['id' => '99'], 'status' => 'approved'], ['orden' => self::CODIGO]);
        $this->db->onSelect('FROM ticket_orders WHERE codigo', [['codigo' => self::CODIGO, 'estado' => 'reservada']]);
        $this->db->onWrite('UPDATE ticket_orders', 1);

        $r = CheckoutHandler::aviso($this->db, $aviso, $http);

        $this->assertTrue($http->llamoA('/v1/payments/'), 'tiene que consultar a Mercado Pago');
        $this->assertNotSame('pago acreditado', $r->body['motivo'], 'el "approved" del cuerpo no vale');
    }

    /** Un pago de otra orden no puede acreditar ésta. */
    public function testUnPagoDeOtraOrdenNoSeAcredita()
    {
        $this->hayOrdenPendiente();
        $this->hayCredencialesDeCobro();

        $r = CheckoutHandler::aviso($this->db, $this->avisoDe('99'),
            $this->httpConPago(['external_reference' => 'OTRA-ORDEN']));

        $this->assertStringContainsString('otra orden', $r->body['motivo']);
        $this->assertSame(0, $this->db->countCalls('UPDATE ticket_orders'));
    }

    /** El monto lo dice Mercado Pago: si no coincide, algo se manipuló. */
    public function testUnPagoPorMenosDeLoDebidoNoSeAcredita()
    {
        $this->hayOrdenPendiente();
        $this->hayCredencialesDeCobro();

        $r = CheckoutHandler::aviso($this->db, $this->avisoDe('99'),
            $this->httpConPago(['transaction_amount' => 1.0]));

        $this->assertStringContainsString('monto no coincide', $r->body['motivo']);
        $this->assertSame(0, $this->db->countCalls('UPDATE ticket_orders'));
    }

    /**
     * Mercado Pago reintenta hasta recibir un 2xx. Un 500 ante un aviso que
     * nunca va a poder procesarse lo haría reintentar para siempre.
     */
    public function testUnAvisoInutilizableSeResponde200ParaQueNoReintente()
    {
        $r = CheckoutHandler::aviso($this->db, new Request('POST', [], []), new FakeHttpClient());

        $this->assertSame(200, $r->status);
    }

    public function testUnAvisoDeUnaOrdenInexistenteNoRompe()
    {
        $r = CheckoutHandler::aviso($this->db, $this->avisoDe('99'), new FakeHttpClient());

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('inexistente', $r->body['motivo']);
    }

    /** Acá sí conviene que reintente: el pago existe, no lo pudimos leer. */
    public function testSiNoSePudoConsultarElPagoSePideReintento()
    {
        $this->hayOrdenPendiente();
        $this->hayCredencialesDeCobro();
        $http = (new FakeHttpClient())->responde('/v1/payments/', 500, '');

        $this->assertSame(503, CheckoutHandler::aviso($this->db, $this->avisoDe('99'), $http)->status);
    }

    /** Las IPN viejas mandan el id en la query en lugar de en el cuerpo. */
    public function testSeAceptaElFormatoViejoDeAviso()
    {
        $this->hayOrdenPendiente();
        $this->hayCredencialesDeCobro();
        $this->db->onSelect('FROM ticket_orders WHERE codigo', [['codigo' => self::CODIGO, 'estado' => 'reservada']]);
        $this->db->onWrite('UPDATE ticket_orders', 1);

        $aviso = new Request('POST', [], ['orden' => self::CODIGO, 'topic' => 'payment', 'id' => '99']);

        $this->assertSame('pago acreditado', CheckoutHandler::aviso($this->db, $aviso, $this->httpConPago([]))->body['motivo']);
    }

    // ---------------------------------------------------------------- orden

    public function testLaOrdenSeConsultaPorSuCodigo()
    {
        $this->hayOrdenPendiente(['estado' => 'pagada']);

        $r = CheckoutHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));

        $this->assertSame(200, $r->status);
        $this->assertSame('pagada', $r->body['orden']['estado']);
        $this->assertSame('Fiesta', $r->body['orden']['evento']);
    }

    /**
     * El código va en una URL: quien la tenga no puede ver los datos de
     * contacto de quien compró.
     */
    public function testLaOrdenNoExponeElEmailNiElTelefono()
    {
        $this->hayOrdenPendiente(['email' => 'ana@example.com', 'telefono' => '1122334455']);

        $orden = CheckoutHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]))->body['orden'];

        $this->assertArrayNotHasKey('email', $orden);
        $this->assertArrayNotHasKey('telefono', $orden);
    }

    public function testUnaReservaVencidaSeInformaComoVencida()
    {
        $this->hayOrdenPendiente(['estado' => 'reservada', 'reserva_vence_en' => '2020-01-01 00:00:00']);

        $r = CheckoutHandler::orden($this->db, new Request('GET', [], ['codigo' => self::CODIGO]));

        $this->assertSame('vencida', $r->body['orden']['estado']);
    }

    public function testUnaOrdenInexistenteDa404()
    {
        $r = CheckoutHandler::orden($this->db, new Request('GET', [], ['codigo' => 'NO-EXISTE']));

        $this->assertSame(404, $r->status);
    }

    public function testLaOrdenExigeCodigo()
    {
        $this->assertSame(400, CheckoutHandler::orden($this->db, new Request('GET'))->status);
    }
}
