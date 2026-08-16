<?php

namespace Tests\Unit\Handlers;

use Cripto;
use EntradasHandler;
use Request;
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
     * El access token permite cobrar en nombre del dueño: no puede volver al
     * frontend ni siquiera para quien lo cargó.
     */
    public function testElAccessTokenNuncaVuelveEnLaRespuesta()
    {
        $this->puedeAdministrar();
        $this->db->onSelect('FROM page_payment_settings', [[
            'page_id' => 5,
            'access_token_cifrado' => Cripto::cifrar(self::TOKEN),
            'token_ultimos4' => 'x123',
            'public_key' => 'APP_USR-publica',
            'modo' => 'produccion',
            'verificado_en' => '2026-08-16 20:00:00',
        ]]);

        $r = EntradasHandler::credenciales($this->db, new Request('GET', [], ['page_id' => 5], $this->sesion()));
        $serializado = json_encode($r->body);

        $this->assertStringNotContainsString(self::TOKEN, $serializado);
        $this->assertStringNotContainsString('access_token', $serializado);
        $this->assertSame('x123', $r->body['cobros']['token_ultimos4']);
    }

    public function testGuardarRechazaUnTokenConFormatoInvalido()
    {
        $this->puedeAdministrar();

        $r = EntradasHandler::credenciales($this->db, new Request('POST', [
            'access_token' => 'no-es-un-token',
            'public_key'   => 'APP_USR-1234567890123456789012345',
        ], ['page_id' => 5], $this->sesion()));

        $this->assertSame(400, $r->status);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO page_payment_settings'));
    }

    /**
     * Mezclar una credencial de prueba con una de producción abre el checkout
     * igual, pero el cobro nunca llega a la cuenta real.
     */
    public function testNoSePuedenMezclarCredencialesDePruebaYProduccion()
    {
        $this->puedeAdministrar();

        $r = EntradasHandler::credenciales($this->db, new Request('POST', [
            'access_token' => self::TOKEN,
            'public_key'   => 'TEST-1234567890123456789012345678',
        ], ['page_id' => 5], $this->sesion()));

        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('mismo par', $r->body['error']);
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
}
