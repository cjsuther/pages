<?php

/**
 * Generación de notificaciones y cola de envío push.
 *
 * Dos garantías de "una sola vez", ambas sostenidas por la base y no por
 * comprobaciones previas (que son carreras esperando a pasar):
 *
 *   1. notifications.dedupe_key es único: un evento genera como mucho una
 *      notificación por usuario, corra el cron las veces que corra y se edite
 *      el evento las veces que se edite.
 *   2. push_deliveries tiene único (notification_id, subscription_id): una
 *      notificación se envía como mucho una vez a cada dispositivo.
 */
class Notificador
{
    const TIPO_EVENTO = 'new_event';

    /** Cuántos envíos procesa cada corrida del cron. */
    const LOTE = 200;

    /** Clave de deduplicación de un aviso de evento nuevo. */
    public static function claveEvento($linkId, $userId)
    {
        return 'evento:' . (int) $linkId . ':' . (int) $userId;
    }

    // ------------------------------------------------- generación (una vez)

    /**
     * Avisa a los seguidores de la página de que se publicó un evento.
     *
     * Se llama al crear el evento, para que el aviso salga en el momento y no
     * al día siguiente. Es idempotente: volver a llamarla no duplica nada.
     *
     * @return int Cuántas notificaciones nuevas se crearon.
     */
    public static function avisarEventoNuevo($db, $linkId)
    {
        $evento = self::datosDelEvento($db, $linkId);

        if (!$evento) {
            return 0;
        }

        $stmt = $db->prepare('
            SELECT pf.user_id, pf.notify_all_events, pf.max_distance_km,
                   u.location_latitude, u.location_longitude
            FROM page_followers pf
            INNER JOIN users u ON u.id = pf.user_id
            WHERE pf.page_id = ?
        ');
        $stmt->execute([$evento['page_id']]);
        $seguidores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $creadas = 0;

        foreach ($seguidores as $seguidor) {
            // El dueño de la página no se notifica a sí mismo.
            $duenoId = isset($evento['owner_id']) ? (int) $evento['owner_id'] : null;

            if ($duenoId !== null && (int) $seguidor['user_id'] === $duenoId) {
                continue;
            }

            if (!NotificationsHandler::debeNotificar($evento, $seguidor)) {
                continue;
            }

            $creadas += self::crearUnaVez($db, [
                'user_id'    => $seguidor['user_id'],
                'page_id'    => $evento['page_id'],
                'link_id'    => $evento['id'],
                'title'      => NotificationsHandler::tituloDeAviso($evento),
                'message'    => NotificationsHandler::mensajeDeAviso($evento),
                'type'       => self::TIPO_EVENTO,
                'dedupe_key' => self::claveEvento($evento['id'], $seguidor['user_id']),
            ]);
        }

        return $creadas;
    }

    /**
     * Inserta la notificación si su clave de deduplicación no existe.
     *
     * INSERT IGNORE y no "consultar y después insertar": entre la consulta y
     * la inserción pueden entrar dos procesos a la vez (el cron y el alta del
     * evento, por ejemplo). El índice único es lo único que lo garantiza.
     *
     * @return int 1 si la creó, 0 si ya existía.
     */
    public static function crearUnaVez($db, array $datos)
    {
        $stmt = $db->prepare('
            INSERT IGNORE INTO notifications
                (user_id, page_id, link_id, title, message, type, dedupe_key, is_read)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0)
        ');
        $stmt->execute([
            $datos['user_id'],
            $datos['page_id'],
            $datos['link_id'],
            $datos['title'],
            $datos['message'],
            $datos['type'],
            $datos['dedupe_key'],
        ]);

        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    /** Datos del evento con los de su página; null si el link no es un evento. */
    private static function datosDelEvento($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT l.id, l.text AS title, l.event_date, l.event_latitude, l.event_longitude,
                   lg.page_id, p.title AS page_title, p.url_slug, p.user_id AS owner_id
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            WHERE l.id = ? AND lg.type = "eventos"
        ');
        $stmt->execute([$linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    // ----------------------------------------------------- cola de envío push

    /**
     * Crea las filas de envío que falten para las notificaciones sin despachar.
     *
     * Una fila por (notificación, dispositivo). El índice único hace que
     * llamarla dos veces no encole nada de más.
     *
     * @return int Envíos encolados.
     */
    public static function encolarPendientes($db, $horas = 48)
    {
        // En dos pasos y no con un INSERT ... SELECT: el identificador de
        // envío se genera en PHP con random_bytes en lugar de depender de
        // funciones de SQL, que varían entre versiones de MySQL y MariaDB.
        $stmt = $db->prepare('
            SELECT n.id AS notification_id, s.id AS subscription_id, s.platform
            FROM notifications n
            INNER JOIN push_subscriptions s ON s.user_id = n.user_id
            LEFT JOIN push_deliveries d
                   ON d.notification_id = n.id AND d.subscription_id = s.id
            WHERE n.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
              AND d.id IS NULL
        ');
        $stmt->execute([$horas]);
        $pares = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pares)) {
            return 0;
        }

        // INSERT IGNORE igual: entre el SELECT y el INSERT puede entrar otra
        // corrida del cron. El índice único es lo que garantiza el "una vez".
        $insert = $db->prepare("
            INSERT IGNORE INTO push_deliveries
                (notification_id, subscription_id, envio_id, estado, platform)
            VALUES (?, ?, ?, 'pendiente', ?)
        ");

        $encolados = 0;

        foreach ($pares as $par) {
            $insert->execute([
                $par['notification_id'],
                $par['subscription_id'],
                bin2hex(random_bytes(5)), // 10 caracteres, como la columna
                $par['platform'],
            ]);
            $encolados += $insert->rowCount() > 0 ? 1 : 0;
        }

        return $encolados;
    }

    /**
     * Envía los push pendientes.
     *
     * @param PushSender $sender
     * @return array Resumen: enviados, fallidos, suscripciones borradas.
     */
    public static function procesarCola($db, $sender, $limite = self::LOTE)
    {
        $stmt = $db->prepare("
            SELECT d.id, d.envio_id, d.notification_id,
                   s.id AS subscription_id, s.endpoint, s.p256dh_key, s.auth_key, s.platform,
                   n.title, n.message, n.page_id, n.link_id,
                   p.url_slug
            FROM push_deliveries d
            INNER JOIN push_subscriptions s ON s.id = d.subscription_id
            INNER JOIN notifications n ON n.id = d.notification_id
            LEFT JOIN pages p ON p.id = n.page_id
            WHERE d.estado = 'pendiente' AND d.intentos < 3
            ORDER BY d.id
            LIMIT " . (int) $limite
        );
        $stmt->execute();
        $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pendientes)) {
            return ['enviados' => 0, 'fallidos' => 0, 'expiradas' => 0, 'total' => 0];
        }

        $enviadoEn = (int) round(microtime(true) * 1000);
        $porEndpoint = [];

        $marcarEnviado = $db->prepare("UPDATE push_deliveries SET estado='enviado', enviado_en=?, intentos=intentos+1 WHERE id=?");
        $marcarFallido = $db->prepare("UPDATE push_deliveries SET estado='fallido', intentos=intentos+1, error=? WHERE id=?");
        $marcarExpirado = $db->prepare("UPDATE push_deliveries SET estado='expirado', intentos=intentos+1, error=? WHERE id=?");
        $borrarSuscripcion = $db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');

        $resumen = ['enviados' => 0, 'fallidos' => 0, 'expiradas' => 0, 'total' => count($pendientes)];

        foreach ($pendientes as $envio) {
            // Una clave corrupta hace explotar el cifrado de la tanda entera,
            // no sólo el de su propio envío: nada se manda, todo queda
            // pendiente con intentos=0 y el cron vuelve a romper en cada
            // corrida. Se descarta acá, antes de mezclarla con las sanas.
            if (!PushSender::claveUtilizable($envio['p256dh_key'])) {
                $marcarFallido->execute(['clave de suscripción inválida', $envio['id']]);
                $resumen['fallidos']++;
                continue;
            }

            // Red de contención por si la librería rechaza algo que el chequeo
            // de arriba dio por bueno.
            try {
                $sender->encolar($envio, [
                    'titulo'    => $envio['title'],
                    'cuerpo'    => $envio['message'],
                    'id'        => $envio['envio_id'],
                    'enviadoEn' => $enviadoEn,
                    'url'       => self::urlDestino($envio),
                    'tag'       => 'evento-' . (int) $envio['link_id'],
                ]);
            } catch (Throwable $e) {
                $marcarFallido->execute([substr($e->getMessage(), 0, 255), $envio['id']]);
                $resumen['fallidos']++;
                continue;
            }

            $porEndpoint[$envio['endpoint']] = $envio;
        }

        // Si ninguna suscripción resultó utilizable no hay nada que mandar, y
        // flush() sobre una cola vacía no aporta.
        if (empty($porEndpoint)) {
            return $resumen;
        }

        foreach ($sender->enviar() as $reporte) {
            $envio = self::buscarPorEndpoint($porEndpoint, $reporte['endpoint']);

            if ($envio === null) {
                continue;
            }

            if ($reporte['exito']) {
                $marcarEnviado->execute([$enviadoEn, $envio['id']]);
                $resumen['enviados']++;
                continue;
            }

            // 404 o 410: el dispositivo ya no existe. Se borra para no seguir
            // intentando eternamente (guía §9).
            if ($reporte['expirada']) {
                $marcarExpirado->execute([$reporte['motivo'], $envio['id']]);
                $borrarSuscripcion->execute([$envio['endpoint']]);
                $resumen['expiradas']++;
                continue;
            }

            $marcarFallido->execute([$reporte['motivo'], $envio['id']]);
            $resumen['fallidos']++;
        }

        return $resumen;
    }

    /** El reporte devuelve la URI completa; el endpoint guardado puede diferir en la query. */
    private static function buscarPorEndpoint(array $porEndpoint, $uri)
    {
        if (isset($porEndpoint[$uri])) {
            return $porEndpoint[$uri];
        }

        foreach ($porEndpoint as $endpoint => $envio) {
            if (strpos((string) $uri, $endpoint) !== false || strpos($endpoint, (string) $uri) !== false) {
                return $envio;
            }
        }

        return null;
    }

    /** Adónde lleva la notificación al tocarla. */
    private static function urlDestino(array $envio)
    {
        if (!empty($envio['link_id'])) {
            return '/evento/' . (int) $envio['link_id'];
        }

        if (!empty($envio['url_slug'])) {
            return '/' . $envio['url_slug'];
        }

        return '/';
    }
}
