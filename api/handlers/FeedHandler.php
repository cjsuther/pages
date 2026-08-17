<?php

/**
 * Feed de eventos de las páginas que sigue el usuario.
 *
 * Extraído de api/pages/feed-events.php. El filtrado por distancia y el
 * ordenamiento se hacen en PHP (no en SQL), así que son la parte más
 * interesante de testear.
 */
class FeedHandler
{
    private static $ordenesValidos = ['date', 'distance'];
    private static $direccionesValidas = ['asc', 'desc'];

    public static function events($db, Request $req)
    {
        if (!$req->hasBearerToken()) {
            return Response::unauthorized('Token no proporcionado');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'GET') {
            return Response::error(405, 'Método no permitido');
        }

        $sortBy = self::valorPermitido($req->param('sortBy'), self::$ordenesValidos, 'date');
        $sortOrder = self::valorPermitido($req->param('sortOrder'), self::$direccionesValidas, 'asc');

        $stmt = $db->prepare('
            SELECT
                l.id,
                l.url,
                l.text,
                l.image_url,
                l.description,
                l.event_date,
                l.event_time,
                l.event_address,
                l.event_latitude,
                l.event_longitude,
                l.event_maps_url,
                1 as is_event,
                p.id as page_id,
                p.title as page_title,
                p.url_slug as page_slug,
                p.profile_image as page_image,
                pf.notify_all_events,
                pf.max_distance_km,
                u.location_latitude as user_latitude,
                u.location_longitude as user_longitude
            FROM page_followers pf
            INNER JOIN pages p ON pf.page_id = p.id
            INNER JOIN link_groups g ON g.page_id = p.id
            INNER JOIN links l ON l.group_id = g.id
            LEFT JOIN users u ON u.id = ?
            WHERE pf.user_id = ?
            AND g.type = "eventos"
            AND l.event_date IS NOT NULL
            AND l.event_date >= ?
        ');
        // Un evento que ya pasó no es agenda: es historia. El resto de las
        // vistas públicas ya cortaba por hoy y ésta no, así que el feed de
        // quien seguía páginas se iba llenando de shows vencidos.
        $stmt->execute([$req->userId(), $req->userId(), Fechas::hoy()]);

        $eventos = self::filtrar($stmt->fetchAll(PDO::FETCH_ASSOC));
        $eventos = self::ordenar($eventos, $sortBy, $sortOrder);

        return Response::ok([
            'events' => $eventos,
            'total' => count($eventos),
        ]);
    }

    /**
     * Deja pasar los eventos de páginas con "notificar todo" y, para el resto,
     * sólo los que caen dentro del radio configurado por el usuario.
     */
    public static function filtrar(array $eventos)
    {
        $resultado = [];

        foreach ($eventos as $evento) {
            $distancia = self::distanciaDe($evento);

            if ($evento['notify_all_events']) {
                $incluir = true;
            } else {
                $incluir = $distancia !== null
                    && $evento['max_distance_km']
                    && $distancia <= $evento['max_distance_km'];
            }

            if (!$incluir) {
                continue;
            }

            // Los campos de preferencias son internos: no se exponen al cliente.
            unset($evento['notify_all_events'], $evento['max_distance_km']);
            unset($evento['user_latitude'], $evento['user_longitude']);

            $evento['id'] = (int) $evento['id'];
            $evento['page_id'] = (int) $evento['page_id'];
            $evento['is_event'] = (bool) $evento['is_event'];
            $evento['distance'] = $distancia;

            if ($evento['event_latitude']) {
                $evento['event_latitude'] = (float) $evento['event_latitude'];
            }
            if ($evento['event_longitude']) {
                $evento['event_longitude'] = (float) $evento['event_longitude'];
            }

            $resultado[] = $evento;
        }

        return $resultado;
    }

    /** Distancia usuario-evento, o null si falta alguna de las cuatro coordenadas. */
    private static function distanciaDe(array $evento)
    {
        $tieneCoordenadas = $evento['user_latitude'] && $evento['user_longitude']
            && $evento['event_latitude'] && $evento['event_longitude'];

        if (!$tieneCoordenadas) {
            return null;
        }

        return Geo::distanceKm(
            $evento['user_latitude'],
            $evento['user_longitude'],
            $evento['event_latitude'],
            $evento['event_longitude']
        );
    }

    /** Los eventos sin distancia conocida van al final al ordenar por cercanía. */
    public static function ordenar(array $eventos, $sortBy, $sortOrder)
    {
        if ($sortBy === 'distance') {
            usort($eventos, function ($a, $b) use ($sortOrder) {
                $da = $a['distance'] !== null ? $a['distance'] : PHP_FLOAT_MAX;
                $db = $b['distance'] !== null ? $b['distance'] : PHP_FLOAT_MAX;

                return $sortOrder === 'asc' ? $da <=> $db : $db <=> $da;
            });

            return $eventos;
        }

        usort($eventos, function ($a, $b) use ($sortOrder) {
            $fa = $a['event_date'] . ' ' . (isset($a['event_time']) ? $a['event_time'] : '00:00:00');
            $fb = $b['event_date'] . ' ' . (isset($b['event_time']) ? $b['event_time'] : '00:00:00');

            return $sortOrder === 'asc' ? strcmp($fa, $fb) : strcmp($fb, $fa);
        });

        return $eventos;
    }

    private static function valorPermitido($valor, array $permitidos, $porDefecto)
    {
        return in_array($valor, $permitidos, true) ? $valor : $porDefecto;
    }
}
