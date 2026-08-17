<?php

namespace Tests\Unit\Lib;

use CorreoEntradas;
use Tests\Support\FakeMailer;
use Tests\Support\HandlerTestCase;

class CorreoEntradasTest extends HandlerTestCase
{
    const CODIGO = 'ABC123DEF456';

    private function hayOrden(array $overrides = [])
    {
        $this->db->onSelect('FROM ticket_orders o', [array_merge([
            'id' => 1,
            'codigo' => self::CODIGO,
            'link_id' => 100,
            'nombre' => 'Ana Gómez',
            'email' => 'ana@example.com',
            'telefono' => '1122334455',
            'cantidad' => 2,
            'total' => '3000.00',
            'moneda' => 'ARS',
            'estado' => 'pagada',
            'mail_enviado_en' => null,
            'mail_intentos' => 0,
            'evento' => 'Fiesta de fin de año',
            'event_date' => '2026-12-01',
            'event_time' => '21:00:00',
            'event_address' => 'Av. Corrientes 1234',
            'pagina' => 'Mi Página',
            'url_slug' => 'mi-pagina',
        ], $overrides)]);
    }

    private function enviar($mailer = null)
    {
        $this->db->onWrite('UPDATE ticket_orders', 1);

        return CorreoEntradas::enviar($this->db, self::CODIGO, $mailer === null ? new FakeMailer() : $mailer);
    }

    // ----------------------------------------------------------- cuándo sale

    public function testSeMandaCuandoLaOrdenEstaPagada()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();

        $r = CorreoEntradas::enviar($this->db, self::CODIGO, $mailer);

        $this->assertTrue($r['enviado']);
        $this->assertCount(1, $mailer->enviados);
    }

    /** Una reserva a medio pagar no es una entrada todavía. */
    public function testNoSeMandaSiLaOrdenNoEstaPagada()
    {
        $this->hayOrden(['estado' => 'reservada']);
        $mailer = new FakeMailer();

        $r = CorreoEntradas::enviar($this->db, self::CODIGO, $mailer);

        $this->assertFalse($r['enviado']);
        $this->assertSame([], $mailer->enviados);
    }

    /** Mandar dos veces la misma entrada hace dudar de cuál vale. */
    public function testNoSeMandaDosVeces()
    {
        $this->hayOrden(['mail_enviado_en' => '2026-08-16 20:00:00']);
        $mailer = new FakeMailer();

        $r = CorreoEntradas::enviar($this->db, self::CODIGO, $mailer);

        $this->assertFalse($r['enviado']);
        $this->assertSame('ya se había enviado', $r['motivo']);
        $this->assertSame([], $mailer->enviados);
    }

    /** Una dirección que rebota no se reintenta para siempre. */
    public function testDejaDeInsistirDespuesDeVariosIntentos()
    {
        $this->hayOrden(['mail_intentos' => CorreoEntradas::MAX_INTENTOS]);
        $mailer = new FakeMailer();

        $r = CorreoEntradas::enviar($this->db, self::CODIGO, $mailer);

        $this->assertFalse($r['enviado']);
        $this->assertSame([], $mailer->enviados);
    }

    public function testUnaOrdenInexistenteNoRompe()
    {
        $r = CorreoEntradas::enviar($this->db, 'NO-EXISTE', new FakeMailer());

        $this->assertFalse($r['enviado']);
        $this->assertSame('orden inexistente', $r['motivo']);
    }

    // ------------------------------------------------------------ contenido

    public function testVaDirigidoAlComprador()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $mensaje = $mailer->mensajePara('ana@example.com');

        $this->assertNotNull($mensaje);
        $this->assertSame('Ana Gómez', $mensaje['paraNombre']);
    }

    public function testElAsuntoDiceDeQueEventoEs()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $this->assertSame('Tu entrada para Fiesta de fin de año', $mailer->enviados[0]['asunto']);
    }

    public function testElCuerpoLlevaElCodigo()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $this->assertStringContainsString(self::CODIGO, $mailer->enviados[0]['html']);
        $this->assertStringContainsString(self::CODIGO, $mailer->enviados[0]['texto']);
    }

    public function testLlevaLosDatosDelEvento()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $html = $mailer->enviados[0]['html'];

        $this->assertStringContainsString('Fiesta de fin de año', $html);
        $this->assertStringContainsString('Av. Corrientes 1234', $html);
        $this->assertStringContainsString('Ana Gómez', $html);
    }

    public function testLlevaLaCantidadEnPluralYSingular()
    {
        $this->hayOrden(['cantidad' => 1]);
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $this->assertStringContainsString('1 entrada a nombre', $mailer->enviados[0]['html']);
    }

    /** Gmail bloquea las imágenes en base64: el QR tiene que ir por cid. */
    public function testElQrVaIncrustadoPorCid()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $mensaje = $mailer->enviados[0];

        $this->assertNotEmpty($mensaje['imagenes'], 'tiene que adjuntar el QR');
        $this->assertSame('qr', $mensaje['imagenes'][0]['cid']);
        $this->assertStringContainsString('src="cid:qr"', $mensaje['html']);
    }

    public function testSiempreLlevaAlternativaEnTextoPlano()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $this->assertNotEmpty($mailer->enviados[0]['texto']);
        $this->assertStringNotContainsString('<', $mailer->enviados[0]['texto']);
    }

    /** Un nombre con comillas o etiquetas no puede romper el HTML del mail. */
    public function testElContenidoDelUsuarioSeEscapa()
    {
        $this->hayOrden(['nombre' => 'Ana "<script>alert(1)</script>"']);
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $this->assertStringNotContainsString('<script>', $mailer->enviados[0]['html']);
    }

    public function testElBotonLlevaALaPaginaDeLaOrden()
    {
        $this->hayOrden();
        $mailer = new FakeMailer();
        $this->enviar($mailer);

        $this->assertStringContainsString('/entrada/' . self::CODIGO, $mailer->enviados[0]['html']);
    }

    // --------------------------------------------------------------- fechas

    public function testLaFechaSeEscribeEnCastellano()
    {
        $this->assertSame('martes 1 de diciembre a las 21:00',
            CorreoEntradas::fechaLegible('2026-12-01', '21:00:00'));
    }

    public function testSinHoraNoSeInventaUna()
    {
        $this->assertSame('martes 1 de diciembre', CorreoEntradas::fechaLegible('2026-12-01'));
    }

    /** En la base hay eventos viejos con la fecha en cero. */
    public function testUnaFechaVaciaNoProduceUnaFechaAbsurda()
    {
        $this->assertNull(CorreoEntradas::fechaLegible('0000-00-00'));
        $this->assertNull(CorreoEntradas::fechaLegible(null));
        $this->assertNull(CorreoEntradas::fechaLegible(''));
    }

    public function testUnEventoSinFechaSeMandaIgual()
    {
        $this->hayOrden(['event_date' => null, 'event_time' => null]);
        $mailer = new FakeMailer();

        $this->assertTrue($this->enviar($mailer)['enviado']);
    }

    // -------------------------------------------------------------- anotado

    public function testAlEnviarSeAnotaLaFecha()
    {
        $this->hayOrden();
        $this->enviar();

        $this->assertStringContainsString('mail_enviado_en = NOW()',
            $this->db->callsFor('UPDATE ticket_orders')[0]['sql']);
    }

    /**
     * Sin anotar el motivo no habría forma de distinguir "no salió todavía" de
     * "la dirección no existe", y se reintentaría a ciegas.
     */
    public function testUnFalloSeAnotaConSuMotivo()
    {
        $this->hayOrden();
        $mailer = (new FakeMailer())->fallarCon('SMTP connect() failed');

        $r = $this->enviar($mailer);

        $this->assertFalse($r['enviado']);
        $this->assertSame('SMTP connect() failed', $r['motivo']);
        $this->assertContains('SMTP connect() failed', $this->db->paramsFor('UPDATE ticket_orders'));
    }

    public function testUnFalloNoMarcaLaEntradaComoEnviada()
    {
        $this->hayOrden();
        $mailer = (new FakeMailer())->fallarCon('rechazado');

        $this->enviar($mailer);

        $this->assertStringNotContainsString('mail_enviado_en = NOW()',
            $this->db->callsFor('UPDATE ticket_orders')[0]['sql']);
    }

    // ------------------------------------------------------------ pendientes

    public function testLoPendienteSonLasPagadasSinEnviar()
    {
        $this->db->onSelect('FROM ticket_orders', [['codigo' => 'AAA'], ['codigo' => 'BBB']]);

        $pendientes = CorreoEntradas::pendientes($this->db);
        $sql = $this->db->callsFor('FROM ticket_orders')[0]['sql'];

        $this->assertSame(['AAA', 'BBB'], $pendientes);
        $this->assertStringContainsString("estado = 'pagada'", $sql);
        $this->assertStringContainsString('mail_enviado_en IS NULL', $sql);
        $this->assertStringContainsString('mail_intentos <', $sql);
    }

    public function testSinPendientesNoSeMandaNada()
    {
        $mailer = new FakeMailer();

        $resumen = CorreoEntradas::enviarPendientes($this->db, $mailer);

        $this->assertSame(0, $resumen['enviados']);
        $this->assertSame([], $mailer->enviados);
    }

    // ================================================== la imagen del evento

    /** El afiche es lo que hace reconocer el mail de un vistazo entre veinte. */
    public function testElMailLlevaLaImagenDelEvento()
    {
        $this->hayOrden(['evento_imagen' => 'https://rezon.ar/uploads/afiche.jpg']);
        $mailer = new FakeMailer();

        $this->enviar($mailer);

        $this->assertStringContainsString('https://rezon.ar/uploads/afiche.jpg', $mailer->enviados[0]['html']);
    }

    public function testUnEventoSinImagenMandaElMailIgual()
    {
        $this->hayOrden(['evento_imagen' => null]);
        $mailer = new FakeMailer();

        $r = $this->enviar($mailer);

        $this->assertTrue($r['enviado']);
        $this->assertStringNotContainsString('<img src="http', $mailer->enviados[0]['html']);
    }

    /**
     * Una ruta relativa resuelve en el navegador contra el sitio y en un
     * cliente de correo contra nada: se vería el ícono de imagen rota arriba
     * de la entrada.
     */
    public function testUnaImagenConRutaRelativaNoVaAlMail()
    {
        $this->assertFalse(CorreoEntradas::imagenValida(['evento_imagen' => '/uploads/afiche.jpg']));
        $this->assertFalse(CorreoEntradas::imagenValida(['evento_imagen' => 'afiche.jpg']));
        $this->assertTrue(CorreoEntradas::imagenValida(['evento_imagen' => 'https://x/afiche.jpg']));
    }

    /** El QR sigue yendo adjunto: tiene que verse aunque bloqueen imágenes. */
    public function testLaImagenDelEventoNoDesplazaAlQrAdjunto()
    {
        $this->hayOrden(['evento_imagen' => 'https://rezon.ar/uploads/afiche.jpg']);
        $mailer = new FakeMailer();

        $this->enviar($mailer);

        $cids = array_column($mailer->enviados[0]['imagenes'], 'cid');

        $this->assertContains('qr', $cids);
    }

    // ============================================= el contacto del organizador

    /**
     * El mail sale de la casilla de la plataforma porque es la que el SPF del
     * dominio autoriza; el Reply-To es lo que hace que "responder" llegue a
     * quien organiza y no a nosotros.
     */
    public function testResponderLeLlegaAlOrganizador()
    {
        $this->hayOrden(['email_contacto' => 'hola@lasala.com']);
        $mailer = new FakeMailer();

        $this->enviar($mailer);

        $this->assertSame('hola@lasala.com', $mailer->enviados[0]['responder']);
    }

    /** Un Reply-To a una casilla que nadie lee es peor que no ofrecer responder. */
    public function testSinContactoCargadoNoSePoneReplyTo()
    {
        $this->hayOrden(['email_contacto' => null]);
        $mailer = new FakeMailer();

        $this->enviar($mailer);

        $this->assertArrayNotHasKey('responder', $mailer->enviados[0]);
    }

    public function testSinContactoElMailNoInvitaAResponder()
    {
        $this->hayOrden(['email_contacto' => '']);
        $mailer = new FakeMailer();

        $this->enviar($mailer);

        $this->assertStringNotContainsString('respondé este mail', $mailer->enviados[0]['html']);
    }

    public function testConContactoElMailInvitaAResponder()
    {
        $this->hayOrden(['email_contacto' => 'hola@lasala.com']);
        $mailer = new FakeMailer();

        $this->enviar($mailer);

        $this->assertStringContainsString('respondé este mail', $mailer->enviados[0]['html']);
    }

    /**
     * Una dirección rota en el Reply-To puede hacer que el servidor rechace el
     * mensaje entero: perder la entrada por un contacto mal tipeado sería
     * desproporcionado.
     */
    public function testUnContactoInvalidoSeIgnoraYLaEntradaSaleIgual()
    {
        $this->hayOrden(['email_contacto' => 'esto no es un mail']);
        $mailer = new FakeMailer();

        $r = $this->enviar($mailer);

        $this->assertTrue($r['enviado']);
        $this->assertArrayNotHasKey('responder', $mailer->enviados[0]);
    }
}
