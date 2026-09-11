<?php

namespace Tests\Unit\Handlers;

use Analytics;
use MetricasHandler;
use Tests\Support\HandlerTestCase;

/**
 * Las métricas de una página son de quien la administra y de nadie más.
 *
 * Son datos de terceros —cuánta gente entró, de dónde, desde qué ciudad— así
 * que lo primero que hay que fijar es el permiso, y que un "no" no se note
 * distinto de una página que no existe.
 */
class MetricasHandlerTest extends HandlerTestCase
{
    private function puedeAdministrar($puede = true)
    {
        $this->db->onSelect('SELECT email FROM users', [['alguien@test']]);
        $this->db->onSelect('SELECT 1 FROM pages p', $puede ? [[1]] : []);
    }

    private function laPagina($slug = 'la-banda', $dominio = null)
    {
        $this->db->onSelect('SELECT url_slug, dominio FROM pages', [
            ['url_slug' => $slug, 'dominio' => $dominio],
        ]);
    }

    // --------------------------------------------------------------- permisos

    public function testExigeSesion()
    {
        $this->assertStatus(401, MetricasHandler::pagina($this->db, $this->get(['page_id' => 1])));
    }

    /**
     * 404 y no 403: para quien no administra esa página, sus métricas no
     * existen. Un 403 confirmaría que la página sí.
     */
    public function testLaPaginaDeOtroNoExiste()
    {
        $this->puedeAdministrar(false);

        $res = MetricasHandler::pagina($this->db, $this->get(['page_id' => 77], $this->user(9)), $this->analytics());

        $this->assertStatus(404, $res);
    }

    /** Y sobre todo: no se le pide nada a Google por una página ajena. */
    public function testPorUnaPaginaAjenaNoSePideNingunInforme()
    {
        $this->puedeAdministrar(false);
        $analytics = $this->analytics();

        MetricasHandler::pagina($this->db, $this->get(['page_id' => 77], $this->user(9)), $analytics);

        $this->assertNull($analytics->pedido);
    }

    public function testSoloAceptaGet()
    {
        $res = MetricasHandler::pagina($this->db, $this->post([], $this->user(9)), $this->analytics());

        $this->assertStatus(405, $res);
    }

    // ---------------------------------------------------------------- informe

    public function testDevuelveElInformeDeLaPagina()
    {
        $this->puedeAdministrar();
        $this->laPagina('la-banda');
        $analytics = $this->analytics(['configurado' => true, 'resumen' => ['actual' => ['visitas' => 300]]]);

        $res = MetricasHandler::pagina($this->db, $this->get(['page_id' => 3], $this->user(9)), $analytics);

        $this->assertStatus(200, $res);
        $this->assertSame(300, $res->body['resumen']['actual']['visitas']);
    }

    /** Se le pasa el slug y el dominio propio: son los dos el mismo lugar. */
    public function testPideLosDatosDeEsaPaginaYNoDeOtra()
    {
        $this->puedeAdministrar();
        $this->laPagina('la-banda', 'labanda.com.ar');
        $analytics = $this->analytics();

        MetricasHandler::pagina($this->db, $this->get(['page_id' => 3], $this->user(9)), $analytics);

        $this->assertSame(['la-banda', 'labanda.com.ar', 30], $analytics->pedido);
    }

    public function testSeLePuedeCambiarLaVentana()
    {
        $this->puedeAdministrar();
        $this->laPagina();
        $analytics = $this->analytics();

        MetricasHandler::pagina($this->db, $this->get(['page_id' => 3, 'dias' => 7], $this->user(9)), $analytics);

        $this->assertSame(7, $analytics->pedido[2]);
    }

    /** Una ventana disparatada no llega a Google: se acota antes. */
    public function testLaVentanaSeAcota()
    {
        $this->puedeAdministrar();
        $this->laPagina();
        $analytics = $this->analytics();

        MetricasHandler::pagina($this->db, $this->get(['page_id' => 3, 'dias' => 99999], $this->user(9)), $analytics);

        $this->assertSame(365, $analytics->pedido[2]);
    }

    // ------------------------------------------------------------- sin conectar

    /**
     * Sin credenciales no se rompió nada: falta configurarlo, y eso se hace en
     * Google, no acá. Un error le diría a quien administra una página que el
     * sitio anda mal.
     */
    public function testSinConectarLoDiceEnVezDeFallar()
    {
        $this->puedeAdministrar();
        $this->laPagina();
        $analytics = $this->analytics([], false);

        $res = MetricasHandler::pagina($this->db, $this->get(['page_id' => 3], $this->user(9)), $analytics);

        $this->assertStatus(200, $res);
        $this->assertFalse($res->body['configurado']);
        $this->assertNull($analytics->pedido);
    }

    /** Si Google contesta que no, se pasa el motivo: suele decir qué falta. */
    public function testUnErrorDeGoogleLlegaConSuMotivo()
    {
        $this->puedeAdministrar();
        $this->laPagina();
        $analytics = $this->analytics(['error' => 'User does not have sufficient permissions']);

        $res = MetricasHandler::pagina($this->db, $this->get(['page_id' => 3], $this->user(9)), $analytics);

        $this->assertStatus(502, $res);
        $this->assertStringContainsString('sufficient permissions', $res->body['error']);
    }

    // -------------------------------------------------------------- ayudantes

    private function analytics(array $informe = ['configurado' => true], $listo = true)
    {
        return new class($informe, $listo) extends Analytics {
            public $pedido = null;
            private $informe;
            private $listo;

            public function __construct(array $informe, $listo)
            {
                $this->informe = $informe;
                $this->listo = $listo;
            }

            public function estaListo()
            {
                return $this->listo;
            }

            public function dePagina($slug, $dominio, $dias, $hoy = null)
            {
                $this->pedido = [$slug, $dominio, $dias];

                return $this->informe;
            }
        };
    }
}
