<?php

/**
 * Endpoints públicos (sin sesión): la página pública, eventos, buscador,
 * listados recientes y seguidores.
 *
 * Extraído de api/public/*.php sin cambios de comportamiento.
 */
class PublicHandler
{
    const MIN_LONGITUD_BUSQUEDA = 2;
    const LIMITE_PAGINAS_RECIENTES = 12;
    const LIMITE_EVENTOS_RECIENTES = 30;
    const LIMITE_RESULTADOS_BUSQUEDA = 10;
    const DIAS_RANGO_EVENTOS = 30;

    // ------------------------------------------------------------------- page

    /** Página pública completa, tal como la consume el frontend y el index.php de OG tags. */
    public static function page($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $slug = trim((string) $req->param('slug'));

        if ($slug === '') {
            return Response::error(400, 'Slug is required');
        }

        try {
            $stmt = $db->prepare('SELECT * FROM pages WHERE url_slug = ?');
            $stmt->execute([$slug]);
            $page = $stmt->fetch();

            if (!$page) {
                return Response::notFound('Page not found');
            }

            $stmt = $db->prepare('SELECT * FROM link_groups WHERE page_id = ? ORDER BY position, id');
            $stmt->execute([$page['id']]);
            $groups = $stmt->fetchAll();

            foreach ($groups as &$group) {
                if ($group['type'] == 'eventos') {
                    $group['links'] = self::eventosVigentes($db, $group['id']);
                    $group['collaborated_events'] = self::eventosColaborados($db, $group['id']);
                } else {
                    $stmt = $db->prepare("
                        SELECT *, (event_date IS NOT NULL AND event_date < ?) as event_due
                        FROM links WHERE group_id = ? ORDER BY position, id
                    ");
                    $stmt->execute([Fechas::hoy(), $group['id']]);
                    $group['links'] = $stmt->fetchAll();
                }
            }
            unset($group);

            $page['groups'] = $groups;
            $page['socials'] = Redes::deLaPagina($db, $page['id']);

            $stmt = $db->prepare('SELECT COUNT(*) as count FROM page_followers WHERE page_id = ?');
            $stmt->execute([$page['id']]);
            $fila = $stmt->fetch();
            $page['follower_count'] = (int) (isset($fila['count']) ? $fila['count'] : 0);

            return Response::ok(['page' => $page]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    /** La página pública sólo muestra eventos futuros. */
    private static function eventosVigentes($db, $groupId)
    {
        // >= y no >: un evento de hoy a las 20:30 todavía no pasó. Con > la
        // página se vaciaba el mismo día del show.
        $hoy = Fechas::hoy();

        $stmt = $db->prepare("
            SELECT *, (event_date IS NOT NULL AND event_date < ?) as event_due
            FROM links
            WHERE group_id = ? AND event_date >= ?
            ORDER BY event_date, id
        ");
        $stmt->execute([$hoy, $groupId, $hoy]);
        $links = $stmt->fetchAll();

        foreach ($links as &$link) {
            $link['collaborators'] = self::colaboradoresDe($db, $link['id']);
            // Si el evento vende entradas, el modal usa la compra interna en
            // lugar del link que tenga cargado.
            $link['entradas'] = Entradas::disponibilidad($db, $link['id']);
        }
        unset($link);

        return $links;
    }

    private static function eventosColaborados($db, $groupId)
    {
        $stmt = $db->prepare("
            SELECT l.*, 1 as is_collaborated, ec.id as collaboration_id,
                rp.id as source_page_id, rp.title as source_page_title,
                rp.url_slug as source_page_slug, rp.profile_image as source_page_image,
                (l.event_date IS NOT NULL AND l.event_date < ?) as event_due
            FROM event_collaborations ec
            JOIN links l ON ec.link_id = l.id
            JOIN pages rp ON ec.requester_page_id = rp.id
            WHERE ec.collaborator_group_id = ? AND ec.status = 'accepted'
                AND (l.event_date IS NULL OR l.event_date >= ?)
            ORDER BY l.event_date, l.id
        ");
        $hoy = Fechas::hoy();
        $stmt->execute([$hoy, $groupId, $hoy]);
        $colaborados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($colaborados as &$evento) {
            $evento['collaborators'] = self::colaboradoresDe($db, $evento['id']);
        }
        unset($evento);

        return $colaborados;
    }

    private static function colaboradoresDe($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT p.id as page_id, p.title as page_title, p.url_slug as page_slug, p.profile_image as page_image
            FROM event_collaborations ec
            JOIN pages p ON ec.collaborator_page_id = p.id
            WHERE ec.link_id = ? AND ec.status = "accepted"
        ');
        $stmt->execute([$linkId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------ event

    public static function event($db, Request $req)
    {
        $eventId = (int) $req->param('id');

        if (!$eventId) {
            return Response::error(400, 'ID de evento requerido');
        }

        $stmt = $db->prepare('
            SELECT
                l.id,
                l.text,
                l.description,
                l.image_url,
                l.url,
                l.url_text,
                l.event_date,
                l.event_time,
                l.event_address,
                l.event_latitude,
                l.event_longitude,
                l.event_maps_url,
                p.id as page_id,
                p.title as page_title,
                p.url_slug as page_slug,
                p.profile_image as page_image
            FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            JOIN pages p ON lg.page_id = p.id
            WHERE l.id = ? AND lg.type = \'eventos\'
        ');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$event) {
            return Response::notFound('Evento no encontrado');
        }

        $event['entradas'] = Entradas::disponibilidad($db, $event['id']);

        return Response::ok(['event' => $event]);
    }

    // ----------------------------------------------------------------- events

    /** Eventos en un rango de fechas; por defecto los próximos 30 días. */
    public static function events($db, Request $req)
    {
        list($inicioPorDefecto, $finPorDefecto) = self::rangoPorDefecto();

        $start = $req->param('start', $inicioPorDefecto);
        $end = $req->param('end', $finPorDefecto);

        $stmt = $db->prepare('
          SELECT
            l.id,
            l.text as title,
            l.description,
            l.event_date,
            l.event_time,
            l.event_address as location,
            p.url_slug as slug,
            p.title as page_title,
            u.name as owner_name,
            u.email as owner_email
          FROM links l
          JOIN link_groups lg ON l.group_id = lg.id
          JOIN pages p ON lg.page_id = p.id
          JOIN users u ON p.user_id = u.id
          WHERE lg.type = "eventos"
          AND l.event_date IS NOT NULL
          AND l.event_date BETWEEN ? AND ?
          ORDER BY l.event_date ASC, l.event_time ASC
        ');
        $stmt->execute([$start, $end]);

        return Response::ok(['events' => $stmt->fetchAll()]);
    }

    /** @return array{0: string, 1: string} Fechas ISO de inicio y fin del rango por defecto. */
    public static function rangoPorDefecto($hoy = null)
    {
        $hoy = $hoy === null ? time() : $hoy;

        return [
            date('Y-m-d', $hoy),
            date('Y-m-d', strtotime('+' . self::DIAS_RANGO_EVENTOS . ' days', $hoy)),
        ];
    }

    // -------------------------------------------------------------- followers

    public static function followers($db, Request $req)
    {
        $pageId = (int) $req->param('page_id');

        if (!$pageId) {
            return Response::error(400, 'ID de página requerido');
        }

        $stmt = $db->prepare('SELECT id FROM pages WHERE id = ?');
        $stmt->execute([$pageId]);

        if (!$stmt->fetch()) {
            return Response::notFound('Página no encontrada');
        }

        $stmt = $db->prepare('
            SELECT
                u.email,
                (SELECT title FROM pages WHERE user_id = u.id ORDER BY id ASC LIMIT 1) as page_title,
                (SELECT url_slug FROM pages WHERE user_id = u.id ORDER BY id ASC LIMIT 1) as page_slug,
                pf.created_at as followed_at
            FROM page_followers pf
            JOIN users u ON pf.user_id = u.id
            WHERE pf.page_id = ?
            ORDER BY pf.created_at DESC
        ');
        $stmt->execute([$pageId]);

        return Response::ok(['followers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ---------------------------------------------------------- recent-pages

    public static function recentPages($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        try {
            $stmt = $db->prepare('
                SELECT p.*, u.name as owner_name, u.email as owner_email,
                       (SELECT COUNT(*) FROM page_followers pf WHERE pf.page_id = p.id) as follower_count
                FROM pages p
                JOIN users u ON p.user_id = u.id
                ORDER BY p.created_at DESC
                LIMIT ' . self::LIMITE_PAGINAS_RECIENTES . '
            ');
            $stmt->execute();

            return Response::ok(['pages' => $stmt->fetchAll()]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // --------------------------------------------------------- recent-events

    public static function recentEvents($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        try {
            $stmt = $db->prepare("
                SELECT l.*, lg.page_id, p.url_slug as page_slug, p.title as page_title,
                       p.profile_image as page_image, u.name as owner_name, u.email as owner_email
                FROM links l
                JOIN link_groups lg ON l.group_id = lg.id
                JOIN pages p ON lg.page_id = p.id
                JOIN users u ON p.user_id = u.id
                WHERE lg.type = 'eventos'
                  AND l.event_date IS NOT NULL
                  AND l.event_date >= '" . Fechas::hoy() . "'
                ORDER BY l.event_date ASC, l.event_time ASC
                LIMIT " . self::LIMITE_EVENTOS_RECIENTES . "
            ");
            $stmt->execute();

            return Response::ok(['events' => $stmt->fetchAll()]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ----------------------------------------------------------------- search

    /** Busca páginas y eventos futuros; devuelve ambos tipos en una sola lista. */
    public static function search($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $query = trim((string) $req->param('q', ''));

        // Con menos de dos caracteres el LIKE devolvería casi todo el catálogo.
        if (strlen($query) < self::MIN_LONGITUD_BUSQUEDA) {
            return Response::ok(['results' => []]);
        }

        try {
            $termino = '%' . $query . '%';
            $results = [];

            $stmt = $db->prepare('
                SELECT p.id, p.title, p.description, p.url_slug as slug, p.profile_image,
                       (SELECT COUNT(*) FROM page_followers pf WHERE pf.page_id = p.id) as follower_count
                FROM pages p
                WHERE p.title LIKE ? OR p.description LIKE ?
                ORDER BY p.created_at DESC
                LIMIT ' . self::LIMITE_RESULTADOS_BUSQUEDA . '
            ');
            $stmt->execute([$termino, $termino]);

            foreach ($stmt->fetchAll() as $page) {
                $page['type'] = 'page';
                $results[] = $page;
            }

            $stmt = $db->prepare("
                SELECT l.id, l.text as title, l.description, l.image_url, l.event_date as item_date,
                       l.event_time, l.event_address, p.url_slug as slug
                FROM links l
                JOIN link_groups lg ON l.group_id = lg.id
                JOIN pages p ON lg.page_id = p.id
                WHERE lg.type = 'eventos'
                  AND l.event_date >= '" . Fechas::hoy() . "'
                  AND (l.text LIKE ? OR l.description LIKE ? OR l.event_address LIKE ?)
                ORDER BY l.event_date ASC
                LIMIT " . self::LIMITE_RESULTADOS_BUSQUEDA . "
            ");
            $stmt->execute([$termino, $termino, $termino]);

            foreach ($stmt->fetchAll() as $event) {
                $event['type'] = 'event';
                $results[] = $event;
            }

            return Response::ok(['results' => $results]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }
}
