<?php

namespace Tests\Unit\Lib;

use CuentaSmtp;
use ProcesadorDeEnvios;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

class ProcesadorDeEnviosTest extends HandlerTestCase
{
    /** @var FakeMailer */
    private $mailer;

    /** @var float[] Las esperas entre mail y mail */
    private $esperas = [];

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

    private function correr()
    {
        return ProcesadorDeEnvios::correr($this->db, $this->mailer, function ($segundos) {
            $this->esperas[] = $segundos;
        });
    }

    private function conLock($obtenido = true)
    {
        $this->db->onSelect('GET_LOCK', [['l' => $obtenido ? '1' : '0']]);
    }

    private function pagina(array $cambios = [])
    {
        $this->db->onSelect('SELECT DISTINCT e.page_id', [array_merge([
            'page_id' => 5, 'rafaga_pausa_minutos' => 10, 'ultima_rafaga_en' => null, 'ahora' => '2026-10-22 10:00:00',
        ], $cambios)]);
    }

    private function cuenta(array $cambios = [])
    {
        $this->db->onSelect('FROM page_smtp WHERE page_id', [array_merge([
            'page_id' => 5, 'host' => 'smtp.gmail.com', 'port' => '465', 'seguridad' => 'ssl',
            'usuario' => 'sala@gmail.com', 'contrasena_cifrada' => CuentaSmtp::cifrar('clave'),
            'remitente_email' => 'sala@gmail.com', 'remitente_nombre' => null, 'responder_a' => null,
            'verificado_en' => null, 'actualizado_en' => '2026-10-01 10:00:00',
            'rafaga_tamano' => 20, 'rafaga_pausa_minutos' => 10, 'tope_diario' => null, 'ultima_rafaga_en' => null,
        ], $cambios)]);
    }

    private function destinatario($id, $email, $nombre = '', array $cambios = [])
    {
        return array_merge([
            'id' => $id, 'envio_id' => 3, 'email' => $email, 'nombre' => $nombre, 'intentos' => 0,
            'asunto' => 'Novedades', 'cuerpo' => 'Hola {primer_nombre},', 'reemplazo_nombre' => '',
        ], $cambios);
    }

    private function pendientes(array $filas, array $bajas = [])
    {
        $this->db->onSelect('SELECT d.id, d.envio_id', $filas);
        $this->db->onSelect('SELECT title, email_contacto FROM pages', [['title' => 'La Sala', 'email_contacto' => 'hola@lasala.com']]);
        $this->db->onSelect('SELECT email FROM bajas_de_correo', array_map(function ($e) {
            return ['email' => $e];
        }, $bajas));
    }

    /** Todo listo para una ráfaga con estos destinatarios. */
    private function listo(array $filas, array $cuenta = [], array $bajas = [])
    {
        $this->conLock();
        $this->pagina();
        $this->cuenta($cuenta);
        $this->pendientes($filas, $bajas);
    }

    private function idsMarcados($estado)
    {
        return array_map(function ($c) {
            return end($c['params']);
        }, $this->db->callsFor("SET estado = '$estado'"));
    }

    private function posicion($fragmento, $n = 0)
    {
        $vistos = 0;
        foreach ($this->db->log() as $i => $entrada) {
            if (strpos(preg_replace('/\s+/', ' ', $entrada['sql']), $fragmento) !== false && $vistos++ === $n) {
                return $i;
            }
        }
        $this->fail("No se ejecutó: $fragmento");
    }

    // -------------------------------------------------------------- lock

    public function testSiOtraCorridaTieneElLockNoHaceNada()
    {
        $this->conLock(false);
        $this->pagina();
        $this->cuenta();
        $this->pendientes([$this->destinatario(1, 'ana@example.com')]);

        $resumen = $this->correr();

        $this->assertTrue($resumen['bloqueado']);
        $this->assertSame([], $this->mailer->enviados);
        $this->assertNoWrites();
        $this->assertFalse($this->db->ran('RELEASE_LOCK'), 'no suelta un lock que no es suyo');
    }

    public function testSueltaElLockAlTerminar()
    {
        $this->listo([$this->destinatario(1, 'ana@example.com')]);

        $this->correr();

        $this->assertStringContainsString("GET_LOCK('rezonar_envios_a_clientes', 0)", $this->db->log()[0]['sql']);
        $this->assertTrue($this->db->ran("RELEASE_LOCK('rezonar_envios_a_clientes')"));
    }

    // ------------------------------------------------------------- ráfaga

    public function testMandaUnaRafagaDelTamanoConfigurado()
    {
        $filas = [];
        for ($i = 1; $i <= 7; $i++) {
            $filas[] = $this->destinatario($i, "c$i@example.com");
        }
        $this->listo($filas, ['rafaga_tamano' => 7]);

        $resumen = $this->correr();

        $this->assertStringContainsString('LIMIT 7', $this->db->callsFor('SELECT d.id, d.envio_id')[0]['sql']);
        $this->assertCount(7, $this->mailer->enviados);
        $this->assertSame(7, $resumen['enviados']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->idsMarcados('enviado'));
        $this->assertSame([5], $this->db->paramsFor('UPDATE page_smtp SET ultima_rafaga_en = NOW()'));
    }

    public function testEntreMailYMailEsperaUnoODosSegundos()
    {
        $this->listo([$this->destinatario(1, 'a@example.com'), $this->destinatario(2, 'b@example.com'), $this->destinatario(3, 'c@example.com')]);

        $this->correr();

        $this->assertCount(2, $this->esperas, 'no antes del primero');
        foreach ($this->esperas as $segundos) {
            $this->assertGreaterThanOrEqual(1, $segundos);
            $this->assertLessThanOrEqual(2, $segundos);
        }
    }

    public function testMarcaCadaDestinatarioApenasLoManda()
    {
        $this->listo([$this->destinatario(1, 'a@example.com'), $this->destinatario(2, 'b@example.com')]);

        $this->correr();

        // El primero queda enviado antes de empezar con el segundo.
        $this->assertLessThan(
            $this->posicion('SET intentos = intentos + 1', 1),
            $this->posicion("SET estado = 'enviado'", 0)
        );
        $this->assertLessThan($this->posicion("SET estado = 'enviado'", 0), $this->posicion('SET intentos = intentos + 1', 0));
    }

    public function testElMailLlevaElNombreLaBajaYLaCuentaDeLaPagina()
    {
        $this->listo([$this->destinatario(1, 'ana@example.com', 'Ana Gómez')]);

        $this->correr();

        $mail = $this->mailer->enviados[0];
        $this->assertSame('ana@example.com', $mail['para']);
        $this->assertStringContainsString('Hola Ana,', $mail['texto']);
        $this->assertStringContainsString('No quiero recibir más mails de La Sala', $mail['html']);
        $this->assertStringContainsString('https://rezonar.test/api/public/baja.php?t=', $mail['cabeceras']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $mail['cabeceras']['List-Unsubscribe-Post']);
        $this->assertSame('hola@lasala.com', $mail['responder']);

        $cuenta = $this->mailer->cuentas[0];
        $this->assertSame('clave', $cuenta['contrasena']);
        $this->assertSame('142.250.0.108', $cuenta['ip']);
        $this->assertSame('La Sala', $cuenta['remitente_nombre']);
    }

    public function testSinNombreUsaElReemplazoDelEnvio()
    {
        $this->listo([$this->destinatario(1, 'x@example.com', '', ['reemplazo_nombre' => 'amigo'])]);

        $this->correr();

        $this->assertStringStartsWith('Hola amigo,', $this->mailer->enviados[0]['texto']);
    }

    public function testCierraLosEnviosQueSeQuedaronSinPendientes()
    {
        $this->listo([$this->destinatario(1, 'a@example.com')]);

        $this->correr();

        $this->assertSame([5], $this->db->paramsFor("UPDATE envios SET estado = 'terminado'"));
    }

    // -------------------------------------------------------------- pausa

    public function pausas()
    {
        return [
            'nunca mandó' => [null, '2026-10-22 10:00:00', true],
            'hace 5 minutos' => ['2026-10-22 09:55:00', '2026-10-22 10:00:00', false],
            'hace 10 minutos' => ['2026-10-22 09:50:00', '2026-10-22 10:00:00', true],
            'casi 10, dentro del margen del cron' => ['2026-10-22 09:50:20', '2026-10-22 10:00:00', true],
            'hace 9 minutos' => ['2026-10-22 09:51:00', '2026-10-22 10:00:00', false],
        ];
    }

    /** @dataProvider pausas */
    public function testRespetaLaPausaEntreRafagas($ultima, $ahora, $pasa)
    {
        $this->assertSame($pasa, ProcesadorDeEnvios::paso([
            'rafaga_pausa_minutos' => 10, 'ultima_rafaga_en' => $ultima, 'ahora' => $ahora,
        ]));
    }

    public function testUnaPaginaEnPausaNoManda()
    {
        $this->conLock();
        $this->pagina(['ultima_rafaga_en' => '2026-10-22 09:57:00']);
        $this->cuenta();
        $this->pendientes([$this->destinatario(1, 'a@example.com')]);

        $resumen = $this->correr();

        $this->assertSame(0, $resumen['paginas']);
        $this->assertSame([], $this->mailer->enviados);
        $this->assertFalse($this->db->ran('SELECT d.id, d.envio_id'));
    }

    public function testLosEnviosDePaginasSinCuentaSePausan()
    {
        $this->conLock();

        $this->correr();

        $pausa = $this->db->callsFor('LEFT JOIN page_smtp s ON s.page_id = e.page_id')[0];
        $this->assertStringContainsString('s.page_id IS NULL', $pausa['sql']);
        $this->assertStringContainsString('Configuración → Email', $pausa['params'][0]);
    }

    // --------------------------------------------------------- tope diario

    public function testElTopeDiarioAchicaLaRafaga()
    {
        $this->conLock();
        $this->pagina();
        $this->cuenta(['tope_diario' => 30]);
        $this->db->onSelect('d.enviado_en >= NOW() - INTERVAL 1 DAY', [['n' => 27]]);
        $this->pendientes([$this->destinatario(1, 'a@example.com')]);

        $this->correr();

        $this->assertStringContainsString('LIMIT 3', $this->db->callsFor('SELECT d.id, d.envio_id')[0]['sql']);
        $this->assertSame([5], $this->db->paramsFor('d.enviado_en >= NOW() - INTERVAL 1 DAY'));
    }

    public function testConElTopeDiarioCumplidoNoManda()
    {
        $this->conLock();
        $this->pagina();
        $this->cuenta(['tope_diario' => 30]);
        $this->db->onSelect('d.enviado_en >= NOW() - INTERVAL 1 DAY', [['n' => 30]]);
        $this->pendientes([$this->destinatario(1, 'a@example.com')]);

        $resumen = $this->correr();

        $this->assertSame([], $this->mailer->enviados);
        $this->assertFalse($this->db->ran('SELECT d.id, d.envio_id'));
        $this->assertFalse($this->db->ran('SET ultima_rafaga_en'), 'no gasta la pausa');
        $this->assertStringContainsString('tope de 30', $resumen['detalle'][0]);
    }

    public function testSinTopeNoCuentaLosDelDia()
    {
        $this->listo([$this->destinatario(1, 'a@example.com')]);

        $this->correr();

        $this->assertFalse($this->db->ran('INTERVAL 1 DAY'));
    }

    // ------------------------------------------------------------- fallos

    public function testUnFalloDeAutenticacionPausaElEnvioYNoQuemaLaLista()
    {
        $this->listo([$this->destinatario(1, 'a@example.com'), $this->destinatario(2, 'b@example.com')]);
        $this->mailer->fallarCon('SMTP Error: Could not authenticate.', 'autenticacion');

        $this->correr();

        $this->assertCount(1, $this->mailer->enviados, 'no sigue probando con el resto');
        $this->assertSame([1], $this->db->paramsFor("SET estado = 'pendiente', intentos = 0"));
        $this->assertSame([], $this->idsMarcados('fallido'));

        $pausa = $this->db->paramsFor("UPDATE envios SET estado = 'pausado', motivo_pausa = ? WHERE page_id = ?");
        $this->assertStringContainsString('contraseña de aplicación', $pausa[0]);
        $this->assertStringContainsString('reanudá', $pausa[0]);
        $this->assertSame(5, $pausa[1]);
    }

    public function testUnErrorDeConexionCortaLaRafagaSinPausar()
    {
        $this->listo([$this->destinatario(1, 'a@example.com'), $this->destinatario(2, 'b@example.com')]);
        $this->mailer->fallarCon('SMTP Error: Could not connect to SMTP host.', 'conexion');

        $this->correr();

        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame([1], $this->db->paramsFor("SET estado = 'pendiente', intentos = 0"));
        $this->assertFalse($this->db->ran("SET estado = 'pausado'"));
    }

    public function testElLimiteDelProveedorCortaLaRafaga()
    {
        $this->listo([$this->destinatario(1, 'a@example.com'), $this->destinatario(2, 'b@example.com')]);
        $this->mailer->fallarCon('550 5.4.5 Daily user sending quota exceeded.', 'otro');

        $this->correr();

        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame([], $this->idsMarcados('fallido'));
    }

    public function testUnDestinatarioRechazadoFallaYSigueConElResto()
    {
        $this->listo([$this->destinatario(1, 'a@example.com'), $this->destinatario(2, 'b@example.com')]);
        $this->mailer->fallarPara('a@example.com', 'SMTP Error: The following recipients failed: a@example.com', 'otro');

        $resumen = $this->correr();

        $this->assertCount(2, $this->mailer->enviados);
        $fallo = $this->db->paramsFor('UPDATE envio_destinatarios SET estado = ?, error = ?');
        $this->assertSame(['fallido', 'SMTP Error: The following recipients failed: a@example.com', 1], $fallo);
        $this->assertSame([2], $this->idsMarcados('enviado'));
        $this->assertSame(1, $resumen['fallidos']);
    }

    public function testUnaContrasenaIlegiblePausaSinMandar()
    {
        $this->listo([$this->destinatario(1, 'a@example.com')], ['contrasena_cifrada' => 'v1:roto']);

        $this->correr();

        $this->assertSame([], $this->mailer->enviados);
        $this->assertStringContainsString('contraseña', $this->db->paramsFor("SET estado = 'pausado', motivo_pausa = ?")[0]);
    }

    public function testSiElServidorApuntaALaRedInternaPausaSinMandar()
    {
        CuentaSmtp::usarResolver(function () {
            return ['10.0.0.1'];
        });
        $this->listo([$this->destinatario(1, 'a@example.com')]);

        $this->correr();

        $this->assertSame([], $this->mailer->enviados);
        $this->assertStringContainsString('red interna', $this->db->paramsFor("SET estado = 'pausado', motivo_pausa = ?")[0]);
    }

    // ---------------------------------------------- no mandar dos veces

    public function testUnoQueQuedoAMitadDeUnaCorridaCortadaNoSeReintenta()
    {
        $this->listo([$this->destinatario(1, 'a@example.com', '', ['intentos' => 1]), $this->destinatario(2, 'b@example.com')]);

        $this->correr();

        $this->assertSame(['b@example.com'], array_column($this->mailer->enviados, 'para'));
        $fallo = $this->db->paramsFor('UPDATE envio_destinatarios SET estado = ?, error = ?');
        $this->assertSame('fallido', $fallo[0]);
        $this->assertSame(ProcesadorDeEnvios::INTERRUMPIDO, $fallo[1]);
    }

    public function testLaBajaExcluyeAlMandar()
    {
        $this->listo([$this->destinatario(1, 'Ana@Example.com'), $this->destinatario(2, 'b@example.com')], [], ['ana@example.com']);

        $resumen = $this->correr();

        $this->assertSame(['b@example.com'], array_column($this->mailer->enviados, 'para'));
        $this->assertSame(['omitido', 'Se dio de baja', 1], $this->db->paramsFor('UPDATE envio_destinatarios SET estado = ?, error = ?'));
        $this->assertSame(1, $resumen['omitidos']);
    }

    public function testSiLoPausanDuranteLaRafagaNoSigue()
    {
        $this->listo([$this->destinatario(1, 'a@example.com'), $this->destinatario(2, 'b@example.com')]);
        $this->db->onSelect('SELECT estado FROM envios WHERE id', [['estado' => 'en_curso']]);
        $this->db->onSelect('SELECT estado FROM envios WHERE id', [['estado' => 'cancelado']]);

        $this->correr();

        $this->assertSame(['a@example.com'], array_column($this->mailer->enviados, 'para'));
    }
}
