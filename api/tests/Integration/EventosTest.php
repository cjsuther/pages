<?php

namespace Tests\Integration;

use ClavesApi;
use CollaborationsHandler;
use Entradas;
use EntradasHandler;
use Geocodificador;
use GroupsHandler;
use LinksHandler;
use McpHandler;
use Notificador;
use PublicHandler;
use Request;
use Tests\Support\IntegracionTestCase;

/**
 * Crear, editar y borrar eventos contra una base de verdad.
 *
 * Los tests unitarios de estos handlers ya existen, pero con FakePdo: miran
 * que se mande la consulta correcta, no que la consulta ande. Acá cada alta,
 * cada edición y cada borrado pasa por MariaDB con el esquema que arman las
 * migraciones, y lo que se afirma son las filas que quedaron.
 *
 * Nada sale a la red: la geocodificación se resuelve desde geocode_cache, los
 * avisos quedan en notifications y push_deliveries sin enviarse, y Mercado
 * Pago no participa porque configurar la venta no le habla.
 */
class EventosTest extends IntegracionTestCase
{
    private $duenaId;
    private $paginaId;
    private $grupoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->duenaId = $this->usuario();
        $this->paginaId = $this->pagina($this->duenaId);
        $this->grupoId = $this->grupo($this->paginaId);
    }

    /** Lo que manda el editor al crear un evento, con todos los campos. */
    private function datosDeEvento(array $cambios = [])
    {
        return array_merge([
            'group_id' => $this->grupoId,
            'url' => 'https://entradas.example/show',
            'url_text' => 'Comprar',
            'text' => 'Noche de tango',
            'image_url' => 'https://rezonar.test/api/uploads/afiche.jpg',
            'description' => 'Con orquesta en vivo',
            'position' => 3,
            'event_date' => date('Y-m-d', strtotime('+20 days')),
            'event_time' => '21:30:00',
            'event_address' => 'Av. Corrientes 1234, CABA',
            'event_latitude' => '-34.60370000',
            'event_longitude' => '-58.38160000',
            'event_maps_url' => 'https://maps.google.com/?q=-34.6037,-58.3816',
            'precio_desde' => '12000',
        ], $cambios);
    }

    private function crear(array $datos, $userId = null)
    {
        return LinksHandler::index($this->db, $this->post($datos, $this->user($userId ?: $this->duenaId)));
    }

    private function editar($linkId, array $datos, $userId = null)
    {
        return LinksHandler::detail($this->db, $this->put($datos, $this->user($userId ?: $this->duenaId), ['id' => $linkId]));
    }

    private function borrar($linkId, $userId = null)
    {
        return LinksHandler::detail($this->db, $this->delete(['id' => $linkId], $this->user($userId ?: $this->duenaId)));
    }

    private function configurarVenta($linkId, array $datos, $userId = null)
    {
        return EntradasHandler::config($this->db, $this->post($datos, $this->user($userId ?: $this->duenaId), ['link_id' => $linkId]));
    }

    /** Un evento que ya vendió: venta gratis activada y una reserva hecha. */
    private function eventoConVentas($titulo = 'Show con público')
    {
        $linkId = $this->evento($this->grupoId, [
            'text' => $titulo,
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4',
        ]);
        $this->assertStatus(200, $this->configurarVenta($linkId, ['capacidad' => 50, 'precio' => 0]));

        $orden = Entradas::crearOrden($this->db, $linkId, [
            'nombre' => 'Ana Compradora', 'email' => 'ana@test.local', 'telefono' => '1122334455', 'cantidad' => 2,
        ]);
        $this->assertTrue($orden['ok'], json_encode($orden));

        return $linkId;
    }

    private function cuenta($tabla, $where = '1', array $params = [])
    {
        return (int) $this->valor("SELECT COUNT(*) FROM `$tabla` WHERE $where", $params);
    }

    // ============================================================== crear

    public function testCrearUnEventoGuardaTodosSusCampos()
    {
        $res = $this->crear($this->datosDeEvento());

        $this->assertStatus(201, $res);
        $fila = $this->fila('SELECT * FROM links WHERE id = ?', [$res->body['link']['id']]);

        $this->assertSame('Noche de tango', $fila['text']);
        $this->assertSame('Comprar', $fila['url_text']);
        $this->assertSame('Con orquesta en vivo', $fila['description']);
        $this->assertSame('https://rezonar.test/api/uploads/afiche.jpg', $fila['image_url']);
        $this->assertSame(date('Y-m-d', strtotime('+20 days')), $fila['event_date']);
        $this->assertSame('21:30:00', $fila['event_time']);
        $this->assertSame('Av. Corrientes 1234, CABA', $fila['event_address']);
        $this->assertEquals(-34.6037, (float) $fila['event_latitude']);
        $this->assertEquals(-58.3816, (float) $fila['event_longitude']);
        $this->assertSame('https://maps.google.com/?q=-34.6037,-58.3816', $fila['event_maps_url']);
        $this->assertEquals(12000, (float) $fila['precio_desde']);
        $this->assertEquals(3, $fila['position']);
        $this->assertEquals($this->grupoId, $fila['group_id']);
    }

    /** La respuesta devuelve la fila tal como quedó, que es lo que dibuja el editor. */
    public function testLaRespuestaEsLaFilaGuardada()
    {
        $res = $this->crear($this->datosDeEvento());

        $this->assertEquals($this->fila('SELECT * FROM links WHERE id = ?', [$res->body['link']['id']]), $res->body['link']);
    }

    /** Vacío no es cero: sin precio de referencia queda NULL, y un texto vacío también. */
    public function testLosOpcionalesVaciosQuedanEnNull()
    {
        $res = $this->crear($this->datosDeEvento(['url_text' => '', 'precio_desde' => '', 'embed_url' => '']));

        $fila = $this->fila('SELECT url_text, precio_desde, embed_url FROM links WHERE id = ?', [$res->body['link']['id']]);
        $this->assertSame(['url_text' => null, 'precio_desde' => null, 'embed_url' => null], $fila);
    }

    public function testUnEventoGratisTienePrecioCeroYNoNull()
    {
        $res = $this->crear($this->datosDeEvento(['precio_desde' => 0]));

        $this->assertEquals(0, (float) $this->valor('SELECT precio_desde FROM links WHERE id = ?', [$res->body['link']['id']]));
        $this->assertNotNull($this->valor('SELECT precio_desde FROM links WHERE id = ?', [$res->body['link']['id']]));
    }

    public function testUnEventoSinCoordenadasNoSeCrea()
    {
        $res = $this->crear($this->datosDeEvento(['event_latitude' => '', 'event_longitude' => '']));

        $this->assertStatus(400, $res);
        $this->assertSame(LinksHandler::ERROR_SIN_COORDENADAS, $res->body['error']);
        $this->assertSame(0, $this->cuenta('links'));
    }

    public function testSinTituloNoSeCrea()
    {
        $datos = $this->datosDeEvento();
        unset($datos['text']);

        $this->assertStatus(400, $this->crear($datos));
        $this->assertSame(0, $this->cuenta('links'));
    }

    public function testSinSesionNoSeCrea()
    {
        $res = LinksHandler::index($this->db, $this->post($this->datosDeEvento()));

        $this->assertStatus(401, $res);
        $this->assertSame(0, $this->cuenta('links'));
    }

    public function testOtraPersonaNoPuedeCrearEnUnaPaginaAjena()
    {
        $otra = $this->usuario('otra@test.local', 'Otra');

        $this->assertStatus(404, $this->crear($this->datosDeEvento(), $otra));
        $this->assertSame(0, $this->cuenta('links'));
    }

    public function testUnGrupoQueNoExisteEs404()
    {
        $this->assertStatus(404, $this->crear($this->datosDeEvento(['group_id' => 999])));
    }

    public function testUnAdministradorAceptadoPuedeCrear()
    {
        $admin = $this->usuario('admin@test.local', 'Admin');
        $this->insertar('page_admins', ['page_id' => $this->paginaId, 'user_id' => $admin, 'status' => 'accepted', 'invited_by' => $this->duenaId]);

        $this->assertStatus(201, $this->crear($this->datosDeEvento(), $admin));
        $this->assertSame(1, $this->cuenta('links'));
    }

    /** Invitado todavía no es administrador: hasta que acepta, no toca nada. */
    public function testUnaInvitacionPendienteNoAlcanza()
    {
        $admin = $this->usuario('admin@test.local', 'Admin');
        $this->insertar('page_admins', ['page_id' => $this->paginaId, 'user_id' => $admin, 'status' => 'pending', 'invited_by' => $this->duenaId]);

        $this->assertStatus(404, $this->crear($this->datosDeEvento(), $admin));
        $this->assertSame(0, $this->cuenta('links'));
    }

    /** Quien administra la plataforma puede cargar en cualquier página. */
    public function testLaPlataformaPuedeCrearEnCualquierPagina()
    {
        $plataforma = $this->usuario('plataforma@test', 'Plataforma');

        $this->assertStatus(201, $this->crear($this->datosDeEvento(), $plataforma));
    }

    public function testUnLinkEnUnGrupoDeLinksNoPideCoordenadasNiAvisa()
    {
        $links = $this->grupo($this->paginaId, 'links', 'Redes');
        $seguidor = $this->usuario('fan@test.local', 'Fan');
        $this->insertar('page_followers', ['user_id' => $seguidor, 'page_id' => $this->paginaId, 'notify_all_events' => 1]);

        $res = $this->crear(['group_id' => $links, 'url' => 'https://open.spotify.com/x', 'text' => 'Spotify']);

        $this->assertStatus(201, $res);
        $this->assertNull($res->body['link']['event_latitude']);
        $this->assertSame(0, $this->cuenta('notifications'));
    }

    // ============================================================ avisos

    /**
     * Publicar una fecha avisa a quien sigue la página, y el envío push queda
     * encolado sin salir: lo manda el cron, no el alta.
     */
    public function testAlCrearUnEventoSeAvisaALosSeguidoresSinEnviarNada()
    {
        $fan = $this->usuario('fan@test.local', 'Fan');
        $this->insertar('page_followers', ['user_id' => $fan, 'page_id' => $this->paginaId, 'notify_all_events' => 1]);
        $this->insertar('push_subscriptions', [
            'user_id' => $fan, 'endpoint' => 'https://push.example/fan', 'p256dh_key' => 'p', 'auth_key' => 'a', 'platform' => 'android',
        ]);

        $res = $this->crear($this->datosDeEvento());
        $linkId = $res->body['link']['id'];

        $aviso = $this->fila('SELECT * FROM notifications WHERE user_id = ?', [$fan]);
        $this->assertSame('new_event', $aviso['type']);
        $this->assertEquals($linkId, $aviso['link_id']);
        $this->assertEquals($this->paginaId, $aviso['page_id']);
        $this->assertStringContainsString('Noche de tango', $aviso['title']);
        $this->assertSame(Notificador::claveEvento($linkId, $fan), $aviso['dedupe_key']);

        // Encolado para el cron, pendiente: nada se envió.
        $this->assertSame(1, Notificador::encolarPendientes($this->db));
        $this->assertSame('pendiente', $this->valor('SELECT estado FROM push_deliveries'));
    }

    /** El cron vuelve a pasar por los eventos: no puede duplicar el aviso. */
    public function testElAvisoSaleUnaSolaVez()
    {
        $fan = $this->usuario('fan@test.local', 'Fan');
        $this->insertar('page_followers', ['user_id' => $fan, 'page_id' => $this->paginaId, 'notify_all_events' => 1]);

        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $this->assertSame(0, Notificador::avisarEventoNuevo($this->db, $linkId));
        $this->assertSame(1, $this->cuenta('notifications'));
    }

    /** La dueña que sigue su propia página no se avisa a sí misma. */
    public function testLaDuenaNoSeAvisaASiMisma()
    {
        $this->insertar('page_followers', ['user_id' => $this->duenaId, 'page_id' => $this->paginaId, 'notify_all_events' => 1]);

        $this->crear($this->datosDeEvento());

        $this->assertSame(0, $this->cuenta('notifications'));
    }

    /** Quien pidió sólo fechas cercanas no se entera de una a 700 km. */
    public function testQuienSigueSoloLoCercanoNoSeEnteraDeUnEventoLejano()
    {
        $cerca = $this->usuario('cerca@test.local', 'Cerca');
        $lejos = $this->usuario('lejos@test.local', 'Lejos');
        $this->db->prepare('UPDATE users SET location_latitude = ?, location_longitude = ? WHERE id = ?')->execute([-34.61, -58.39, $cerca]);
        $this->db->prepare('UPDATE users SET location_latitude = ?, location_longitude = ? WHERE id = ?')->execute([-31.42, -64.18, $lejos]);
        foreach ([$cerca, $lejos] as $u) {
            $this->insertar('page_followers', ['user_id' => $u, 'page_id' => $this->paginaId, 'notify_all_events' => 0, 'max_distance_km' => 20]);
        }

        $this->crear($this->datosDeEvento());

        $this->assertSame([$cerca], array_map('intval', $this->db->query('SELECT user_id FROM notifications')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    // ============================================================ editar

    public function testEditarUnEventoCambiaSoloLoQueSeManda()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $res = $this->editar($linkId, ['text' => 'Noche de tango (agotado)', 'event_date' => '2030-01-15', 'event_time' => '22:00:00']);

        $this->assertStatus(200, $res);
        $fila = $this->fila('SELECT * FROM links WHERE id = ?', [$linkId]);
        $this->assertSame('Noche de tango (agotado)', $fila['text']);
        $this->assertSame('2030-01-15', $fila['event_date']);
        $this->assertSame('22:00:00', $fila['event_time']);
        $this->assertSame('Av. Corrientes 1234, CABA', $fila['event_address']);
        $this->assertSame('Comprar', $fila['url_text']);
    }

    /** Mandar null o vacío en un campo que lo admite lo vacía; sin mandarlo, queda. */
    public function testEditarPuedeVaciarLosOpcionales()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $this->editar($linkId, ['precio_desde' => '', 'url_text' => null, 'image_url' => null]);

        $fila = $this->fila('SELECT precio_desde, url_text, image_url, description FROM links WHERE id = ?', [$linkId]);
        $this->assertNull($fila['precio_desde']);
        $this->assertNull($fila['url_text']);
        $this->assertNull($fila['image_url']);
        $this->assertSame('Con orquesta en vivo', $fila['description']);
    }

    public function testEditarSinCamposEsUnError()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $this->assertStatus(400, $this->editar($linkId, []));
    }

    public function testEditarNoPuedeDejarAlEventoSinCoordenadas()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $res = $this->editar($linkId, ['event_latitude' => '0', 'text' => 'Otro']);

        $this->assertStatus(400, $res);
        $this->assertSame('Noche de tango', $this->valor('SELECT text FROM links WHERE id = ?', [$linkId]));
    }

    /** Sin tocar las coordenadas, valen las guardadas. */
    public function testEditarSinMandarCoordenadasUsaLasGuardadas()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $this->assertStatus(200, $this->editar($linkId, ['description' => 'Nueva']));
    }

    public function testOtraPersonaNoPuedeEditar()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $otra = $this->usuario('otra@test.local', 'Otra');

        $this->assertStatus(404, $this->editar($linkId, ['text' => 'Hackeado'], $otra));
        $this->assertSame('Noche de tango', $this->valor('SELECT text FROM links WHERE id = ?', [$linkId]));
    }

    public function testEditarSinIdEs400YSinSesion401()
    {
        $this->assertStatus(400, LinksHandler::detail($this->db, $this->put(['text' => 'x'], $this->user($this->duenaId))));
        $this->assertStatus(401, LinksHandler::detail($this->db, $this->put(['text' => 'x'], null, ['id' => 1])));
        $this->assertStatus(405, LinksHandler::detail($this->db, $this->get(['id' => 1], $this->user($this->duenaId))));
    }

    /** El orden de la agenda es el que se guarda en position. */
    public function testReordenarCambiaElOrden()
    {
        $a = $this->crear($this->datosDeEvento(['text' => 'A', 'position' => 0]))->body['link']['id'];
        $b = $this->crear($this->datosDeEvento(['text' => 'B', 'position' => 1]))->body['link']['id'];

        $this->editar($a, ['position' => 1]);
        $this->editar($b, ['position' => 0]);

        $orden = $this->db->query("SELECT text FROM links WHERE group_id = {$this->grupoId} ORDER BY position, id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['B', 'A'], $orden);
    }

    /** Un evento importado recuerda qué se corrigió a mano, para que el cron no lo pise. */
    public function testEditarUnEventoImportadoLoMarcaComoEditado()
    {
        $linkId = $this->evento($this->grupoId, [
            'event_latitude' => '-34.6', 'event_longitude' => '-58.4', 'origen' => 'niceto', 'origen_id' => 'abc',
        ]);

        $this->editar($linkId, ['text' => 'Título corregido']);

        $this->assertSame('text', $this->valor('SELECT campos_editados FROM links WHERE id = ?', [$linkId]));
    }

    /** El registro de un evento que vendió guarda el último título y fecha. */
    public function testEditarUnEventoQueVendioRefrescaSuRegistro()
    {
        $linkId = $this->eventoConVentas('Título viejo');

        $this->editar($linkId, ['text' => 'Título nuevo', 'event_date' => '2031-03-03']);

        $registro = $this->fila('SELECT titulo, event_date FROM event_records WHERE link_id = ?', [$linkId]);
        $this->assertSame(['titulo' => 'Título nuevo', 'event_date' => '2031-03-03'], $registro);
    }

    /** Un evento que nunca vendió no necesita registro, y editarlo no se lo crea. */
    public function testEditarUnEventoSinVentasNoLeCreaRegistro()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $this->editar($linkId, ['text' => 'Otro']);

        $this->assertSame(0, $this->cuenta('event_records'));
    }

    // ============================================================ borrar

    public function testBorrarUnEventoSinVentas()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $this->assertStatus(200, $this->borrar($linkId));

        $this->assertSame(0, $this->cuenta('links'));
        $this->assertSame(0, $this->cuenta('event_records'));
    }

    /**
     * Lo que motivó event_records: borrar un evento no se lleva a quienes
     * compraron. Las compras quedan sin evento pero atadas a su registro, que
     * guarda cómo se llamaba y quién lo organizaba.
     */
    public function testBorrarUnEventoConVentasConservaLasCompras()
    {
        $linkId = $this->eventoConVentas('El último show');
        $recordId = (int) $this->valor('SELECT id FROM event_records WHERE link_id = ?', [$linkId]);

        $this->assertStatus(200, $this->borrar($linkId));

        $this->assertSame(0, $this->cuenta('links', 'id = ?', [$linkId]));
        $orden = $this->fila('SELECT link_id, record_id, nombre, cantidad FROM ticket_orders');
        $this->assertNull($orden['link_id']);
        $this->assertSame($recordId, (int) $orden['record_id']);
        $this->assertSame('Ana Compradora', $orden['nombre']);

        $registro = $this->fila('SELECT link_id, page_id, titulo FROM event_records WHERE id = ?', [$recordId]);
        $this->assertNull($registro['link_id']);
        $this->assertEquals($this->paginaId, $registro['page_id']);
        $this->assertSame('El último show', $registro['titulo']);

        $this->assertSame(1, $this->cuenta('event_record_pages', 'record_id = ? AND page_id = ? AND rol = ?', [$recordId, $this->paginaId, 'organizador']));
    }

    /** La configuración de venta sí se va con el evento: no queda nada que vender. */
    public function testBorrarUnEventoSeLlevaSuConfiguracionDeVenta()
    {
        $linkId = $this->eventoConVentas();

        $this->borrar($linkId);

        $this->assertSame(0, $this->cuenta('event_ticketing'));
    }

    public function testOtraPersonaNoPuedeBorrar()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $otra = $this->usuario('otra@test.local', 'Otra');

        $this->assertStatus(404, $this->borrar($linkId, $otra));
        $this->assertSame(1, $this->cuenta('links'));
    }

    public function testBorrarUnGrupoConEventosQueVendieronConservaLasCompras()
    {
        $linkId = $this->eventoConVentas('Show del grupo');

        $res = GroupsHandler::detail($this->db, $this->delete(['id' => $this->grupoId], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertSame(0, $this->cuenta('links'));
        $this->assertSame(0, $this->cuenta('link_groups'));
        $this->assertSame(1, $this->cuenta('ticket_orders', 'link_id IS NULL AND record_id IS NOT NULL'));
        $this->assertSame('Show del grupo', $this->valor('SELECT titulo FROM event_records'));
    }

    // ============================================================ grupos

    public function testCrearUnGrupoDeEventos()
    {
        $res = GroupsHandler::index($this->db, $this->post(['page_id' => $this->paginaId, 'title' => 'Agenda 2027', 'type' => 'eventos'], $this->user($this->duenaId)));

        $this->assertStatus(201, $res);
        $this->assertSame('eventos', $this->valor('SELECT type FROM link_groups WHERE id = ?', [$res->body['group']['id']]));
    }

    public function testUnGrupoSinTipoEsDeLinks()
    {
        $res = GroupsHandler::index($this->db, $this->post(['page_id' => $this->paginaId, 'title' => 'Redes'], $this->user($this->duenaId)));

        $this->assertSame('links', $res->body['group']['type']);
    }

    public function testUnTipoDeGrupoDesconocidoNoSeGuarda()
    {
        $res = GroupsHandler::index($this->db, $this->post(['page_id' => $this->paginaId, 'title' => 'X', 'type' => 'redes'], $this->user($this->duenaId)));

        $this->assertStatus(400, $res);
        $this->assertSame(1, $this->cuenta('link_groups'));
    }

    public function testOtraPersonaNoPuedeCrearGruposEnUnaPaginaAjena()
    {
        $otra = $this->usuario('otra@test.local', 'Otra');

        $this->assertStatus(404, GroupsHandler::index($this->db, $this->post(['page_id' => $this->paginaId, 'title' => 'X'], $this->user($otra))));
    }

    public function testEditarUnGrupo()
    {
        $res = GroupsHandler::detail($this->db, $this->put(['title' => 'Próximas fechas', 'position' => 2], $this->user($this->duenaId), ['id' => $this->grupoId]));

        $this->assertStatus(200, $res);
        $this->assertSame(['title' => 'Próximas fechas', 'position' => 2], array_map(function ($v) {
            return is_numeric($v) ? (int) $v : $v;
        }, $this->fila('SELECT title, position FROM link_groups WHERE id = ?', [$this->grupoId])));
    }

    // =================================================== venta de entradas

    public function testActivarLaVentaGratis()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $res = $this->configurarVenta($linkId, ['capacidad' => 120, 'precio' => 0, 'max_por_compra' => 4]);

        $this->assertStatus(200, $res);
        $fila = $this->fila('SELECT activo, capacidad, precio, moneda, max_por_compra, plano FROM event_ticketing WHERE link_id = ?', [$linkId]);
        $this->assertEquals(1, $fila['activo']);
        $this->assertEquals(120, $fila['capacidad']);
        $this->assertEquals(0, (float) $fila['precio']);
        $this->assertSame('ARS', $fila['moneda']);
        $this->assertEquals(4, $fila['max_por_compra']);
        $this->assertNull($fila['plano']);
        $this->assertEquals(120, $res->body['entradas']['capacidad']);
    }

    public function testLaVentaPagaPideMercadoPago()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $res = $this->configurarVenta($linkId, ['capacidad' => 100, 'precio' => 15000]);

        $this->assertStatus(400, $res);
        $this->assertStringContainsString('Mercado Pago', $res->body['error']);
        $this->assertSame(0, $this->cuenta('event_ticketing'));
    }

    public function testActivarLaVentaPagaConMercadoPagoConectado()
    {
        $this->insertar('page_payment_settings', [
            'page_id' => $this->paginaId, 'access_token_cifrado' => 'x', 'token_ultimos4' => '1234',
            'public_key' => 'APP_USR-pk', 'modo' => 'produccion', 'conectado_por' => 'oauth', 'mp_user_id' => '99',
        ]);
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $res = $this->configurarVenta($linkId, ['capacidad' => 100, 'precio' => '15000.50', 'moneda' => 'ars']);

        $this->assertStatus(200, $res);
        $fila = $this->fila('SELECT precio, moneda FROM event_ticketing WHERE link_id = ?', [$linkId]);
        $this->assertEquals(15000.5, (float) $fila['precio']);
        $this->assertSame('ARS', $fila['moneda']);

        $get = EntradasHandler::config($this->db, $this->get(['link_id' => $linkId], $this->user($this->duenaId)));
        $this->assertTrue($get->body['cobros']['configurado']);
        $this->assertTrue($get->body['cobros']['admite_split']);
        $this->assertSame(0, $get->body['ocupadas']);
    }

    /** Con plano, la capacidad es la cantidad de lugares, diga lo que diga el pedido. */
    public function testConPlanoLaCapacidadSaleDeLosLugares()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $plano = ['ancho' => 20, 'alto' => 12, 'elementos' => [
            ['tipo' => 'fila', 'nombre' => 'A', 'butacas' => 5, 'desde' => 1, 'x' => 0, 'y' => 0],
            ['tipo' => 'mesa', 'nombre' => '1', 'lugares' => 4, 'x' => 5, 'y' => 5],
        ]];

        $res = $this->configurarVenta($linkId, ['capacidad' => 999, 'precio' => 0, 'plano' => $plano]);

        $this->assertStatus(200, $res);
        $fila = $this->fila('SELECT capacidad, plano FROM event_ticketing WHERE link_id = ?', [$linkId]);
        $this->assertEquals(9, $fila['capacidad']);
        $this->assertCount(2, json_decode($fila['plano'], true)['elementos']);
        $this->assertCount(2, $res->body['entradas']['plano']['elementos']);
    }

    /** Sin mandar plano, cambiar el precio no borra el que había. */
    public function testCambiarElPrecioSinMandarPlanoLoConserva()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $plano = ['ancho' => 10, 'alto' => 5, 'elementos' => [['tipo' => 'fila', 'nombre' => 'A', 'butacas' => 3, 'desde' => 1, 'x' => 0, 'y' => 0]]];
        $this->configurarVenta($linkId, ['precio' => 0, 'plano' => $plano]);

        $this->configurarVenta($linkId, ['precio' => 0, 'max_por_compra' => 2]);

        $this->assertNotNull($this->valor('SELECT plano FROM event_ticketing WHERE link_id = ?', [$linkId]));
        $this->assertEquals(3, $this->valor('SELECT capacidad FROM event_ticketing WHERE link_id = ?', [$linkId]));
    }

    public function testUnaCapacidadInvalidaNoSeGuarda()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $this->assertStatus(400, $this->configurarVenta($linkId, ['capacidad' => 0, 'precio' => 0]));
        $this->assertStatus(400, $this->configurarVenta($linkId, ['capacidad' => 10, 'precio' => 0, 'max_por_compra' => 51]));
        $this->assertSame(0, $this->cuenta('event_ticketing'));
    }

    public function testNoSePuedeBajarLaCapacidadPorDebajoDeLoVendido()
    {
        $linkId = $this->eventoConVentas();

        $res = $this->configurarVenta($linkId, ['capacidad' => 1, 'precio' => 0]);

        $this->assertStatus(400, $res);
        $this->assertEquals(50, $this->valor('SELECT capacidad FROM event_ticketing WHERE link_id = ?', [$linkId]));
    }

    public function testDesactivarLaVentaSinVentas()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $this->configurarVenta($linkId, ['capacidad' => 10, 'precio' => 0]);

        $res = EntradasHandler::config($this->db, new Request('DELETE', [], ['link_id' => $linkId], $this->user($this->duenaId)));

        $this->assertStatus(200, $res);
        $this->assertSame(0, $this->cuenta('event_ticketing'));
    }

    /** Con entradas tomadas, cortar la venta pide confirmación. */
    public function testDesactivarLaVentaConVentasPideConfirmacion()
    {
        $linkId = $this->eventoConVentas();

        $sin = EntradasHandler::config($this->db, new Request('DELETE', [], ['link_id' => $linkId], $this->user($this->duenaId)));
        $this->assertStatus(409, $sin);
        $this->assertSame(1, $this->cuenta('event_ticketing'));

        $con = EntradasHandler::config($this->db, new Request('DELETE', ['confirmar' => true], ['link_id' => $linkId], $this->user($this->duenaId)));
        $this->assertStatus(200, $con);
        $this->assertSame(0, $this->cuenta('event_ticketing'));
        $this->assertSame(1, $this->cuenta('ticket_orders'));
    }

    public function testOtraPersonaNoPuedeConfigurarLaVenta()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $otra = $this->usuario('otra@test.local', 'Otra');

        $this->assertStatus(403, $this->configurarVenta($linkId, ['capacidad' => 10, 'precio' => 0], $otra));
        $this->assertSame(0, $this->cuenta('event_ticketing'));
    }

    // ============================================================ asistente

    /** La dirección ya resuelta: el asistente no sale a Nominatim en los tests. */
    private function direccionConocida($direccion, $lat = -34.5889, $lng = -58.4301)
    {
        $normal = Geocodificador::normalizar($direccion);
        $this->insertar('geocode_cache', [
            'huella' => hash('sha256', $normal), 'direccion' => $normal, 'latitud' => $lat, 'longitud' => $lng, 'intentos' => 1,
        ]);
    }

    private $claves = [];

    /** Una llamada MCP de verdad, con una clave de API de la base (una por persona). */
    private function llamarAsistente($herramienta, array $argumentos, $userId = null)
    {
        $userId = $userId ?: $this->duenaId;
        if (!isset($this->claves[$userId])) {
            $this->claves[$userId] = ClavesApi::generar($this->db, $userId, 'Claude')['clave'];
        }
        $clave = $this->claves[$userId];
        $req = new Request('POST', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $herramienta, 'arguments' => $argumentos],
        ], [], null, [], ['Authorization' => 'Bearer ' . $clave]);

        $res = McpHandler::rpc($this->db, $req);
        $this->assertStatus(200, $res);

        return $res->body['result'];
    }

    public function testElAsistenteCreaUnEventoConEntradas()
    {
        $this->direccionConocida('Niceto Vega 5510, CABA');

        $creado = $this->llamarAsistente('crear_evento', [
            'pagina' => 'la-sala', 'titulo' => 'Show dictado', 'fecha' => '2030-06-20', 'hora' => '21:00',
            'direccion' => 'Niceto Vega 5510, CABA', 'descripcion' => 'Cargado hablando', 'precio_desde' => 8000,
        ]);

        $this->assertFalse($creado['isError'], $creado['content'][0]['text']);
        $evento = $this->fila('SELECT * FROM links WHERE text = ?', ['Show dictado']);
        $this->assertSame('2030-06-20', $evento['event_date']);
        $this->assertSame('21:00:00', $evento['event_time']);
        $this->assertEquals(-34.5889, (float) $evento['event_latitude']);
        $this->assertEquals(8000, (float) $evento['precio_desde']);
        $this->assertSame('', $evento['url']);
        // Ya había un grupo de eventos: lo usa, no crea otro.
        $this->assertEquals($this->grupoId, $evento['group_id']);

        $entradas = $this->llamarAsistente('configurar_entradas', ['evento_id' => (int) $evento['id'], 'modo' => 'gratis', 'capacidad' => 80]);

        $this->assertFalse($entradas['isError'], $entradas['content'][0]['text']);
        $this->assertEquals(80, $this->valor('SELECT capacidad FROM event_ticketing WHERE link_id = ?', [$evento['id']]));
        $this->assertEquals(1, $this->valor('SELECT COUNT(*) FROM api_keys WHERE ultimo_uso_en IS NOT NULL'));
    }

    /** Una página sin agenda todavía: el asistente se la arma. */
    public function testElAsistenteCreaLaAgendaSiNoHay()
    {
        $this->db->exec('DELETE FROM link_groups');
        $this->direccionConocida('Av. Rivadavia 100, CABA');

        $r = $this->llamarAsistente('crear_evento', ['pagina' => 'la-sala', 'titulo' => 'Primero', 'fecha' => '2030-01-01', 'direccion' => 'Av. Rivadavia 100, CABA']);

        $this->assertFalse($r['isError'], $r['content'][0]['text']);
        $this->assertSame(['title' => 'Agenda', 'type' => 'eventos'], $this->fila('SELECT title, type FROM link_groups'));
    }

    public function testElAsistenteActualizaYBorraUnEvento()
    {
        $this->direccionConocida('Otra calle 1, CABA', -34.7, -58.5);
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $r = $this->llamarAsistente('actualizar_evento', ['evento_id' => (int) $linkId, 'titulo' => 'Cambiado', 'hora' => '23:15', 'direccion' => 'Otra calle 1, CABA']);
        $this->assertFalse($r['isError'], $r['content'][0]['text']);
        $fila = $this->fila('SELECT text, event_time, event_latitude, event_address FROM links WHERE id = ?', [$linkId]);
        $this->assertSame('Cambiado', $fila['text']);
        $this->assertSame('23:15:00', $fila['event_time']);
        $this->assertEquals(-34.7, (float) $fila['event_latitude']);

        $r = $this->llamarAsistente('borrar_evento', ['evento_id' => (int) $linkId]);
        $this->assertFalse($r['isError']);
        $this->assertSame(0, $this->cuenta('links'));
    }

    /** Si la dirección no aparece en el mapa, no se publica un evento sin mapa. */
    public function testElAsistenteNoCreaUnEventoQueNoPuedeUbicar()
    {
        $normal = Geocodificador::normalizar('Calle que no existe 999');
        $this->insertar('geocode_cache', ['huella' => hash('sha256', $normal), 'direccion' => $normal, 'latitud' => null, 'longitud' => null, 'intentos' => Geocodificador::MAX_INTENTOS]);

        $r = $this->llamarAsistente('crear_evento', ['pagina' => 'la-sala', 'titulo' => 'Perdido', 'fecha' => '2030-01-01', 'direccion' => 'Calle que no existe 999']);

        $this->assertTrue($r['isError']);
        $this->assertSame(0, $this->cuenta('links'));
    }

    public function testElAsistenteNoTocaPaginasAjenas()
    {
        $otra = $this->usuario('otra@test.local', 'Otra');
        $this->direccionConocida('Av. Rivadavia 100, CABA');

        $r = $this->llamarAsistente('crear_evento', ['pagina' => 'la-sala', 'titulo' => 'Intruso', 'fecha' => '2030-01-01', 'direccion' => 'Av. Rivadavia 100, CABA'], $otra);

        $this->assertTrue($r['isError']);
        $this->assertSame(0, $this->cuenta('links'));
    }

    public function testElAsistenteNoActivaLaVentaPagaSinMercadoPago()
    {
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];

        $r = $this->llamarAsistente('configurar_entradas', ['evento_id' => (int) $linkId, 'modo' => 'pago', 'precio' => 5000, 'capacidad' => 10]);

        $this->assertTrue($r['isError']);
        $this->assertSame(0, $this->cuenta('event_ticketing'));
    }

    // ======================================================= colaboración

    /**
     * Dos páginas hacen un evento juntas: la que colabora lo muestra en su
     * agenda y, si vende, ve a los clientes. Deshacer la colaboración le saca
     * las dos cosas.
     */
    public function testColaborarEnUnEventoDePuntaAPunta()
    {
        $socia = $this->usuario('socia@test.local', 'Socia');
        $paginaSocia = $this->pagina($socia, 'la-socia', 'La Socia');
        $agendaSocia = $this->grupo($paginaSocia);
        $linkId = $this->eventoConVentas('Fecha compartida');
        $this->db->prepare('UPDATE links SET event_date = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('+5 days')), $linkId]);

        $invitacion = CollaborationsHandler::index($this->db, $this->post(['link_id' => $linkId, 'collaborator_page_id' => $paginaSocia], $this->user($this->duenaId)));
        $this->assertStatus(201, $invitacion);
        $collabId = $invitacion->body['collaboration_id'];
        $this->assertSame('pending', $this->valor('SELECT status FROM event_collaborations WHERE id = ?', [$collabId]));
        $this->assertSame(1, $this->cuenta('notifications', 'user_id = ? AND type = ?', [$socia, 'collaboration_request']));

        $pendientes = CollaborationsHandler::index($this->db, $this->get(['type' => 'pending'], $this->user($socia, 'socia@test.local')));
        $this->assertCount(1, $pendientes->body['pending']);

        $acepta = CollaborationsHandler::detail($this->db, $this->put(['status' => 'accepted', 'group_id' => $agendaSocia], $this->user($socia, 'socia@test.local'), ['id' => $collabId]));
        $this->assertStatus(200, $acepta);

        $recordId = $this->valor('SELECT id FROM event_records WHERE link_id = ?', [$linkId]);
        $this->assertSame(1, $this->cuenta('event_record_pages', 'record_id = ? AND page_id = ? AND rol = ?', [$recordId, $paginaSocia, 'colaborador']));
        $this->assertSame(1, $this->cuenta('notifications', 'user_id = ? AND type = ?', [$this->duenaId, 'collaboration_response']));
        $this->assertEquals(1, $this->valor('SELECT is_read FROM notifications WHERE collaboration_id = ?', [$collabId]));

        $publica = PublicHandler::page($this->db, $this->get(['slug' => 'la-socia']));
        $colaborados = $publica->body['page']['groups'][0]['collaborated_events'];
        $this->assertCount(1, $colaborados);
        $this->assertSame('Fecha compartida', $colaborados[0]['text']);
        $this->assertSame('la-sala', $colaborados[0]['source_page_slug']);

        $deshace = CollaborationsHandler::detail($this->db, $this->delete(['id' => $collabId], $this->user($socia, 'socia@test.local')));
        $this->assertStatus(200, $deshace);
        $this->assertSame(0, $this->cuenta('event_collaborations'));
        $this->assertSame(0, $this->cuenta('event_record_pages', 'rol = ?', ['colaborador']));
        $this->assertSame(1, $this->cuenta('event_record_pages', 'rol = ?', ['organizador']));
    }

    public function testNoSeAceptaEnUnGrupoQueNoEsDeEventos()
    {
        $socia = $this->usuario('socia@test.local', 'Socia');
        $paginaSocia = $this->pagina($socia, 'la-socia', 'La Socia');
        $redes = $this->grupo($paginaSocia, 'links', 'Redes');
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $collabId = CollaborationsHandler::index($this->db, $this->post(['link_id' => $linkId, 'collaborator_page_id' => $paginaSocia], $this->user($this->duenaId)))->body['collaboration_id'];

        $res = CollaborationsHandler::detail($this->db, $this->put(['status' => 'accepted', 'group_id' => $redes], $this->user($socia), ['id' => $collabId]));

        $this->assertStatus(400, $res);
        $this->assertSame('pending', $this->valor('SELECT status FROM event_collaborations'));
    }

    public function testNoSeInvitaALaPropiaPaginaNiDosVeces()
    {
        $socia = $this->usuario('socia@test.local', 'Socia');
        $paginaSocia = $this->pagina($socia, 'la-socia', 'La Socia');
        $linkId = $this->crear($this->datosDeEvento())->body['link']['id'];
        $invitar = function ($pagina) use ($linkId) {
            return CollaborationsHandler::index($this->db, $this->post(['link_id' => $linkId, 'collaborator_page_id' => $pagina], $this->user($this->duenaId)));
        };

        $this->assertStatus(400, $invitar($this->paginaId));
        $this->assertStatus(201, $invitar($paginaSocia));
        $this->assertStatus(409, $invitar($paginaSocia));
        $this->assertSame(1, $this->cuenta('event_collaborations'));
    }

    /** Rechazar deja la invitación cerrada y sin registro de clientes compartido. */
    public function testRechazarUnaColaboracion()
    {
        $socia = $this->usuario('socia@test.local', 'Socia');
        $paginaSocia = $this->pagina($socia, 'la-socia', 'La Socia');
        $linkId = $this->eventoConVentas();
        $collabId = CollaborationsHandler::index($this->db, $this->post(['link_id' => $linkId, 'collaborator_page_id' => $paginaSocia], $this->user($this->duenaId)))->body['collaboration_id'];

        $res = CollaborationsHandler::detail($this->db, $this->put(['status' => 'rejected'], $this->user($socia), ['id' => $collabId]));

        $this->assertStatus(200, $res);
        $this->assertSame('rejected', $this->valor('SELECT status FROM event_collaborations'));
        $this->assertSame(0, $this->cuenta('event_record_pages', 'rol = ?', ['colaborador']));
        $this->assertStatus(400, CollaborationsHandler::detail($this->db, $this->put(['status' => 'accepted', 'group_id' => 1], $this->user($socia), ['id' => $collabId])));
    }
}
