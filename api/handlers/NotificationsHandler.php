<?php

/**
 * Notificaciones: listado y marcado (index), suscripciones push (subscribe) y
 * el proceso diario que genera avisos de eventos nuevos (processDaily).
 *
 * Extraído de api/notifications/*.php. Se corrigieron dos usos de una variable
 * $conn inexistente que rompían el marcado por IDs y el borrado; ver
 * marcarPorIds() y eliminar().
 */
class NotificationsHandler
{
    const LIMITE_POR_DEFECTO = 50;

    // ------------------------------------------------------------------ index

    public static function index($db, Request $req)
    {
        $error = self::exigirSesion($req);
        if ($error !== null) {
            return $error;
        }

        if ($req->method === 'GET') {
            return self::listar($db, $req);
        }

        if ($req->method === 'PUT') {
            return self::marcarLeidas($db, $req);
        }

        if ($req->method === 'DELETE') {
            return self::eliminar($db, $req);
        }

        return Response::error(405, 'Método no permitido');
    }

    private static function listar($db, Request $req)
    {
        $limit = $req->param('limit') !== null ? (int) $req->param('limit') : self::LIMITE_POR_DEFECTO;
        $offset = $req->param('offset') !== null ? (int) $req->param('offset') : 0;
        $soloNoLeidas = $req->param('unread_only') === 'true';

        $where = 'WHERE n.user_id = ?';
        if ($soloNoLeidas) {
            $where .= ' AND n.is_read = 0';
        }

        $stmt = $db->prepare("
            SELECT
                n.id,
                n.title,
                n.message,
                n.type,
                n.is_read,
                n.created_at,
                p.id as page_id,
                p.url_slug as page_slug,
                p.title as page_title,
                l.id as link_id,
                l.text as event_title,
                l.url as event_url
            FROM notifications n
            INNER JOIN pages p ON n.page_id = p.id
            INNER JOIN links l ON n.link_id = l.id
            $where
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$req->userId(), $limit, $offset]);
        $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notificaciones as &$n) {
            $n['id'] = (int) $n['id'];
            $n['is_read'] = (bool) $n['is_read'];
            $n['page_id'] = (int) $n['page_id'];
            $n['link_id'] = (int) $n['link_id'];
        }
        unset($n);

        $stmt = $db->prepare('SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$req->userId()]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return Response::ok([
            'notifications' => $notificaciones,
            'unread_count' => (int) (isset($fila['unread_count']) ? $fila['unread_count'] : 0),
            'total' => count($notificaciones),
        ]);
    }

    private static function marcarLeidas($db, Request $req)
    {
        if ($req->input('mark_all_as_read') === true) {
            $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
            $stmt->execute([$req->userId()]);

            return Response::ok([
                'success' => true,
                'message' => 'Todas las notificaciones marcadas como leídas',
                'updated_count' => $stmt->rowCount(),
            ]);
        }

        return self::marcarPorIds($db, $req);
    }

    private static function marcarPorIds($db, Request $req)
    {
        $ids = $req->input('notification_ids');

        if (!$ids || !is_array($ids)) {
            return Response::error(400, 'IDs de notificaciones requeridos');
        }

        // El código original usaba $conn (inexistente) en lugar de $db, así que
        // esta rama siempre terminaba en error fatal.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$req->userId()], array_values($ids)));

        return Response::ok([
            'success' => true,
            'message' => 'Notificaciones marcadas como leídas',
            'updated_count' => $stmt->rowCount(),
        ]);
    }

    private static function eliminar($db, Request $req)
    {
        $crudo = $req->param('ids');
        $ids = $crudo ? explode(',', $crudo) : null;

        if (!$ids || !is_array($ids)) {
            return Response::error(400, 'IDs de notificaciones requeridos');
        }

        // Mismo bug de $conn que en marcarPorIds().
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$req->userId()], $ids));

        return Response::ok([
            'success' => true,
            'message' => 'Notificaciones eliminadas',
            'deleted_count' => $stmt->rowCount(),
        ]);
    }

    // -------------------------------------------------------------- subscribe

    public static function subscribe($db, Request $req)
    {
        if (!$req->hasBearerToken()) {
            return Response::unauthorized('Token no proporcionado');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'POST') {
            return self::registrarSuscripcion($db, $req);
        }

        if ($req->method === 'DELETE') {
            return self::borrarSuscripcion($db, $req);
        }

        if ($req->method === 'GET') {
            if (!defined('VAPID_PUBLIC_KEY')) {
                return Response::error(500, 'Clave VAPID no configurada');
            }

            return Response::ok(['public_key' => VAPID_PUBLIC_KEY]);
        }

        return Response::error(405, 'Método no permitido');
    }

    private static function registrarSuscripcion($db, Request $req)
    {
        $endpoint = $req->input('endpoint');
        $claves = $req->input('keys', []);
        $p256dh = isset($claves['p256dh']) ? $claves['p256dh'] : null;
        $auth = isset($claves['auth']) ? $claves['auth'] : null;

        if (!$endpoint || !$p256dh || !$auth) {
            return Response::error(400, 'Datos de suscripción incompletos');
        }

        $stmt = $db->prepare('
            INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            p256dh_key = VALUES(p256dh_key),
            auth_key = VALUES(auth_key)
        ');
        $stmt->execute([$req->userId(), $endpoint, $p256dh, $auth]);

        return Response::ok([
            'success' => true,
            'message' => 'Suscripción registrada correctamente',
        ]);
    }

    private static function borrarSuscripcion($db, Request $req)
    {
        $endpoint = $req->input('endpoint');

        if (!$endpoint) {
            return Response::error(400, 'Endpoint requerido');
        }

        $stmt = $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
        $stmt->execute([$req->userId(), $endpoint]);

        return Response::ok([
            'success' => true,
            'message' => 'Suscripción eliminada',
        ]);
    }

    // ------------------------------------------------------------ processDaily

    /**
     * Punto de entrada web del proceso diario. Por CLI se invoca
     * procesarEventosNuevos() directamente desde el wrapper.
     */
    public static function processDaily($db, Request $req)
    {
        if (!defined('CRON_SECRET_KEY') || $req->param('cron_key') !== CRON_SECRET_KEY) {
            return Response::error(403, 'Acceso denegado');
        }

        $resumen = self::procesarEventosNuevos($db);

        return Response::ok([
            'success' => true,
            'events_processed' => $resumen['events_processed'],
            'notifications_sent' => $resumen['notifications_sent'],
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Crea notificaciones para los seguidores de cada evento publicado o
     * modificado en las últimas 24 horas.
     *
     * @return array{events_processed: int, notifications_sent: int, log: string[]}
     */
    public static function procesarEventosNuevos($db)
    {
        $log = [];
        $eventosProcesados = 0;
        $notificacionesCreadas = 0;

        $stmt = $db->prepare('
            SELECT
                l.id,
                lg.page_id,
                p.title,
                p.url_slug,
                l.event_date,
                l.event_address,
                l.event_latitude,
                l.event_longitude,
                p.title as page_title
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON lg.page_id = p.id
            WHERE lg.type = "eventos"
            AND l.event_date > NOW()
            AND (l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) OR l.updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
            ORDER BY l.created_at DESC
        ');
        $stmt->execute();
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $log[] = 'Eventos nuevos encontrados: ' . count($eventos);

        foreach ($eventos as $evento) {
            $seguidoresStmt = $db->prepare('
                SELECT
                    pf.user_id,
                    pf.notify_all_events,
                    pf.max_distance_km,
                    u.location_latitude,
                    u.location_longitude,
                    u.email
                FROM page_followers pf
                INNER JOIN users u ON pf.user_id = u.id
                WHERE pf.page_id = ?
            ');
            $seguidoresStmt->execute([$evento['page_id']]);
            $seguidores = $seguidoresStmt->fetchAll(PDO::FETCH_ASSOC);

            $log[] = '  Procesando evento: ' . $evento['title'] . ' (' . $evento['id'] . ') - Seguidores: ' . count($seguidores);

            foreach ($seguidores as $seguidor) {
                if (!self::debeNotificar($evento, $seguidor)) {
                    continue;
                }

                // Evita duplicar el aviso si el evento se editó dentro de las 24 h.
                $yaExiste = $db->prepare('SELECT id FROM notifications WHERE user_id = ? AND link_id = ?');
                $yaExiste->execute([$seguidor['user_id'], $evento['id']]);

                if ($yaExiste->fetch()) {
                    continue;
                }

                $insert = $db->prepare('
                    INSERT INTO notifications (user_id, page_id, link_id, title, message)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $insert->execute([
                    $seguidor['user_id'],
                    $evento['page_id'],
                    $evento['id'],
                    self::tituloDeAviso($evento),
                    self::mensajeDeAviso($evento),
                ]);

                $notificacionesCreadas++;
                $log[] = '    Notificación creada para usuario ' . $seguidor['user_id'];
            }

            $eventosProcesados++;
        }

        return [
            'events_processed' => $eventosProcesados,
            'notifications_sent' => $notificacionesCreadas,
            'log' => $log,
        ];
    }

    /** Notifica si el seguidor pidió todo, o si el evento cae dentro de su radio. */
    public static function debeNotificar(array $evento, array $seguidor)
    {
        if ((bool) $seguidor['notify_all_events']) {
            return true;
        }

        $tieneCoordenadas = $evento['event_latitude'] && $evento['event_longitude']
            && $seguidor['location_latitude'] && $seguidor['location_longitude'];

        if (!$tieneCoordenadas) {
            return false;
        }

        $distancia = Geo::distanceKm(
            $seguidor['location_latitude'],
            $seguidor['location_longitude'],
            $evento['event_latitude'],
            $evento['event_longitude']
        );

        return $distancia <= (float) $seguidor['max_distance_km'];
    }

    public static function tituloDeAviso(array $evento)
    {
        return 'Nuevo evento: ' . $evento['title'];
    }

    public static function mensajeDeAviso(array $evento)
    {
        $mensaje = 'La página ' . $evento['page_title'] . ' ha publicado un nuevo evento';

        if (!empty($evento['event_date'])) {
            $mensaje .= ' para el ' . date('d/m/Y', strtotime($evento['event_date']));
        }

        return $mensaje;
    }

    // ------------------------------------------------------------- compartido

    /**
     * index.php usaba mensajes propios ("Token inválido o expirado") distintos
     * del resto de la API; se conservan.
     */
    private static function exigirSesion(Request $req)
    {
        if (!$req->hasBearerToken()) {
            return Response::unauthorized('Token no proporcionado');
        }

        if (!$req->user) {
            return Response::unauthorized('Token inválido o expirado');
        }

        return null;
    }
}
