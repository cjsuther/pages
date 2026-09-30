<?php

namespace Tests\Integration;

use CarcajadaHandler;
use Fechas;
use Tests\Support\IntegracionTestCase;

/**
 * Carcajada contra la base real: comediantes sin cuenta, la descripción del
 * show y la página pública con las otras fechas.
 *
 * Los invitados dependen de que user_id acepte NULL, que es un cambio de
 * esquema (migration_carcajada_invitados.sql): con FakePdo no hay forma de
 * saber si la base lo permite.
 */
class CarcajadaTest extends IntegracionTestCase
{
    private $productorId;
    private $cicloId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productorId = $this->usuario('productora@test.local', 'Productora');
        $this->insertar('carcajada_productores', ['user_id' => $this->productorId]);
        $this->cicloId = $this->insertar('carcajada_ciclos', ['nombre' => 'JaJaJaJueves', 'slug' => 'jajajajueves']);
    }

    private function productor()
    {
        return $this->user($this->productorId, 'productora@test.local');
    }

    private function show($dias = 0, array $datos = [])
    {
        return $this->insertar('carcajada_shows', array_merge([
            'ciclo_id' => $this->cicloId,
            'fecha' => date('Y-m-d', strtotime(Fechas::hoy() . " $dias days")),
            'hora' => '21:00:00',
            'lugar' => 'Humboldt 1574',
        ], $datos));
    }

    private function accion($showId, array $cuerpo)
    {
        return CarcajadaHandler::show($this->db, $this->post($cuerpo, $this->productor(), ['id' => $showId]));
    }

    // ------------------------------------------------------------ invitados

    public function testQuienProduceSumaAAlguienSinCuentaAlShow()
    {
        $showId = $this->show(3);

        $res = $this->accion($showId, [
            'accion' => 'invitar', 'nombre' => 'Pepe Invitado',
            'foto_url' => 'https://rezon.ar/api/uploads/pepe.jpg', 'instagram' => '@pepe.ok',
        ]);

        $this->assertStatus(200, $res);

        $fila = $this->fila('SELECT * FROM carcajada_comediantes WHERE nombre = ?', ['Pepe Invitado']);
        $this->assertNull($fila['user_id']);
        $this->assertNull($fila['page_id']);
        $this->assertSame('pepe.ok', $fila['instagram']);

        $this->assertSame('1', (string) $this->valor(
            'SELECT COUNT(*) FROM carcajada_lineup WHERE show_id = ? AND comediante_id = ?', [$showId, $fila['id']]
        ));
        $this->assertSame('Pepe Invitado', $res->body['show']['lineup'][0]['nombre']);
        $this->assertFalse($res->body['show']['lineup'][0]['con_cuenta']);
    }

    /** Varios invitados conviven: la clave única de user_id no choca entre NULLs. */
    public function testPuedeHaberVariosInvitados()
    {
        $showId = $this->show(3);

        $this->assertStatus(200, $this->accion($showId, ['accion' => 'invitar', 'nombre' => 'Uno']));
        $this->assertStatus(200, $this->accion($showId, ['accion' => 'invitar', 'nombre' => 'Dos']));

        $this->assertSame('2', (string) $this->valor('SELECT COUNT(*) FROM carcajada_comediantes WHERE user_id IS NULL'));
        $this->assertSame('2', (string) $this->valor('SELECT COUNT(*) FROM carcajada_lineup WHERE show_id = ?', [$showId]));
    }

    public function testUnInvitadoSinNombreNoSeCreaNiSeSuma()
    {
        $showId = $this->show(3);

        $this->assertStatus(400, $this->accion($showId, ['accion' => 'invitar', 'nombre' => '']));

        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM carcajada_comediantes'));
        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM carcajada_lineup'));
    }

    public function testElInvitadoApareceEnLasFichasYSeCorrige()
    {
        $res = CarcajadaHandler::comediantes($this->db, $this->post(['nombre' => 'Pepe'], $this->productor()));
        $this->assertStatus(200, $res);
        $id = $res->body['id'];

        $res = CarcajadaHandler::comediantes($this->db, $this->put(
            ['nombre' => 'Pepe Argento', 'instagram' => 'instagram.com/pepeargento'], $this->productor(), ['id' => $id]
        ));

        $this->assertStatus(200, $res);
        $ficha = $res->body['comediantes'][0];
        $this->assertSame('Pepe Argento', $ficha['nombre']);
        $this->assertSame('pepeargento', $ficha['instagram']);
        $this->assertFalse($ficha['con_cuenta']);
    }

    /** Quien no produce no puede crear invitados: es la puerta para meter a cualquiera en un show. */
    public function testSinSerProductorNoSeCreanInvitados()
    {
        $otra = $this->usuario('otra@test.local', 'Otra');

        $res = CarcajadaHandler::comediantes($this->db, $this->post(['nombre' => 'Colado'], $this->user($otra, 'otra@test.local')));

        $this->assertStatus(403, $res);
        $this->assertSame('0', (string) $this->valor('SELECT COUNT(*) FROM carcajada_comediantes'));
    }

    // ------------------------------------------------ formación del comediante

    public function testElAltaGuardaConQuienEstudioElEgresoYElMaterial()
    {
        $ana = $this->usuario('ana@test.local', 'Ana');
        $pagina = $this->pagina($ana, 'anagomez', 'Ana Gómez');

        $res = CarcajadaHandler::comediante($this->db, $this->post([
            'page_id' => $pagina, 'nombre' => 'Ana Gómez', 'personas_comprometidas' => 8,
            'estudio_con' => 'Escuela de Humor Sur', 'egreso_anio' => 2019,
            'material' => "Stand up sobre mi familia.\nNada de política.",
        ], $this->user($ana, 'ana@test.local')));

        $this->assertStatus(200, $res);
        $fila = $this->fila('SELECT estudio_con, egreso_anio, material FROM carcajada_comediantes WHERE user_id = ?', [$ana]);
        $this->assertSame('Escuela de Humor Sur', $fila['estudio_con']);
        $this->assertSame(2019, (int) $fila['egreso_anio']);
        $this->assertSame("Stand up sobre mi familia.\nNada de política.", $fila['material']);

        $this->assertSame('Escuela de Humor Sur', $res->body['comediante']['estudio_con']);
        $this->assertSame(2019, $res->body['comediante']['egreso_anio']);
    }

    /** Quien produce lo ve en la ficha, que es donde decide a quién llamar. */
    public function testLaFormacionLlegaALaFicha()
    {
        $ana = $this->usuario('ana@test.local', 'Ana');
        $pagina = $this->pagina($ana, 'anagomez', 'Ana Gómez');
        CarcajadaHandler::comediante($this->db, $this->post([
            'page_id' => $pagina, 'nombre' => 'Ana', 'estudio_con' => 'Taller de Pepe', 'egreso_anio' => 2021, 'material' => 'Absurdo',
        ], $this->user($ana, 'ana@test.local')));

        $ficha = CarcajadaHandler::comediantes($this->db, $this->get([], $this->productor()))->body['comediantes'][0];

        $this->assertSame('Taller de Pepe', $ficha['estudio_con']);
        $this->assertSame(2021, $ficha['egreso_anio']);
        $this->assertSame('Absurdo', $ficha['material']);
        $this->assertTrue($ficha['con_cuenta']);
    }

    // ------------------------------------------------------------ descripción

    public function testLaDescripcionSeGuardaLimpia()
    {
        $showId = $this->show(3);

        $res = $this->accion($showId, [
            'accion' => 'descripcion',
            'descripcion' => '<p onclick="robar()">Noche de <b>stand up</b></p><script>alert(1)</script><a href="javascript:x()">malo</a>',
        ]);

        $this->assertStatus(200, $res);
        $this->assertSame(
            '<p>Noche de <strong>stand up</strong></p>malo',
            $this->valor('SELECT descripcion FROM carcajada_shows WHERE id = ?', [$showId])
        );
    }

    /** Borrar todo el texto en el editor deja párrafos vacíos: eso es no tener descripción. */
    public function testUnaDescripcionVaciaQuedaEnNull()
    {
        $showId = $this->show(3, ['descripcion' => '<p>Vieja</p>']);

        $this->accion($showId, ['accion' => 'descripcion', 'descripcion' => '<p><br></p>']);

        $this->assertNull($this->valor('SELECT descripcion FROM carcajada_shows WHERE id = ?', [$showId]));
    }

    /** Cambiar la descripción no toca la fecha, el lugar ni el line-up. */
    public function testLaDescripcionNoPisaElRestoDelShow()
    {
        $showId = $this->show(3, ['notas' => 'interno']);
        $this->accion($showId, ['accion' => 'invitar', 'nombre' => 'Pepe']);

        $this->accion($showId, ['accion' => 'descripcion', 'descripcion' => '<p>Hola</p>']);

        $fila = $this->fila('SELECT lugar, notas, hora FROM carcajada_shows WHERE id = ?', [$showId]);
        $this->assertSame(['lugar' => 'Humboldt 1574', 'notas' => 'interno', 'hora' => '21:00:00'], $fila);
        $this->assertSame('1', (string) $this->valor('SELECT COUNT(*) FROM carcajada_lineup WHERE show_id = ?', [$showId]));
    }

    // -------------------------------------------------------- página pública

    /** Descripción, después los comediantes, después las otras fechas. */
    public function testLaPaginaPublicaTraeDescripcionComediantesYOtrasFechas()
    {
        $hoy = $this->show(0, ['descripcion' => '<p>Esta noche</p>']);
        $this->accion($hoy, ['accion' => 'invitar', 'nombre' => 'Pepe', 'instagram' => 'pepe']);
        $proxima = $this->show(7);
        $this->show(-7); // una que ya pasó

        $res = CarcajadaHandler::hoy($this->db, $this->get());

        $this->assertStatus(200, $res);
        $this->assertSame($hoy, $res->body['show']['id']);
        $this->assertSame('<p>Esta noche</p>', $res->body['show']['descripcion']);
        $this->assertTrue($res->body['show']['es_hoy']);
        $this->assertSame('Pepe', $res->body['comediantes'][0]['nombre']);
        $this->assertSame([$proxima], array_column($res->body['otras'], 'id'));
    }

    public function testUnaFechaSeAbrePorSuId()
    {
        $this->show(0);
        $proxima = $this->show(7, ['descripcion' => '<p>La que viene</p>']);

        $res = CarcajadaHandler::hoy($this->db, $this->get(['id' => $proxima]));

        $this->assertStatus(200, $res);
        $this->assertSame('<p>La que viene</p>', $res->body['show']['descripcion']);
        $this->assertFalse($res->body['show']['es_hoy']);
        $this->assertNotContains($proxima, array_column($res->body['otras'], 'id'));
    }

    public function testUnaFechaQueNoExisteDa404()
    {
        $this->assertStatus(404, CarcajadaHandler::hoy($this->db, $this->get(['id' => 999])));
    }

    /** Las otras fechas se cortan en un número razonable y van en orden. */
    public function testLasOtrasFechasVanEnOrdenYConTope()
    {
        $actual = $this->show(0);
        for ($i = 10; $i >= 1; $i--) {
            $this->show($i);
        }

        $otras = CarcajadaHandler::hoy($this->db, $this->get())->body['otras'];

        $this->assertCount(\Carcajada::OTRAS_FECHAS, $otras);
        $fechas = array_column($otras, 'fecha');
        $ordenadas = $fechas;
        sort($ordenadas);
        $this->assertSame($ordenadas, $fechas);
        $this->assertNotContains($actual, array_column($otras, 'id'));
    }

    /**
     * Es un cartel en la pared de un bar: la formación, el material, el
     * puntaje y lo que prometió traer son para quien produce.
     */
    public function testLaPaginaPublicaNoMuestraNadaDeLaFicha()
    {
        $ana = $this->usuario('ana@test.local', 'Ana');
        $pagina = $this->pagina($ana, 'anagomez', 'Ana Gómez');
        CarcajadaHandler::comediante($this->db, $this->post([
            'page_id' => $pagina, 'nombre' => 'Ana', 'personas_comprometidas' => 9,
            'estudio_con' => 'Taller secreto', 'egreso_anio' => 2020, 'material' => 'material privado',
        ], $this->user($ana, 'ana@test.local')));
        $comedianteId = (int) $this->valor('SELECT id FROM carcajada_comediantes WHERE user_id = ?', [$ana]);

        $showId = $this->show(0, ['notas' => 'nota interna']);
        $this->accion($showId, ['accion' => 'sumar', 'comediante_id' => $comedianteId]);
        $this->accion($showId, ['accion' => 'evaluar', 'comediante_id' => $comedianteId, 'puntaje' => 2, 'comentario' => 'flojo']);

        $json = json_encode(CarcajadaHandler::hoy($this->db, $this->get())->body, JSON_UNESCAPED_UNICODE);

        foreach (['Taller secreto', '2020', 'material privado', 'nota interna', 'flojo', 'puntaje', 'comprometidas'] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
        $this->assertStringContainsString('anagomez', $json);
    }
}
