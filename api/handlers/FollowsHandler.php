<?php

/**
 * Seguimiento de páginas.
 *
 * Extraído de api/pages/follow.php y api/pages/following.php. Ambos
 * distinguen "no mandaste token" (Token no proporcionado) de "tu token no
 * vale" (Unauthorized), y ese matiz se conserva.
 */
class FollowsHandler
{
    const NOTIFY_ALL_POR_DEFECTO = true;
    const DISTANCIA_POR_DEFECTO_KM = 50.00;

    // ----------------------------------------------------------------- follow

    public static function follow($db, Request $req)
    {
        $error = self::exigirSesion($req);
        if ($error !== null) {
            return $error;
        }

        if ($req->method === 'POST') {
            return self::seguir($db, $req);
        }

        if ($req->method === 'DELETE') {
            return self::dejarDeSeguir($db, $req);
        }

        if ($req->method === 'GET') {
            return self::estado($db, $req);
        }

        return Response::error(405, 'Método no permitido');
    }

    private static function seguir($db, Request $req)
    {
        $pageId = (int) $req->input('page_id');

        $notifyAllEvents = $req->has('notify_all_events')
            ? (bool) $req->input('notify_all_events')
            : self::NOTIFY_ALL_POR_DEFECTO;

        $maxDistanceKm = $req->has('max_distance_km')
            ? (float) $req->input('max_distance_km')
            : self::DISTANCIA_POR_DEFECTO_KM;

        if (!$pageId) {
            return Response::error(400, 'ID de página requerido');
        }

        $stmt = $db->prepare('SELECT id FROM pages WHERE id = ?');
        $stmt->execute([$pageId]);

        if (!$stmt->fetch()) {
            return Response::notFound('Página no encontrada');
        }

        // ON DUPLICATE KEY: seguir dos veces actualiza las preferencias en
        // lugar de fallar por la clave única (user_id, page_id).
        $stmt = $db->prepare('
            INSERT INTO page_followers (user_id, page_id, notify_all_events, max_distance_km)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            notify_all_events = VALUES(notify_all_events),
            max_distance_km = VALUES(max_distance_km)
        ');
        $stmt->execute([$req->userId(), $pageId, $notifyAllEvents ? 1 : 0, $maxDistanceKm]);

        return Response::ok([
            'success' => true,
            'message' => 'Preferencias de seguimiento actualizadas',
            'page_id' => $pageId,
            'notify_all_events' => $notifyAllEvents,
            'max_distance_km' => $maxDistanceKm,
        ]);
    }

    private static function dejarDeSeguir($db, Request $req)
    {
        $pageId = (int) $req->param('page_id');

        if (!$pageId) {
            return Response::error(400, 'ID de página requerido');
        }

        $stmt = $db->prepare('DELETE FROM page_followers WHERE user_id = ? AND page_id = ?');
        $stmt->execute([$req->userId(), $pageId]);

        if ($stmt->rowCount() === 0) {
            return Response::notFound('No seguías esta página');
        }

        return Response::ok([
            'success' => true,
            'message' => 'Dejaste de seguir la página',
        ]);
    }

    private static function estado($db, Request $req)
    {
        $pageId = (int) $req->param('page_id');

        if (!$pageId) {
            return Response::error(400, 'ID de página requerido');
        }

        $stmt = $db->prepare('
            SELECT notify_all_events, max_distance_km, created_at
            FROM page_followers
            WHERE user_id = ? AND page_id = ?
        ');
        $stmt->execute([$req->userId(), $pageId]);
        $follow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$follow) {
            return Response::ok(['is_following' => false]);
        }

        return Response::ok([
            'is_following' => true,
            'notify_all_events' => (bool) $follow['notify_all_events'],
            'max_distance_km' => (float) $follow['max_distance_km'],
            'following_since' => $follow['created_at'],
        ]);
    }

    // -------------------------------------------------------------- following

    public static function following($db, Request $req)
    {
        $error = self::exigirSesion($req);
        if ($error !== null) {
            return $error;
        }

        if ($req->method !== 'GET') {
            return Response::error(405, 'Método no permitido');
        }

        $stmt = $db->prepare('
            SELECT
                p.id,
                p.url_slug as slug,
                p.title,
                p.description,
                p.profile_image as image_url,
                pf.notify_all_events,
                pf.max_distance_km,
                pf.created_at as following_since,
                (SELECT COUNT(*) FROM page_followers WHERE page_id = p.id) as follower_count
            FROM page_followers pf
            INNER JOIN pages p ON pf.page_id = p.id
            WHERE pf.user_id = ?
            ORDER BY pf.created_at DESC
        ');
        $stmt->execute([$req->userId()]);
        $following = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // MySQL devuelve todo como string; el frontend espera tipos reales.
        foreach ($following as &$page) {
            $page['id'] = (int) $page['id'];
            $page['notify_all_events'] = (bool) $page['notify_all_events'];
            $page['max_distance_km'] = (float) $page['max_distance_km'];
        }
        unset($page);

        return Response::ok([
            'following' => $following,
            'total' => count($following),
        ]);
    }

    // ------------------------------------------------------------- compartido

    /** @return Response|null null si la sesión es válida. */
    private static function exigirSesion(Request $req)
    {
        if (!$req->hasBearerToken()) {
            return Response::unauthorized('Token no proporcionado');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        return null;
    }
}
