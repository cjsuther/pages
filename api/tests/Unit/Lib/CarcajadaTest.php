<?php

namespace Tests\Unit\Lib;

use Carcajada;
use Tests\Support\HandlerTestCase;

class CarcajadaTest extends HandlerTestCase
{
    // --------------------------------------------------------- productores

    /**
     * Producir el ciclo no es administrar Rezonar: si fuera la misma lista,
     * dar de alta a quien produce le daría acceso a las páginas de todos.
     */
    public function testProducirNoEsAdministrarLaPlataforma()
    {
        $this->db->onSelect('FROM users WHERE id', [['email' => 'comediante@test.local']]);
        $this->db->onSelect('FROM carcajada_productores', []);

        $this->assertFalse(Carcajada::esProductor($this->db, 7));
    }

    public function testQuienEstaEnLaListaProduce()
    {
        $this->db->onSelect('FROM users WHERE id', [['email' => 'productor@test.local']]);
        $this->db->onSelect('FROM carcajada_productores', [[1]]);

        $this->assertTrue(Carcajada::esProductor($this->db, 7));
    }

    // ------------------------------------------------------------- Instagram

    /**
     * Cada uno lo pega a su manera y en la pantalla del público se arma el
     * link: si se guardara tal cual, la mitad quedarían rotos.
     */
    public function testElInstagramSeGuardaComoUsuario()
    {
        $this->assertSame('anagomez', Carcajada::usuarioDeInstagram('@anagomez'));
        $this->assertSame('anagomez', Carcajada::usuarioDeInstagram('https://instagram.com/anagomez'));
        $this->assertSame('anagomez', Carcajada::usuarioDeInstagram('https://www.instagram.com/anagomez/'));
        $this->assertSame('ana.gomez_ok', Carcajada::usuarioDeInstagram(' ana.gomez_ok '));
        $this->assertNull(Carcajada::usuarioDeInstagram(''));
        $this->assertNull(Carcajada::usuarioDeInstagram('@@@'));
    }

    // ------------------------------------------------------------------ alta

    private function laPaginaEsSuya()
    {
        $this->db->onSelect('FROM pages WHERE id = ? AND user_id = ?', [[1]]);
    }

    public function testElAltaExigeNombre()
    {
        $r = Carcajada::guardarComediante($this->db, 7, ['page_id' => 5, 'nombre' => '   ']);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO carcajada_comediantes'));
    }

    /** El QR manda a esa página: no puede ser la de otro. */
    public function testNoSePuedeAnotarLaPaginaDeOtro()
    {
        $this->db->onSelect('FROM pages WHERE id = ? AND user_id = ?', []);

        $r = Carcajada::guardarComediante($this->db, 7, ['page_id' => 5, 'nombre' => 'Ana']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no es tuya', $r['error']);
    }

    public function testNoSeAceptaUnaCantidadDeGenteIrreal()
    {
        $r = Carcajada::guardarComediante($this->db, 7, [
            'page_id' => 5, 'nombre' => 'Ana', 'personas_comprometidas' => 5000,
        ]);

        $this->assertFalse($r['ok']);
    }

    public function testElAltaGuardaLoQueHaceFalta()
    {
        $this->laPaginaEsSuya();
        $this->db->onWrite('INSERT INTO carcajada_comediantes', 1);

        $r = Carcajada::guardarComediante($this->db, 7, [
            'page_id' => 5, 'nombre' => 'Ana Gómez', 'instagram' => '@anagomez',
            'foto_url' => 'https://rezon.ar/uploads/ana.jpg', 'personas_comprometidas' => 12,
            'estudio_con' => '  Escuela de Humor Sur ', 'egreso_anio' => '2019', 'material' => 'Stand up sobre mi familia.',
        ]);
        $params = $this->db->paramsFor('INSERT INTO carcajada_comediantes');

        $this->assertTrue($r['ok']);
        $this->assertSame([
            7, 5, 'Ana Gómez', 'https://rezon.ar/uploads/ana.jpg', 'anagomez', 12,
            'Escuela de Humor Sur', 2019, 'Stand up sobre mi familia.',
        ], $params);
    }

    /** Con quién estudió, el egreso y el material son opcionales: vacíos quedan en NULL, no en "". */
    public function testLaFormacionEsOpcional()
    {
        $this->laPaginaEsSuya();
        $this->db->onWrite('INSERT INTO carcajada_comediantes', 1);

        Carcajada::guardarComediante($this->db, 7, [
            'page_id' => 5, 'nombre' => 'Ana', 'estudio_con' => '', 'egreso_anio' => '', 'material' => '   ',
        ]);

        $this->assertSame([null, null, null], array_slice($this->db->paramsFor('INSERT INTO carcajada_comediantes'), 6));
    }

    /** Un año de egreso fuera de rango es un error de tipeo, no un dato. */
    public function testElEgresoTieneQueSerUnAnioPosible()
    {
        $this->laPaginaEsSuya();

        foreach (['1900', (string) ((int) date('Y') + 1)] as $anio) {
            $r = Carcajada::guardarComediante($this->db, 7, ['page_id' => 5, 'nombre' => 'Ana', 'egreso_anio' => $anio]);

            $this->assertFalse($r['ok'], "aceptó $anio");
            $this->assertStringContainsString('año de egreso', $r['error']);
        }

        $this->assertSame(0, $this->db->countCalls('INSERT INTO carcajada_comediantes'));
    }

    public function testElMaterialTieneUnTope()
    {
        $this->laPaginaEsSuya();

        $r = Carcajada::guardarComediante($this->db, 7, [
            'page_id' => 5, 'nombre' => 'Ana', 'material' => str_repeat('a', Carcajada::LARGO_MATERIAL + 1),
        ]);

        $this->assertFalse($r['ok']);
    }

    // ------------------------------------------------------------ invitados

    /** Alguien sin cuenta: sólo nombre, foto e Instagram, y sin usuario ni página. */
    public function testUnInvitadoSeDaDeAltaSinCuenta()
    {
        $this->db->onInsert('INSERT INTO carcajada_comediantes', 44);

        $r = Carcajada::guardarInvitado($this->db, null, [
            'nombre' => ' Pepe Invitado ', 'foto_url' => 'https://rezon.ar/api/uploads/pepe.jpg', 'instagram' => 'https://instagram.com/pepe.ok',
        ]);

        $this->assertSame(['ok' => true, 'error' => null, 'id' => 44], $r);
        $this->assertSame(['Pepe Invitado', 'https://rezon.ar/api/uploads/pepe.jpg', 'pepe.ok'], $this->db->paramsFor('INSERT INTO carcajada_comediantes'));
        $this->assertSame(1, $this->db->countCalls('VALUES (NULL, NULL, ?, ?, ?)'));
    }

    public function testUnInvitadoNecesitaNombre()
    {
        $r = Carcajada::guardarInvitado($this->db, null, ['nombre' => '  ']);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('INSERT'));
    }

    /** La foto se muestra en la página pública: un javascript: o una ruta rara no pasan. */
    public function testLaFotoDelInvitadoTieneQueSerUnaDireccionWeb()
    {
        foreach (['javascript:alert(1)', 'data:image/png;base64,xx', '/uploads/x.jpg'] as $foto) {
            $r = Carcajada::guardarInvitado($this->db, null, ['nombre' => 'Pepe', 'foto_url' => $foto]);

            $this->assertFalse($r['ok'], "aceptó $foto");
        }

        $this->assertSame(0, $this->db->countCalls('INSERT'));
    }

    public function testSeCorrigeUnInvitado()
    {
        $this->db->onSelect('SELECT user_id FROM carcajada_comediantes', [['user_id' => null]]);
        $this->db->onWrite('UPDATE carcajada_comediantes', 1);

        $r = Carcajada::guardarInvitado($this->db, 44, ['nombre' => 'Pepe Corregido', 'foto_url' => '', 'instagram' => '']);

        $this->assertTrue($r['ok']);
        $this->assertSame(['Pepe Corregido', null, null, 44], $this->db->paramsFor('UPDATE carcajada_comediantes'));
    }

    /** Los datos de quien tiene cuenta los maneja esa persona: quien produce no se los pisa. */
    public function testNoSePisanLosDatosDeQuienTieneCuenta()
    {
        $this->db->onSelect('SELECT user_id FROM carcajada_comediantes', [['user_id' => 7]]);

        $r = Carcajada::guardarInvitado($this->db, 9, ['nombre' => 'Otro nombre']);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('UPDATE'));
    }

    public function testNoSeCorrigeUnComedianteQueNoExiste()
    {
        $this->db->onSelect('SELECT user_id FROM carcajada_comediantes', []);

        $this->assertFalse(Carcajada::guardarInvitado($this->db, 99, ['nombre' => 'Pepe'])['ok']);
    }

    /** Anotarse dos veces con la misma cuenta actualiza, no duplica. */
    public function testAnotarseDeNuevoActualiza()
    {
        $this->laPaginaEsSuya();
        $this->db->onWrite('INSERT INTO carcajada_comediantes', 1);

        Carcajada::guardarComediante($this->db, 7, ['page_id' => 5, 'nombre' => 'Ana']);

        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            $this->db->callsFor('INSERT INTO carcajada_comediantes')[0]['sql']
        );
    }

    // ------------------------------------------------------------- evaluar

    public function testElPuntajeVaDeUnoACinco()
    {
        $this->assertFalse(Carcajada::evaluar($this->db, 1, 2, ['puntaje' => 6])['ok']);
        $this->assertFalse(Carcajada::evaluar($this->db, 1, 2, ['puntaje' => 0])['ok']);
        $this->assertSame(0, $this->db->countCalls('UPDATE carcajada_lineup'));
    }

    public function testEvaluarGuardaComoLeFue()
    {
        $this->db->onWrite('UPDATE carcajada_lineup', 1);

        $r = Carcajada::evaluar($this->db, 3, 9, [
            'puntaje' => 4, 'personas_traidas' => 7, 'comentario' => 'Muy bien el cierre',
        ]);
        $llamada = $this->db->callsFor('UPDATE carcajada_lineup')[0];

        $this->assertTrue($r['ok']);
        $this->assertSame([4, 7, 'Muy bien el cierre', 3, 9], $llamada['params']);
        $this->assertStringContainsString('evaluado_en = NOW()', $llamada['sql']);
    }

    /**
     * Borrar la evaluación tiene que dejarla sin fecha: si no, la ficha vería
     * "evaluado" en una noche de la que no se sabe nada.
     */
    public function testBorrarLaEvaluacionLaDejaSinFecha()
    {
        $this->db->onWrite('UPDATE carcajada_lineup', 1);

        Carcajada::evaluar($this->db, 3, 9, ['puntaje' => '', 'personas_traidas' => '', 'comentario' => '']);

        $this->assertStringContainsString(
            'evaluado_en = NULL',
            $this->db->callsFor('UPDATE carcajada_lineup')[0]['sql']
        );
    }

    /** Traer a nadie es un dato; no haber evaluado todavía, no. */
    public function testTraerCeroPersonasSiEsUnDato()
    {
        $this->db->onWrite('UPDATE carcajada_lineup', 1);

        Carcajada::evaluar($this->db, 3, 9, ['personas_traidas' => 0]);
        $llamada = $this->db->callsFor('UPDATE carcajada_lineup')[0];

        $this->assertSame(0, $llamada['params'][1]);
        $this->assertStringContainsString('evaluado_en = NOW()', $llamada['sql']);
    }

    // --------------------------------------------------------------- lineup

    public function testSumarAlLineupLoPoneUltimo()
    {
        $this->db->onSelect('FROM carcajada_comediantes WHERE id', [[1]]);
        $this->db->onSelect('MAX(orden)', [[3]]);
        $this->db->onWrite('INSERT INTO carcajada_lineup', 1);

        $r = Carcajada::sumarAlLineup($this->db, 5, 9);

        $this->assertTrue($r['ok']);
        $this->assertSame([5, 9, 3], $this->db->paramsFor('INSERT INTO carcajada_lineup'));
    }

    public function testNoSePuedeSumarAAlguienQueNoExiste()
    {
        $this->db->onSelect('FROM carcajada_comediantes WHERE id', []);

        $r = Carcajada::sumarAlLineup($this->db, 5, 99);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO carcajada_lineup'));
    }

    public function testOrdenarNumeraDesdeUnoEnElOrdenRecibido()
    {
        $this->db->onWrite('UPDATE carcajada_lineup SET orden', 1);
        $this->db->onWrite('UPDATE carcajada_lineup SET orden', 1);

        Carcajada::ordenar($this->db, 5, [9, 4]);
        $llamadas = $this->db->callsFor('UPDATE carcajada_lineup SET orden');

        $this->assertSame([1, 5, 9], $llamadas[0]['params']);
        $this->assertSame([2, 5, 4], $llamadas[1]['params']);
    }

    // --------------------------------------------------------------- público

    /**
     * El QR se imprime una vez: si no hay show hoy tiene que mostrar el
     * próximo, no una pantalla vacía.
     */
    public function testElShowEnCursoEsElDeHoyOElQueSigue()
    {
        $this->db->onSelect('WHERE s.fecha >= ?', [[7]]);
        $this->db->onSelect('FROM carcajada_shows s', [[
            'id' => 7, 'fecha' => \Fechas::hoy(), 'hora' => '21:00:00', 'lugar' => 'Humboldt 1574', 'descripcion' => null,
            'notas' => null, 'ciclo_id' => 1, 'ciclo' => 'JaJaJaJueves', 'ciclo_slug' => 'jajajajueves',
        ]]);
        $this->db->onSelect('FROM carcajada_lineup l', [[
            'id' => 1, 'comediante_id' => 9, 'user_id' => 30, 'orden' => 1, 'puntaje' => 5, 'personas_traidas' => 8,
            'comentario' => 'secreto', 'evaluado_en' => '2026-09-01 00:00:00', 'nombre' => 'Ana Gómez',
            'foto_url' => 'https://rezon.ar/uploads/ana.jpg', 'instagram' => 'anagomez',
            'personas_comprometidas' => 10, 'url_slug' => 'anagomez', 'pagina' => 'Ana Gómez',
        ]]);

        $r = Carcajada::showEnCurso($this->db);

        $this->assertSame('JaJaJaJueves', $r['show']['ciclo']);
        $this->assertTrue($r['show']['es_hoy']);
        $this->assertSame([[
            'nombre' => 'Ana Gómez',
            'foto_url' => 'https://rezon.ar/uploads/ana.jpg',
            'instagram' => 'anagomez',
            'url_slug' => 'anagomez',
        ]], $r['comediantes']);
    }

    /** Es un cartel en la pared: lo de la ficha no puede salir por ahí. */
    public function testLaPantallaDelPublicoNoMuestraPuntajesNiComentarios()
    {
        $this->db->onSelect('WHERE s.fecha >= ?', [[7]]);
        $this->db->onSelect('FROM carcajada_shows s', [[
            'id' => 7, 'fecha' => '2026-12-31', 'hora' => null, 'lugar' => null, 'descripcion' => null, 'notas' => null,
            'ciclo_id' => 1, 'ciclo' => 'JaJaJaJueves', 'ciclo_slug' => 'jajajajueves',
        ]]);
        $this->db->onSelect('FROM carcajada_lineup l', [[
            'id' => 1, 'comediante_id' => 9, 'user_id' => 30, 'orden' => 1, 'puntaje' => 2, 'personas_traidas' => 1,
            'comentario' => 'flojo', 'evaluado_en' => null, 'nombre' => 'Ana', 'foto_url' => null,
            'instagram' => null, 'personas_comprometidas' => 10, 'url_slug' => 'ana', 'pagina' => 'Ana',
        ]]);

        $json = json_encode(Carcajada::showEnCurso($this->db));

        $this->assertStringNotContainsString('flojo', $json);
        $this->assertStringNotContainsString('puntaje', $json);
        $this->assertStringNotContainsString('comprometidas', $json);
    }

    public function testSinShowsNoHayNadaQueMostrar()
    {
        $this->db->onSelect('WHERE s.fecha >= ?', []);

        $this->assertNull(Carcajada::showEnCurso($this->db));
    }
}
