<?php

namespace Tests\Unit\Handlers;

use Cripto;
use EntradasHandler;
use MercadoPagoOAuth;
use Request;
use Tests\Support\FakeHttpClient;
use Tests\Support\HandlerTestCase;

class EntradasHandlerTest extends HandlerTestCase
{
    const TOKEN = 'APP_USR-1234567890123456-081612-abcdef0123456789abcdef01234567-123';

    private function sesion()
    {
        return ['user_id' => 7];
    }

    private function puedeAdministrar()
    {
        // PageAccess resuelve el permiso con esta consulta.
        $this->db->onSelect('FROM pages', [['id' => 5, 'user_id' => 7]]);
    }

    // ---------------------------------------------------------- credenciales

    public function testLasCredencialesExigenSesion()
    {
        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], ['page_id' => 5]));

        $this->assertSame(401, $r->status);
    }

    public function testLasCredencialesExigenPageId()
    {
        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], [], $this->sesion()));

        $this->assertSame(400, $r->status);
    }

    /** Las credenciales de cobro son de otra persona: sólo las toca quien administra. */
    public function testUnExtranoNoPuedeVerLasCredenciales()
    {
        $this->db->onSelect('FROM pages', []);

        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));

        $this->assertSame(403, $r->status);
    }

    public function testSinCredencialesCargadasSeInformaAsi()
    {
        $this->puedeAdministrar();

        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));

        $this->assertFalse($r->body['cobros']['configurado']);
    }




    /**
     * Los eventos que ya venden quedarían con el checkout roto, y el dueño se
     * enteraría recién cuando un comprador no pueda pagar.
     */
    public function testDesconectarAvisaSiHayEventosCobrando()
    {
        $this->puedeAdministrar();
        $this->db->onSelect('FROM event_ticketing et', [[3]]);

        $r = EntradasHandler::credenciales($this->db, new Request('DELETE', [], ['page_id' => 5], $this->sesion()));

        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('3 evento', $r->body['error']);
        $this->assertSame(0, $this->db->countCalls('DELETE FROM page_payment_settings'));
    }

    public function testDesconectarConfirmadoBorraIgual()
    {
        $this->puedeAdministrar();
        $this->db->onSelect('FROM event_ticketing et', [[3]]);

        $r = EntradasHandler::credenciales($this->db,
            new Request('DELETE', ['confirmar' => true], ['page_id' => 5], $this->sesion()));

        $this->assertSame(200, $r->status);
        $this->assertTrue($this->db->ran('DELETE FROM page_payment_settings'));
    }


    /**
     * Ni el access token ni el refresh token pueden volver al frontend, ni
     * siquiera para quien conectó la cuenta.
     */
    public function testNingunSecretoVuelveEnLaRespuesta()
    {
        $this->puedeAdministrar();
        $this->db->onSelect('FROM page_payment_settings', [[
            'page_id' => 5,
            'mp_user_id' => '987654321',
            'access_token_cifrado' => Cripto::cifrar(self::TOKEN),
            'refresh_token_cifrado' => Cripto::cifrar('TG-refresh'),
            'modo' => 'produccion',
            'conectado_por' => 'oauth',
            'verificado_en' => '2026-08-16 20:00:00',
        ]]);

        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));
        $serializado = json_encode($r->body);

        $this->assertStringNotContainsString(self::TOKEN, $serializado);
        $this->assertStringNotContainsString('TG-refresh', $serializado);
        $this->assertStringNotContainsString('cifrado', $serializado);
    }

    /** El dueño tiene que saber qué porcentaje se le descuenta. */
    public function testSeInformaElPorcentajeDeComision()
    {
        $this->puedeAdministrar();

        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));

        $this->assertSame(10.0, $r->body['comision']);
    }

    public function testSeInformaSiLaCuentaAdmiteSplit()
    {
        $this->puedeAdministrar();
        $this->db->onSelect('FROM page_payment_settings', [[
            'page_id' => 5, 'mp_user_id' => '1', 'conectado_por' => 'oauth',
            'modo' => 'produccion', 'verificado_en' => null,
        ]]);

        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));

        $this->assertTrue($r->body['cobros']['admite_split']);
    }

    // ------------------------------------------------------------------ OAuth

    public function testConectarExigeSesion()
    {
        $this->assertSame(401, EntradasHandler::conectar($this->db, new Request('POST', [], ['page_id' => 5]))->status);
    }

    /** Sin esto cualquiera podría empezar a conectar cuentas a páginas ajenas. */
    public function testUnExtranoNoPuedeIniciarLaConexion()
    {
        $this->db->onSelect('FROM pages', []);

        $r = EntradasHandler::conectar($this->db, new Request('POST', [], ['page_id' => 5], $this->sesion()));

        $this->assertSame(403, $r->status);
    }

    public function testConectarDevuelveLaUrlDeMercadoPago()
    {
        $this->puedeAdministrar();

        $r = EntradasHandler::conectar($this->db, new Request('POST', [], ['page_id' => 5], $this->sesion()));

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('auth.mercadopago', $r->body['url']);
        $this->assertStringContainsString('client_id=' . MP_APP_ID, $r->body['url']);
    }

    /**
     * El estado firmado es lo que impide que alguien arme el link con el
     * page_id de otro y le conecte la cuenta a una página que no le pertenece.
     */
    public function testLaUrlLlevaUnEstadoFirmadoConLaPagina()
    {
        $this->puedeAdministrar();

        $r = EntradasHandler::conectar($this->db, new Request('POST', [], ['page_id' => 5], $this->sesion()));

        parse_str(parse_url($r->body['url'], PHP_URL_QUERY), $query);
        $estado = MercadoPagoOAuth::leerEstado($query['state']);

        $this->assertSame(5, $estado['page_id']);
        $this->assertSame(7, $estado['user_id']);
    }

    // -------------------------------------------------------- oauth callback

    private function estadoValido()
    {
        return MercadoPagoOAuth::firmarEstado(5, 7);
    }

    private function httpQueCanjea()
    {
        return (new FakeHttpClient())->responde('/oauth/token', 200, [
            'access_token' => 'APP_USR-token-nuevo-del-vendedor',
            'refresh_token' => 'TG-refresh-nuevo',
            'public_key' => 'APP_USR-publica',
            'user_id' => 987654321,
            'live_mode' => true,
            'expires_in' => 15552000,
        ]);
    }

    public function testElCallbackGuardaLasCredencialesYVuelveAlEditor()
    {
        $this->puedeAdministrar();
        $this->db->onWrite('INSERT INTO page_payment_settings', 1);

        $r = EntradasHandler::oauthCallback($this->db,
            new Request('GET', [], ['code' => 'CODIGO-123', 'state' => $this->estadoValido()]),
            $this->httpQueCanjea());

        $this->assertTrue($r->isRedirect());
        $this->assertStringContainsString('conectado=1', $r->redirectUrl);
        $this->assertTrue($this->db->ran('INSERT INTO page_payment_settings'));
    }

    /** Un estado inventado no puede conectar nada. */
    public function testUnEstadoInvalidoNoGuardaNada()
    {
        $r = EntradasHandler::oauthCallback($this->db,
            new Request('GET', [], ['code' => 'CODIGO-123', 'state' => 'inventado']),
            $this->httpQueCanjea());

        $this->assertStringContainsString('error=estado_invalido', $r->redirectUrl);
        $this->assertNoWrites();
    }

    /**
     * Entre que se firmó el estado y la vuelta pueden haberle sacado el acceso
     * a la página.
     */
    public function testSeVuelveAComprobarElPermisoAlVolver()
    {
        $this->db->onSelect('FROM pages', []);

        $r = EntradasHandler::oauthCallback($this->db,
            new Request('GET', [], ['code' => 'CODIGO-123', 'state' => $this->estadoValido()]),
            $this->httpQueCanjea());

        $this->assertStringContainsString('error=sin_permiso', $r->redirectUrl);
        $this->assertNoWrites();
    }

    public function testSiElDuenoCancelaSeVuelveSinConectarNada()
    {
        $r = EntradasHandler::oauthCallback($this->db,
            new Request('GET', [], ['error' => 'access_denied', 'state' => $this->estadoValido()]),
            new FakeHttpClient());

        $this->assertStringContainsString('error=cancelado', $r->redirectUrl);
        $this->assertNoWrites();
    }

    public function testSiMercadoPagoRechazaElCodigoNoSeGuardaNada()
    {
        $this->puedeAdministrar();
        $http = (new FakeHttpClient())->responde('/oauth/token', 400, ['message' => 'invalid_grant']);

        $r = EntradasHandler::oauthCallback($this->db,
            new Request('GET', [], ['code' => 'VIEJO', 'state' => $this->estadoValido()]), $http);

        $this->assertStringContainsString('error=fallo_mercadopago', $r->redirectUrl);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO page_payment_settings'));
    }

    // ---------------------------------------------------------------- config

    private function puedeAdministrarElEvento()
    {
        // PageAccess::canManageLink resuelve el permiso por el link.
        $this->db->onSelect('FROM links l', [['id' => 100, 'user_id' => 7, 'page_id' => 5]]);
    }

    public function testLaConfiguracionExigeSesion()
    {
        $r = EntradasHandler::config($this->db, new Request('GET', [], ['link_id' => 100]));

        $this->assertSame(401, $r->status);
    }

    public function testUnExtranoNoPuedeConfigurarLaVenta()
    {
        $this->db->onSelect('FROM links l', []);

        $r = EntradasHandler::config($this->db, new Request('GET', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(403, $r->status);
    }

    /**
     * Cobrar sin credencial cargada deja el checkout roto para el comprador:
     * mejor frenarlo al configurar que al vender.
     */
    public function testNoSePuedePonerPrecioSinMercadoPagoConectado()
    {
        $this->puedeAdministrarElEvento();
        $this->db->onSelect('lg.page_id', [[5]]);

        $r = EntradasHandler::config($this->db, new Request('POST', [
            'capacidad' => 100, 'precio' => 1500,
        ], ['link_id' => 100], $this->sesion()));

        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('conectar Mercado Pago', $r->body['error']);
    }

    /** Una reserva sin precio no necesita Mercado Pago. */
    public function testSePuedeReservarSinPrecioAunqueNoHayaMercadoPago()
    {
        $this->puedeAdministrarElEvento();
        $this->db->onSelect('COALESCE(SUM(cantidad), 0)', [[0]]);
        $this->db->onWrite('INSERT INTO event_ticketing', 1);
        $this->db->onSelect('FROM event_ticketing WHERE link_id', [['capacidad' => 100, 'precio' => '0.00']]);

        $r = EntradasHandler::config($this->db, new Request('POST', [
            'capacidad' => 100, 'precio' => 0,
        ], ['link_id' => 100], $this->sesion()));

        $this->assertSame(200, $r->status);
    }

    public function testDesactivarAvisaSiYaHayEntradasTomadas()
    {
        $this->puedeAdministrarElEvento();
        $this->db->onSelect('COALESCE(SUM(cantidad), 0)', [[12]]);

        $r = EntradasHandler::config($this->db, new Request('DELETE', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('12 entrada', $r->body['error']);
        $this->assertSame(0, $this->db->countCalls('DELETE FROM event_ticketing'));
    }

    // ---------------------------------------------------------------- ventas

    private function hayVentas()
    {
        $this->db->onSelect('FROM ticket_orders', [[
            'id' => 1, 'codigo' => 'ABC123DEF456', 'nombre' => 'Ana Gómez',
            'email' => 'ana@example.com', 'telefono' => '1122334455',
            'cantidad' => 2, 'precio_unitario' => '1500.00', 'total' => '3000.00',
            'comision' => '300.00', 'comision_porcentaje' => '10.00',
            'moneda' => 'ARS', 'estado' => 'pagada', 'reserva_vence_en' => null,
            'mp_payment_id' => '99', 'pagada_en' => '2026-08-16 20:00:00',
            'created_at' => '2026-08-16 19:58:00', 'vencida' => 0,
        ]]);
        $this->db->onSelect('FROM event_ticketing WHERE link_id', [['capacidad' => 100]]);
    }

    /** Son datos de contacto de terceros: no los ve cualquiera con el link. */
    public function testUnExtranoNoPuedeVerLasVentas()
    {
        $this->db->onSelect('FROM links l', []);

        $r = EntradasHandler::ventas($this->db, new Request('GET', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(403, $r->status);
    }

    public function testLasVentasExigenSesion()
    {
        $this->assertSame(401, EntradasHandler::ventas($this->db, new Request('GET', [], ['link_id' => 100]))->status);
    }

    public function testLasVentasTraenElResumenYLaCapacidad()
    {
        $this->puedeAdministrarElEvento();
        $this->hayVentas();

        $r = EntradasHandler::ventas($this->db, new Request('GET', [], ['link_id' => 100], $this->sesion()));

        $this->assertSame(2, $r->body['resumen']['vendidas']);
        $this->assertSame(3000.0, $r->body['resumen']['recaudado']);
        $this->assertSame(100, $r->body['capacidad']);
    }

    public function testSePuedenExportarLasVentasComoCsv()
    {
        $this->puedeAdministrarElEvento();
        $this->hayVentas();

        $r = EntradasHandler::ventas($this->db,
            new Request('GET', [], ['link_id' => 100, 'formato' => 'csv'], $this->sesion()));

        $this->assertStringContainsString('text/csv', implode(' ', $r->headers));
        $this->assertStringContainsString('attachment', implode(' ', $r->headers));
        $this->assertStringContainsString('Ana Gómez', $r->raw);
        $this->assertStringContainsString('Codigo,Nombre,Email', $r->raw, 'la primera fila son los encabezados');
    }

    // --------------------------------------------------- eventos con ventas

    private function hayEventosConEntradas()
    {
        $this->db->onSelect('FROM links l', [[
            'id' => 100, 'text' => 'Corta la Semana', 'event_date' => '2026-09-02',
            'event_time' => '21:00:00', 'event_address' => 'Humboldt 1574',
            'activo' => 1, 'capacidad' => 80, 'precio' => '5000.00', 'moneda' => 'ARS',
            'ordenes' => '4', 'vendidas' => '6', 'reservadas' => '2', 'recaudado' => '30000.00',
        ]]);
    }

    public function testElListadoDeEventosExigeSesion()
    {
        $r = EntradasHandler::eventos($this->db, new Request('GET', [], ['page_id' => 5]));

        $this->assertSame(401, $r->status);
    }

    public function testElListadoDeEventosExigePageId()
    {
        $r = EntradasHandler::eventos($this->db, new Request('GET', [], [], $this->sesion()));

        $this->assertSame(400, $r->status);
    }

    /** El listado dice cuánto vendió cada show: no lo ve cualquiera. */
    public function testUnExtranoNoPuedeVerLosEventosDeUnaPagina()
    {
        $this->db->onSelect('FROM pages', []);

        $r = EntradasHandler::eventos($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));

        $this->assertSame(403, $r->status);
    }

    public function testElListadoTraeLosTotalesDeCadaEvento()
    {
        $this->puedeAdministrar();
        $this->hayEventosConEntradas();

        $r = EntradasHandler::eventos($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));

        $evento = $r->body['eventos'][0];
        $this->assertSame('Corta la Semana', $evento['text']);
        $this->assertSame(6, $evento['vendidas'], 'los totales llegan como número, no como texto');
        $this->assertSame(2, $evento['reservadas']);
        $this->assertSame(30000.0, $evento['recaudado']);
        $this->assertTrue($evento['activo']);
    }

    public function testSePuedeBuscarPorNombre()
    {
        $this->puedeAdministrar();
        $this->hayEventosConEntradas();

        EntradasHandler::eventos($this->db,
            new Request('GET', [], ['page_id' => 5, 'q' => 'Corta'], $this->sesion()));

        $this->assertContains('%Corta%', $this->db->paramsFor('FROM links l'));
    }

    /**
     * Quien busca "100%" busca eso. Sin escapar, el % del texto es el comodín
     * de LIKE y la búsqueda trae cualquier cosa que empiece con 100.
     */
    public function testLosComodinesDelTextoNoSonComodines()
    {
        $this->puedeAdministrar();
        $this->hayEventosConEntradas();

        EntradasHandler::eventos($this->db,
            new Request('GET', [], ['page_id' => 5, 'q' => '100%'], $this->sesion()));

        $this->assertContains('%100\%%', $this->db->paramsFor('FROM links l'));
    }

    public function testSePuedeBuscarPorRangoDeFechas()
    {
        $this->puedeAdministrar();
        $this->hayEventosConEntradas();

        EntradasHandler::eventos($this->db, new Request('GET', [],
            ['page_id' => 5, 'desde' => '2026-09-01', 'hasta' => '2026-09-30'], $this->sesion()));

        $params = $this->db->paramsFor('FROM links l');
        $this->assertContains('2026-09-01', $params);
        $this->assertContains('2026-09-30', $params);
    }

    /** Una fecha que no es una fecha se ignora en vez de romper la consulta. */
    public function testUnaFechaInvalidaNoLlegaALaConsulta()
    {
        $this->puedeAdministrar();
        $this->hayEventosConEntradas();

        EntradasHandler::eventos($this->db,
            new Request('GET', [], ['page_id' => 5, 'desde' => 'ayer'], $this->sesion()));

        $sql = $this->db->callsFor('FROM links l')[0]['sql'];
        $this->assertStringNotContainsString('event_date >=', $sql);
    }

    /**
     * Si el dueño apaga las entradas de un show que ya vendió, las ventas
     * hechas tienen que seguir apareciendo.
     */
    public function testUnEventoSinEntradasPeroConVentasSigueApareciendo()
    {
        $this->puedeAdministrar();
        $this->hayEventosConEntradas();

        EntradasHandler::eventos($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));

        $sql = $this->db->callsFor('FROM links l')[0]['sql'];
        $this->assertStringContainsString('et.id IS NOT NULL OR v.link_id IS NOT NULL', $sql);
    }

    // ------------------------------------------------------------ csv seguro

    /** Un nombre con coma partiría la fila en dos columnas. */
    public function testUnaComaEnElNombreNoRompeLaFila()
    {
        $this->assertSame('"Gómez, Ana"', EntradasHandler::campoCsv('Gómez, Ana'));
    }

    public function testLasComillasSeEscapan()
    {
        $this->assertSame('"Ana ""La Turca"" Gómez"', EntradasHandler::campoCsv('Ana "La Turca" Gómez'));
    }

    /**
     * Excel interpreta como fórmula lo que empieza con = + - o @: un nombre
     * cargado a propósito puede ejecutar algo al abrir el archivo.
     */
    public function testUnCampoQueParezcaFormulaSeNeutraliza()
    {
        $this->assertSame('"\'=1+1"', EntradasHandler::campoCsv('=1+1'));
        $this->assertSame('"\'@SUM(A1)"', EntradasHandler::campoCsv('@SUM(A1)'));
    }

    // -------------------------------------------------------------- cancelar

    private function hayCompra($estado = 'pagada')
    {
        $this->db->onSelect('FROM ticket_orders o', [[
            'id' => 1, 'codigo' => 'ABC123', 'link_id' => 100, 'estado' => $estado, 'cantidad' => 2,
        ]]);
        $this->db->onSelect('SELECT estado, cantidad FROM ticket_orders', [[
            'estado' => $estado, 'cantidad' => 2,
        ]]);
    }

    private function cancelar(array $body, $conSesion = true)
    {
        return EntradasHandler::cancelar(
            $this->db,
            new Request('POST', $body, [], $conSesion ? $this->sesion() : null)
        );
    }

    public function testCancelarExigeSesion()
    {
        $this->assertSame(401, $this->cancelar(['codigo' => 'ABC123'], false)->status);
    }

    public function testCancelarExigePost()
    {
        $r = EntradasHandler::cancelar($this->db, new Request('GET', [], [], $this->sesion()));

        $this->assertSame(405, $r->status);
    }

    public function testCancelarExigeElCodigo()
    {
        $this->assertSame(400, $this->cancelar([])->status);
    }

    public function testNoSePuedeCancelarUnaCompraQueNoExiste()
    {
        $this->db->onSelect('FROM ticket_orders o', []);

        $this->assertSame(404, $this->cancelar(['codigo' => 'NO-EXISTE'])->status);
    }

    /**
     * El comprador tiene el código de su orden. Si alcanzara para cancelar,
     * cualquiera que lo viera podría dar de baja entradas ajenas.
     */
    public function testUnExtranoNoPuedeCancelarCompras()
    {
        $this->hayCompra();
        $this->db->onSelect('FROM links l', []);

        $this->assertSame(403, $this->cancelar(['codigo' => 'ABC123'])->status);
    }

    public function testElAdministradorCancelaLaCompra()
    {
        $this->hayCompra();
        $this->puedeAdministrarElEvento();
        $this->db->onWrite('UPDATE ticket_orders', 1);

        $r = $this->cancelar(['codigo' => 'ABC123']);

        $this->assertSame(200, $r->status);
        $this->assertTrue($r->body['cancelada']);
    }

    /** Quien cancela quiere ver el cupo actualizado, no volver a pedirlo. */
    public function testAlCancelarSeDevuelveElEstadoDeLasVentas()
    {
        $this->hayCompra();
        $this->puedeAdministrarElEvento();
        $this->db->onWrite('UPDATE ticket_orders', 1);

        $r = $this->cancelar(['codigo' => 'ABC123']);

        $this->assertArrayHasKey('ventas', $r->body);
    }

    public function testCancelarDosVecesNoLiberaElDoble()
    {
        $this->hayCompra('cancelada');
        $this->puedeAdministrarElEvento();

        $r = $this->cancelar(['codigo' => 'ABC123']);

        $this->assertSame(409, $r->status);
        $this->assertSame(0, $this->db->countCalls('UPDATE ticket_orders'));
    }
}
