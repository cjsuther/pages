<?php

/**
 * Suscripciones push y confirmación de entrega.
 *
 * Ver GUIA-PUSH-PWA.md §6 y §7.
 */
class PushHandler
{
    // ------------------------------------------------------------------ vapid

    /**
     * Clave pública VAPID. Es pública por definición y se sirve sin sesión: el
     * service worker la necesita al renovar la suscripción
     * (pushsubscriptionchange), momento en el que no tiene el token del usuario.
     */
    public static function vapid($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        if (!defined('VAPID_PUBLIC_KEY') || VAPID_PUBLIC_KEY === '') {
            return Response::error(500, 'Las notificaciones push no están configuradas en el servidor');
        }

        return Response::ok([
            'public_key' => VAPID_PUBLIC_KEY,
            // El cliente lo usa para no ofrecer push si el servidor no puede enviarlo.
            'disponible' => PushSender::disponible(),
        ]);
    }

    // -------------------------------------------------------------- subscribe

    public static function subscribe($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'POST') {
            return self::registrar($db, $req);
        }

        if ($req->method === 'DELETE') {
            return self::borrar($db, $req);
        }

        return Response::methodNotAllowed();
    }

    private static function registrar($db, Request $req)
    {
        // El cliente puede mandar la suscripción cruda o envuelta en
        // `suscripcion`, que es como la serializa el navegador.
        $sus = $req->input('suscripcion', $req->body);

        $endpoint = isset($sus['endpoint']) ? $sus['endpoint'] : null;
        $claves   = isset($sus['keys']) ? $sus['keys'] : [];
        $p256dh   = isset($claves['p256dh']) ? $claves['p256dh'] : null;
        $auth     = isset($claves['auth']) ? $claves['auth'] : null;

        if (!$endpoint || !$p256dh || !$auth) {
            return Response::error(400, 'Datos de suscripción incompletos');
        }

        $userAgent = $req->header('User-Agent', '');

        // El endpoint es único: volver a suscribirse desde el mismo dispositivo
        // actualiza la fila en lugar de duplicarla (guía §6).
        $stmt = $db->prepare('
            INSERT INTO push_subscriptions
                (user_id, endpoint, p256dh_key, auth_key, platform, brand, standalone, user_agent, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                user_id      = VALUES(user_id),
                p256dh_key   = VALUES(p256dh_key),
                auth_key     = VALUES(auth_key),
                platform     = VALUES(platform),
                brand        = VALUES(brand),
                standalone   = VALUES(standalone),
                user_agent   = VALUES(user_agent),
                last_seen_at = NOW()
        ');
        $stmt->execute([
            $req->userId(),
            $endpoint,
            $p256dh,
            $auth,
            DeviceInfo::plataforma($userAgent),
            DeviceInfo::marca($userAgent),
            $req->input('standalone') ? 1 : 0,
            substr((string) $userAgent, 0, 500),
        ]);

        return Response::ok([
            'success'  => true,
            'message'  => 'Suscripción registrada correctamente',
            'platform' => DeviceInfo::plataforma($userAgent),
        ]);
    }

    private static function borrar($db, Request $req)
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

    // -------------------------------------------------------------------- ack

    /**
     * Confirmación de entrega enviada por el service worker.
     *
     * No lleva sesión a propósito: el worker puede despertarse sin token. El
     * envio_id es un valor aleatorio que sólo conoce quien recibió la
     * notificación, y la respuesta no expone ningún dato.
     */
    public static function ack($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $envioId = $req->input('id');

        if (!$envioId) {
            return Response::error(400, 'id requerido');
        }

        $recibidoEn = $req->input('recibidoEn');
        $recibidoEn = is_numeric($recibidoEn) ? (int) $recibidoEn : null;

        $latencia = $req->input('latenciaMs');
        $latencia = is_numeric($latencia) ? (int) $latencia : null;

        // Sólo avanza de 'enviado' a 'confirmado': un ack repetido no pisa la
        // latencia ya registrada ni resucita un envío fallido.
        $stmt = $db->prepare("
            UPDATE push_deliveries
            SET estado = 'confirmado',
                recibido_en = COALESCE(recibido_en, ?),
                latencia_ms = COALESCE(latencia_ms, ?)
            WHERE envio_id = ? AND estado = 'enviado'
        ");
        $stmt->execute([$recibidoEn, $latencia, $envioId]);

        return Response::ok(['success' => true]);
    }

    // ------------------------------------------------------------- diagnóstico

    /** Métricas de entrega segmentadas por plataforma (guía §7). */
    public static function metricas($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $stmt = $db->prepare("
            SELECT
                COALESCE(platform, 'desconocida') AS plataforma,
                COUNT(*) AS total,
                SUM(estado = 'enviado' OR estado = 'confirmado') AS enviadas,
                SUM(estado = 'confirmado') AS confirmadas,
                SUM(estado = 'fallido')    AS fallidas,
                ROUND(AVG(NULLIF(latencia_ms, 0))) AS latencia_media_ms
            FROM push_deliveries
            GROUP BY COALESCE(platform, 'desconocida')
        ");
        $stmt->execute();
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filas as &$f) {
            $enviadas = (int) $f['enviadas'];
            $f['total']             = (int) $f['total'];
            $f['enviadas']          = $enviadas;
            $f['confirmadas']       = (int) $f['confirmadas'];
            $f['fallidas']          = (int) $f['fallidas'];
            $f['latencia_media_ms'] = $f['latencia_media_ms'] === null ? null : (int) $f['latencia_media_ms'];
            // Un 100% global puede esconder un 0% en iOS: por eso se segmenta.
            $f['tasa_entrega']      = $enviadas > 0 ? round($f['confirmadas'] / $enviadas, 4) : null;
        }
        unset($f);

        return Response::ok(['plataformas' => $filas]);
    }
}
