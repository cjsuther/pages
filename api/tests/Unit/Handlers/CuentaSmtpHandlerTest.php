<?php

namespace Tests\Unit\Handlers;

use CuentaSmtp;
use CuentaSmtpHandler;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

class CuentaSmtpHandlerTest extends HandlerTestCase
{
    /** @var FakeMailer */
    private $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        CuentaSmtp::olvidar();
        CuentaSmtp::usarResolver(function () {
            return ['142.250.0.108'];
        });
        $this->mailer = new FakeMailer();
    }

    protected function tearDown(): void
    {
        CuentaSmtp::olvidar();
        parent::tearDown();
    }

    // ------------------------------------------------------------- datos

    private function puedeGestionar()
    {
        $this->db->onSelect('FROM pages p', [['1' => 1]]);
    }

    private function cuentaGuardada(array $cambios = [])
    {
        return array_merge([
            'page_id' => 5, 'host' => 'smtp.gmail.com', 'port' => '587', 'seguridad' => 'tls',
            'usuario' => 'sala@gmail.com', 'contrasena_cifrada' => CuentaSmtp::cifrar('clave-vieja'),
            'remitente_email' => 'sala@gmail.com', 'remitente_nombre' => null, 'responder_a' => null,
            'verificado_en' => null, 'actualizado_en' => '2026-10-01 10:00:00',
        ], $cambios);
    }

    private function conCuenta(array $cambios = [])
    {
        $this->db->onSelect('FROM page_smtp WHERE page_id', [$this->cuentaGuardada($cambios)]);
    }

    private function formulario(array $cambios = [])
    {
        return array_merge([
            'page_id' => 5,
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'seguridad' => 'tls',
            'usuario' => 'sala@gmail.com',
            'contrasena' => 'abcd efgh ijkl mnop',
            'remitente_email' => 'sala@gmail.com',
            'remitente_nombre' => '',
            'responder_a' => '',
        ], $cambios);
    }

    private function guardar(array $cambios = [])
    {
        return CuentaSmtpHandler::index($this->db, $this->put($this->formulario($cambios), $this->user()));
    }

    private function probar()
    {
        return CuentaSmtpHandler::prueba($this->db, $this->post(['page_id' => 5], $this->user()), $this->mailer);
    }

    /** La contraseña que quedó en el INSERT, descifrada. */
    private function contrasenaGuardada()
    {
        $params = $this->db->paramsFor('INSERT INTO page_smtp');
        $this->assertNotNull($params, 'No se guardó la cuenta');

        return CuentaSmtp::descifrar($params[5]);
    }

    // ---------------------------------------------------------- permisos

    public function testExigeSesion()
    {
        $this->assertError(401, CuentaSmtpHandler::index($this->db, $this->get(['page_id' => 5])));
        $this->assertError(401, CuentaSmtpHandler::prueba($this->db, $this->post(['page_id' => 5]), $this->mailer));
    }

    public function testExigeLaPagina()
    {
        $this->assertError(400, CuentaSmtpHandler::index($this->db, $this->get([], $this->user())));
    }

    public function testRechazaMetodosDesconocidos()
    {
        $this->assertError(405, CuentaSmtpHandler::index($this->db, new \Request('PATCH', [], ['page_id' => 5], $this->user())));
        $this->assertError(405, CuentaSmtpHandler::prueba($this->db, $this->get(['page_id' => 5], $this->user()), $this->mailer));
    }

    /** Ni verla, ni cambiarla, ni probarla, ni quitarla sin ser dueño o administrador. */
    public function testSinPermisoSobreLaPaginaDa403()
    {
        $this->assertError(403, CuentaSmtpHandler::index($this->db, $this->get(['page_id' => 5], $this->user())));
        $this->assertError(403, $this->guardar());
        $this->assertError(403, CuentaSmtpHandler::index($this->db, $this->delete(['page_id' => 5], $this->user())));
        $this->assertError(403, $this->probar());

        $this->assertNoWrites();
        $this->assertSame([], $this->mailer->enviados);
        $this->assertSame(0, $this->db->countCalls('FROM page_smtp'));
    }

    /** El permiso es el de toda la configuración: dueño o administrador aceptado. */
    public function testElPermisoIncluyeALosAdministradores()
    {
        CuentaSmtpHandler::index($this->db, $this->get(['page_id' => 5], $this->user(3)));

        $consulta = $this->db->callsFor('FROM pages p')[0];
        $this->assertStringContainsString('page_admins', $consulta['sql']);
        $this->assertSame([5, 3, 3], $consulta['params']);
    }

    // --------------------------------------------------------- sin clave

    public function testSinClaveDeCifradoResponde503YNoGuarda()
    {
        CuentaSmtp::usarClave(null);
        $this->puedeGestionar();

        $res = $this->guardar();

        $this->assertError(503, $res, 'no está disponible');
        $this->assertFalse($res->body['disponible']);
        $this->assertNoWrites();
    }

    public function testSinClaveElGetTambienAvisa()
    {
        CuentaSmtp::usarClave(null);
        $this->puedeGestionar();

        $this->assertError(503, CuentaSmtpHandler::index($this->db, $this->get(['page_id' => 5], $this->user())));
    }

    public function testSinClaveNoSeMandaLaPrueba()
    {
        CuentaSmtp::usarClave(null);
        $this->puedeGestionar();

        $this->assertError(503, $this->probar());
        $this->assertSame([], $this->mailer->enviados);
    }

    // --------------------------------------------------------------- GET

    public function testSinCuentaDevuelveNullYLosPredeterminadosDeLaPagina()
    {
        $this->puedeGestionar();
        $this->db->onSelect('SELECT title, email_contacto FROM pages', [['title' => 'La Sala', 'email_contacto' => 'hola@lasala.com']]);

        $res = CuentaSmtpHandler::index($this->db, $this->get(['page_id' => 5], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertNull($res->body['cuenta']);
        $this->assertSame(['remitente_nombre' => 'La Sala', 'responder_a' => 'hola@lasala.com'], $res->body['predeterminados']);
    }

    public function testLaContrasenaNuncaVuelveAlNavegador()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $res = CuentaSmtpHandler::index($this->db, $this->get(['page_id' => 5], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertTrue($res->body['cuenta']['tiene_contrasena']);
        $json = json_encode($res->body);
        $this->assertStringNotContainsString('clave-vieja', $json);
        $this->assertStringNotContainsString('contrasena_cifrada', $json);
        $this->assertStringNotContainsString('v1:', $json);
    }

    // ------------------------------------------------------------ guardar

    public function testGuardaLaContrasenaCifrada()
    {
        $this->puedeGestionar();

        $res = $this->guardar();

        $this->assertStatus(200, $res);
        $params = $this->db->paramsFor('INSERT INTO page_smtp');
        $this->assertSame([5, 'smtp.gmail.com', 587, 'tls', 'sala@gmail.com'], array_slice($params, 0, 5));
        $this->assertStringNotContainsString('abcd efgh', $params[5]);
        $this->assertSame('abcd efgh ijkl mnop', $this->contrasenaGuardada());
        $this->assertStringNotContainsString('abcd efgh', json_encode($res->body));
    }

    public function testLaContrasenaVaciaConservaLaAnterior()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $res = $this->guardar(['contrasena' => '', 'host' => 'smtp.office365.com']);

        $this->assertStatus(200, $res);
        $this->assertSame('clave-vieja', $this->contrasenaGuardada());
        $this->assertSame('smtp.office365.com', $this->db->paramsFor('INSERT INTO page_smtp')[1]);
    }

    public function testUnaContrasenaNuevaReemplazaLaAnterior()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $this->assertStatus(200, $this->guardar(['contrasena' => 'nueva']));
        $this->assertSame('nueva', $this->contrasenaGuardada());
    }

    public function testSinCuentaPreviaLaContrasenaEsObligatoria()
    {
        $this->puedeGestionar();

        $this->assertError(400, $this->guardar(['contrasena' => '']), 'contraseña');
        $this->assertNoWrites();
    }

    public function datosInvalidos()
    {
        return [
            'puerto fuera de rango' => [['port' => 70000], 'entre 1 y 65535'],
            'puerto que no es de correo' => [['port' => 22], '25, 465, 587 o 2525'],
            'seguridad fuera de la lista' => [['seguridad' => 'starttls'], 'seguridad'],
            'remitente inválido' => [['remitente_email' => 'x@'], 'remitente'],
            'responder a inválido' => [['responder_a' => 'nadie'], 'Responder a'],
            'servidor inválido' => [['host' => 'smtp gmail com'], 'servidor'],
        ];
    }

    /** @dataProvider datosInvalidos */
    public function testValidaLosCampos(array $cambios, $fragmento)
    {
        $this->puedeGestionar();

        $this->assertError(400, $this->guardar($cambios), $fragmento);
        $this->assertNoWrites();
    }

    public function testRechazaServidoresDeLaRedInterna()
    {
        $this->puedeGestionar();
        $this->assertError(400, $this->guardar(['host' => 'localhost']));

        foreach (['smtp.localhost', '10.0.0.8', '127.0.0.1', '192.168.0.20'] as $host) {
            $this->puedeGestionar();
            $this->assertError(400, $this->guardar(['host' => $host]), 'red interna');
        }

        CuentaSmtp::usarResolver(function () {
            return ['169.254.169.254'];
        });
        $this->puedeGestionar();
        $this->assertError(400, $this->guardar(['host' => 'smtp.trampa.com']), 'red interna');

        $this->assertNoWrites();
    }

    public function testCambiarLaCuentaBorraLaVerificacion()
    {
        $this->puedeGestionar();

        $this->guardar();

        $insert = $this->db->callsFor('INSERT INTO page_smtp')[0]['sql'];
        $this->assertStringContainsString('verificado_en = NULL', $insert);
    }

    // ------------------------------------------------------------- quitar

    public function testQuitarBorraLaCuenta()
    {
        $this->puedeGestionar();

        $res = CuentaSmtpHandler::index($this->db, $this->delete(['page_id' => 5], $this->user()));

        $this->assertStatus(200, $res);
        $this->assertSame([5], $this->db->paramsFor('DELETE FROM page_smtp WHERE page_id'));
    }

    // ------------------------------------------------------------- prueba

    private function listaParaProbar(array $cuenta = [])
    {
        $this->puedeGestionar();
        $this->conCuenta($cuenta);
        $this->db->onSelect('FROM page_smtp_pruebas', [['n' => 0]]);
        // La primera consulta del email es la de Plataforma, al revisar el permiso.
        $this->db->onSelect('SELECT email FROM users', [['email' => 'user@test.local']]);
        $this->db->onSelect('SELECT email FROM users', [['email' => 'duena@lasala.com']]);
        $this->db->onSelect('SELECT title, email_contacto FROM pages', [['title' => 'La Sala', 'email_contacto' => 'hola@lasala.com']]);
        $this->db->onInsert('INSERT INTO page_smtp_pruebas', 31);
    }

    public function testLaPruebaVaAlEmailDeQuienLaPideConLaCuentaGuardada()
    {
        $this->listaParaProbar();

        $res = $this->probar();

        $this->assertStatus(200, $res);
        $this->assertStringContainsString('duena@lasala.com', $res->body['mensaje']);
        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame('duena@lasala.com', $this->mailer->enviados[0]['para']);
        $this->assertSame('hola@lasala.com', $this->mailer->enviados[0]['responder'], 'responder a: el email de contacto');

        $cuenta = $this->mailer->cuentas[0];
        $this->assertSame('smtp.gmail.com', $cuenta['host']);
        $this->assertSame('142.250.0.108', $cuenta['ip'], 'se conecta a la IP ya revisada');
        $this->assertSame('clave-vieja', $cuenta['contrasena']);
        $this->assertSame('La Sala', $cuenta['remitente_nombre'], 'el nombre por defecto es el título');
    }

    public function testUnaPruebaExitosaMarcaLaCuentaComoVerificada()
    {
        $this->listaParaProbar();

        $this->probar();

        $this->assertSame([31], $this->db->paramsFor('UPDATE page_smtp_pruebas SET ok = 1'));
        $this->assertSame([5], $this->db->paramsFor('UPDATE page_smtp SET verificado_en = NOW()'));
    }

    public function testElNombreYResponderAGuardadosGananALosDeLaPagina()
    {
        $this->listaParaProbar(['remitente_nombre' => 'Boletería', 'responder_a' => 'ventas@lasala.com']);

        $this->probar();

        $this->assertSame('Boletería', $this->mailer->cuentas[0]['remitente_nombre']);
        $this->assertSame('ventas@lasala.com', $this->mailer->enviados[0]['responder']);
    }

    public function errores()
    {
        return [
            'autenticación' => ['autenticacion', 'contraseña de aplicación'],
            'conexión' => ['conexion', 'smtp.gmail.com por el puerto 587'],
            'certificado' => ['certificado', 'certificado'],
        ];
    }

    /** @dataProvider errores */
    public function testUnErrorDelServidorSeExplicaEnCastellano($motivo, $fragmento)
    {
        $this->listaParaProbar();
        $this->mailer->fallarCon('SMTP Error: algo', $motivo);

        $res = $this->probar();

        $this->assertError(502, $res, $fragmento);
        $this->assertSame($motivo, $res->body['motivo']);
        $this->assertFalse($this->db->ran('SET verificado_en = NOW()'));
    }

    public function testElLimiteEsDeCincoPruebasPorHora()
    {
        $this->puedeGestionar();
        $this->conCuenta();
        $this->db->onSelect('FROM page_smtp_pruebas', [['n' => 5]]);

        $res = $this->probar();

        $this->assertError(429, $res, '5 pruebas');
        $this->assertSame([], $this->mailer->enviados);
        $this->assertFalse($this->db->ran('INSERT INTO page_smtp_pruebas'));
        $this->assertStringContainsString('INTERVAL 1 HOUR', $this->db->callsFor('FROM page_smtp_pruebas')[0]['sql']);
    }

    public function testCadaPruebaQuedaAnotadaAntesDeMandarse()
    {
        $this->listaParaProbar();
        $this->mailer->fallarCon('timeout', 'conexion');

        $this->probar();

        $this->assertSame([5, 1], $this->db->paramsFor('INSERT INTO page_smtp_pruebas'));
    }

    public function testSinCuentaGuardadaNoHayPrueba()
    {
        $this->puedeGestionar();

        $this->assertError(404, $this->probar(), 'guardá');
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testSiElServidorPasoAApuntarALaRedInternaNoSeConecta()
    {
        $this->listaParaProbar();
        CuentaSmtp::usarResolver(function () {
            return ['127.0.0.1'];
        });

        $this->assertError(400, $this->probar(), 'red interna');
        $this->assertSame([], $this->mailer->enviados);
    }

    // ------------------------------------------------------------ ráfagas

    private function rafagas(array $datos, $user = null)
    {
        return CuentaSmtpHandler::rafagas($this->db, $this->put(array_merge(['page_id' => 5], $datos), $user ?: $this->user()));
    }

    public function testLaCuentaMuestraLasRafagasConLosValoresPorDefecto()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $res = CuentaSmtpHandler::index($this->db, $this->get(['page_id' => 5], $this->user()));

        $this->assertSame(['tamano' => 20, 'pausa_minutos' => 10, 'tope_diario' => null], $res->body['cuenta']['rafagas']);
    }

    public function testGuardaLasRafagas()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $res = $this->rafagas(['tamano' => '30', 'pausa_minutos' => 15, 'tope_diario' => '450']);

        $this->assertStatus(200, $res);
        $this->assertSame([30, 15, 450, 5], $this->db->paramsFor('UPDATE page_smtp SET rafaga_tamano'));
    }

    public function testElTopeDiarioVacioEsSinTope()
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $this->assertStatus(200, $this->rafagas(['tamano' => 20, 'pausa_minutos' => 10, 'tope_diario' => '']));
        $this->assertNull($this->db->paramsFor('UPDATE page_smtp SET rafaga_tamano')[2]);
    }

    public function rafagasInvalidas()
    {
        return [
            'ráfaga chica' => [['tamano' => 4, 'pausa_minutos' => 10], 'entre 5 y 100'],
            'ráfaga grande' => [['tamano' => 101, 'pausa_minutos' => 10], 'entre 5 y 100'],
            'pausa corta' => [['tamano' => 20, 'pausa_minutos' => 4], 'pausa'],
            'tope cero' => [['tamano' => 20, 'pausa_minutos' => 10, 'tope_diario' => 0], 'tope'],
            'tope con letras' => [['tamano' => 20, 'pausa_minutos' => 10, 'tope_diario' => 'mucho'], 'tope'],
        ];
    }

    /** @dataProvider rafagasInvalidas */
    public function testValidaLasRafagas(array $datos, $fragmento)
    {
        $this->puedeGestionar();
        $this->conCuenta();

        $this->assertError(400, $this->rafagas($datos), $fragmento);
        $this->assertNoWrites();
    }

    public function testLasRafagasNecesitanCuentaYPermiso()
    {
        $this->assertError(403, $this->rafagas(['tamano' => 20, 'pausa_minutos' => 10]));

        $this->puedeGestionar();
        $this->assertError(404, $this->rafagas(['tamano' => 20, 'pausa_minutos' => 10]));

        $this->assertError(401, CuentaSmtpHandler::rafagas($this->db, $this->put(['page_id' => 5])));
        $this->assertError(405, CuentaSmtpHandler::rafagas($this->db, $this->get(['page_id' => 5], $this->user())));
        $this->assertError(400, CuentaSmtpHandler::rafagas($this->db, $this->put([], $this->user())));
        $this->assertNoWrites();
    }

    public function testUnaContrasenaIlegibleSePideDeNuevo()
    {
        $this->listaParaProbar(['contrasena_cifrada' => 'v1:roto']);

        $this->assertError(409, $this->probar(), 'Volvé a cargarla');
        $this->assertSame([], $this->mailer->enviados);
    }
}
