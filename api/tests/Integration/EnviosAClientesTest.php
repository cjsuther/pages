<?php

namespace Tests\Integration;

use BajasDeCorreo;
use Clientes;
use ClientesHandler;
use CuentaSmtp;
use EnviosHandler;
use ProcesadorDeEnvios;
use Tests\Support\BaseDescartable;
use Tests\Support\FakeMailer;
use Tests\Support\IntegracionTestCase;

/**
 * Los envíos a clientes contra una MariaDB de verdad (#37): que los
 * destinatarios por filtros salgan de la misma consulta que Clientes, y que
 * el procesador respete las ráfagas, la pausa y el lock con el reloj y los
 * locks del motor.
 */
class EnviosAClientesTest extends IntegracionTestCase
{
    private $duena;
    private $pageId;
    private $eventoA;
    private $eventoB;

    protected function setUp(): void
    {
        parent::setUp();
        CuentaSmtp::olvidar();
        CuentaSmtp::usarResolver(function () {
            return ['142.250.0.108'];
        });

        $this->duena = $this->usuario('duena@lasala.com', 'Dueña');
        $this->pageId = $this->pagina($this->duena, 'la-sala', 'La Sala');
        $grupo = $this->grupo($this->pageId);
        $this->eventoA = $this->evento($grupo, ['text' => 'Show A']);
        $this->eventoB = $this->evento($grupo, ['text' => 'Show B']);
    }

    protected function tearDown(): void
    {
        CuentaSmtp::olvidar();
        parent::tearDown();
    }

    // ------------------------------------------------------------- datos

    private function compra($linkId, $email, $nombre, $total = 1000)
    {
        $recordId = Clientes::registrarEvento($this->db, $linkId);

        $this->insertar('ticket_orders', [
            'codigo' => substr(md5($email . $linkId), 0, 12),
            'link_id' => $linkId,
            'record_id' => $recordId,
            'nombre' => $nombre,
            'email' => $email,
            'telefono' => '11 5555-0000',
            'cantidad' => 1,
            'precio_unitario' => $total,
            'total' => $total,
            'estado' => 'pagada',
        ]);
    }

    private function cuentaSmtp($tamano = 5, $pausa = 10, $tope = null)
    {
        CuentaSmtp::guardar($this->db, $this->pageId, [
            'host' => 'smtp.gmail.com', 'port' => 465, 'seguridad' => 'ssl', 'usuario' => 'lasala@gmail.com',
            'remitente_email' => 'lasala@gmail.com', 'remitente_nombre' => null, 'responder_a' => null,
        ], CuentaSmtp::cifrar('clave'));
        CuentaSmtp::guardarRafagas($this->db, $this->pageId, ['tamano' => $tamano, 'pausa_minutos' => $pausa, 'tope_diario' => $tope]);
    }

    private function crearEnvio(array $seleccion)
    {
        return EnviosHandler::index($this->db, $this->post([
            'page_id' => $this->pageId,
            'mensaje' => ['asunto' => 'Novedades', 'cuerpo' => 'Hola {primer_nombre},', 'reemplazo_nombre' => ''],
            'seleccion' => $seleccion,
        ], $this->user($this->duena, 'duena@lasala.com')));
    }

    private function destinatarios($envioId)
    {
        $stmt = $this->db->prepare('SELECT email FROM envio_destinatarios WHERE envio_id = ? ORDER BY email');
        $stmt->execute([$envioId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function correr(FakeMailer $mailer)
    {
        return ProcesadorDeEnvios::correr($this->db, $mailer, function () {
        });
    }

    // ---------------------------------------------------- destinatarios

    public function testLosDestinatariosPorFiltrosSalenDeLaConsultaDeClientes()
    {
        $this->cuentaSmtp();
        $this->compra($this->eventoA, 'ana@example.com', 'Ana Gómez');
        $this->compra($this->eventoA, 'Beto@Example.com', 'Beto');
        $this->compra($this->eventoA, 'caro@example.com', 'Caro');
        $this->compra($this->eventoA, 'dani@example.com', 'Dani');
        $this->compra($this->eventoB, 'eva@example.com', 'Eva');
        // De otra página: nunca entra.
        $otra = $this->pagina($this->usuario('otra@test.local', 'Otra'), 'otra', 'Otra');
        $this->compra($this->evento($this->grupo($otra)), 'fede@example.com', 'Fede');
        BajasDeCorreo::dar($this->db, $this->pageId, 'CARO@example.com');

        $recordA = Clientes::registroDelEvento($this->db, $this->eventoA);
        $res = $this->crearEnvio(['modo' => 'filtros', 'filtros' => ['evento' => $recordA], 'excluidos' => ['dani@example.com']]);

        $this->assertStatus(201, $res);
        $this->assertSame(2, $res->body['total']);
        $this->assertSame(1, $res->body['de_baja']);
        $this->assertSame(['ana@example.com', 'Beto@Example.com'], $this->destinatarios($res->body['envio']['id']));
        $this->assertSame(2, $res->body['envio']['pendientes']);
        $this->assertSame('en_curso', $res->body['envio']['estado']);
    }

    public function testPorEmailsNoEntraNadieQueNoSeaCliente()
    {
        $this->cuentaSmtp();
        $this->compra($this->eventoA, 'ana@example.com', 'Ana');
        $otra = $this->pagina($this->usuario('otra@test.local', 'Otra'), 'otra', 'Otra');
        $this->compra($this->evento($this->grupo($otra)), 'fede@example.com', 'Fede');

        $res = $this->crearEnvio(['modo' => 'emails', 'emails' => ['ana@example.com', 'fede@example.com']]);

        $this->assertStatus(201, $res);
        $this->assertSame(['ana@example.com'], $this->destinatarios($res->body['envio']['id']));
        $this->assertSame(1, $res->body['ignorados']);
    }

    public function testLaBajaPorElLinkSeVeEnClientesYExcluyeDeLosEnvios()
    {
        $this->cuentaSmtp();
        $this->compra($this->eventoA, 'ana@example.com', 'Ana');
        $this->compra($this->eventoA, 'beto@example.com', 'Beto');

        $baja = EnviosHandler::baja($this->db, $this->post([], null, ['t' => BajasDeCorreo::token($this->pageId, 'Ana@example.com')]));
        $this->assertStatus(200, $baja);
        $this->assertSame('La Sala', $baja->body['pagina']);

        $clientes = ClientesHandler::index($this->db, $this->get(['page_id' => $this->pageId], $this->user($this->duena)));
        // La lista va por última actividad: si las dos compras caen en
        // segundos distintos, Beto sale primero. Lo que importa es la marca.
        $bajas = array_column($clientes->body['clientes'], 'baja', 'email');
        ksort($bajas);
        $this->assertSame(['ana@example.com' => true, 'beto@example.com' => false], $bajas);
        $this->assertSame(1, $clientes->body['resumen']['contactables']);

        $res = $this->crearEnvio(['modo' => 'filtros']);
        $this->assertSame(['beto@example.com'], $this->destinatarios($res->body['envio']['id']));
    }

    // -------------------------------------------------------- procesador

    public function testMandaPorRafagasYRespetaLaPausa()
    {
        $this->cuentaSmtp(5, 10);
        for ($i = 1; $i <= 7; $i++) {
            $this->compra($this->eventoA, "c$i@example.com", "Cliente $i");
        }
        $envioId = $this->crearEnvio(['modo' => 'filtros'])->body['envio']['id'];
        $mailer = new FakeMailer();

        $this->assertSame(5, $this->correr($mailer)['enviados']);
        $this->assertSame(5, (int) $this->valor("SELECT COUNT(*) FROM envio_destinatarios WHERE estado = 'enviado' AND enviado_en IS NOT NULL"));

        // Todavía no pasaron los 10 minutos.
        $this->assertSame(0, $this->correr($mailer)['enviados']);
        $this->assertCount(5, $mailer->enviados);

        $this->db->exec('UPDATE page_smtp SET ultima_rafaga_en = NOW() - INTERVAL 10 MINUTE');
        $this->assertSame(2, $this->correr($mailer)['enviados']);

        $this->assertCount(7, array_unique(array_column($mailer->enviados, 'para')), 'a nadie dos veces');
        $this->assertSame('terminado', $this->valor('SELECT estado FROM envios WHERE id = ?', [$envioId]));
    }

    public function testElTopeDiarioCuentaLasUltimas24Horas()
    {
        $this->cuentaSmtp(5, 5, 6);
        for ($i = 1; $i <= 8; $i++) {
            $this->compra($this->eventoA, "c$i@example.com", "Cliente $i");
        }
        $this->crearEnvio(['modo' => 'filtros']);
        $mailer = new FakeMailer();

        $this->assertSame(5, $this->correr($mailer)['enviados']);
        $this->db->exec('UPDATE page_smtp SET ultima_rafaga_en = NOW() - INTERVAL 1 HOUR');
        $this->assertSame(1, $this->correr($mailer)['enviados']);
        $this->db->exec('UPDATE page_smtp SET ultima_rafaga_en = NOW() - INTERVAL 1 HOUR');
        $this->assertSame(0, $this->correr($mailer)['enviados']);

        // Al día siguiente vuelve a haber cupo.
        $this->db->exec('UPDATE envio_destinatarios SET enviado_en = NOW() - INTERVAL 25 HOUR WHERE enviado_en IS NOT NULL');
        $this->assertSame(2, $this->correr($mailer)['enviados']);
    }

    public function testConElLockTomadoPorOtraCorridaNoManda()
    {
        $this->cuentaSmtp();
        $this->compra($this->eventoA, 'ana@example.com', 'Ana');
        $this->crearEnvio(['modo' => 'filtros']);

        $otra = BaseDescartable::conectar(self::BASE);
        $this->assertSame('1', (string) $otra->query("SELECT GET_LOCK('" . ProcesadorDeEnvios::LOCK . "', 0)")->fetchColumn());

        $mailer = new FakeMailer();
        $this->assertTrue($this->correr($mailer)['bloqueado']);
        $this->assertSame([], $mailer->enviados);

        $otra->query("SELECT RELEASE_LOCK('" . ProcesadorDeEnvios::LOCK . "')");
        $otra = null;

        $this->assertSame(1, $this->correr($mailer)['enviados']);
    }

    public function testUnFalloDeAutenticacionPausaYSeVeEnLaPantalla()
    {
        $this->cuentaSmtp();
        $this->compra($this->eventoA, 'ana@example.com', 'Ana');
        $this->compra($this->eventoA, 'beto@example.com', 'Beto');
        $envioId = $this->crearEnvio(['modo' => 'filtros'])->body['envio']['id'];
        $mailer = (new FakeMailer())->fallarCon('SMTP Error: Could not authenticate.', 'autenticacion');

        $this->correr($mailer);

        $lista = EnviosHandler::index($this->db, $this->get(['page_id' => $this->pageId], $this->user($this->duena)));
        $envio = $lista->body['envios'][0];
        $this->assertSame($envioId, $envio['id']);
        $this->assertSame('pausado', $envio['estado']);
        $this->assertStringContainsString('contraseña', $envio['motivo_pausa']);
        $this->assertSame(2, $envio['pendientes'], 'no quema la lista');

        // Reanudado con la cuenta arreglada, sale todo.
        EnviosHandler::index($this->db, $this->put(['page_id' => $this->pageId, 'id' => $envioId, 'accion' => 'reanudar'], $this->user($this->duena)));
        $this->db->exec('UPDATE page_smtp SET ultima_rafaga_en = NULL');
        $this->assertSame(2, $this->correr(new FakeMailer())['enviados']);
    }

    public function testCancelarOmiteLosPendientes()
    {
        $this->cuentaSmtp();
        $this->compra($this->eventoA, 'ana@example.com', 'Ana');
        $envioId = $this->crearEnvio(['modo' => 'filtros'])->body['envio']['id'];

        $res = EnviosHandler::index($this->db, $this->put(['page_id' => $this->pageId, 'id' => $envioId, 'accion' => 'cancelar'], $this->user($this->duena)));

        $this->assertStatus(200, $res);
        $this->assertSame('cancelado', $res->body['envio']['estado']);
        $this->assertSame(1, $res->body['envio']['omitidos']);
        $this->assertSame([], (function () {
            $mailer = new FakeMailer();
            $this->correr($mailer);
            return $mailer->enviados;
        })());
    }
}
