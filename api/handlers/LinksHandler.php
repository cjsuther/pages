<?php

/**
 * Links: alta (index) y edición/borrado (detail).
 *
 * Extraído de api/links/index.php y api/links/detail.php sin cambios de
 * comportamiento: mismos códigos de estado, mismos mensajes y mismo orden de
 * validaciones (el orden importa: detail valida el id antes que la sesión).
 */
class LinksHandler
{
    /**
     * Campos que acepta la edición.
     *
     * 'nullable' => true replica array_key_exists(): permite enviar null o ''
     * para vaciar el campo. Los demás usan isset(), así que un null se ignora.
     */
    private static $updatableFields = [
        'url' => ['nullable' => false],
        'url_text' => ['nullable' => true],
        'text' => ['nullable' => false],
        'image_url' => ['nullable' => true],
        'description' => ['nullable' => false],
        'position' => ['nullable' => false],
        'event_date' => ['nullable' => false],
        'event_time' => ['nullable' => false],
        'event_address' => ['nullable' => false],
        'event_latitude' => ['nullable' => false],
        'event_longitude' => ['nullable' => false],
        'event_maps_url' => ['nullable' => false],
    ];

    const ERROR_SIN_COORDENADAS = 'Los eventos deben tener coordenadas. Por favor selecciona una dirección de Google Maps.';

    // ------------------------------------------------------------------ index

    public static function index($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->missing(['group_id', 'url', 'text'])) {
            return Response::error(400, 'Group ID, URL, and text are required');
        }

        try {
            if (!PageAccess::canManageGroup($db, $req->input('group_id'), $req->userId())) {
                return Response::notFound('Group not found');
            }

            $stmt = $db->prepare('SELECT id, type FROM link_groups WHERE id = ?');
            $stmt->execute([$req->input('group_id')]);
            $group = $stmt->fetch();

            if ($group && $group['type'] === 'eventos') {
                $lat = $req->input('event_latitude');
                $lng = $req->input('event_longitude');

                if (empty($lat) || empty($lng)) {
                    return Response::error(400, self::ERROR_SIN_COORDENADAS);
                }
            }

            $stmt = $db->prepare('INSERT INTO links (group_id, url, url_text, text, image_url, description, position, event_date, event_time, event_address, event_latitude, event_longitude, event_maps_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $req->input('group_id'),
                $req->input('url'),
                self::valorONull($req->input('url_text')),
                $req->input('text'),
                $req->input('image_url'),
                $req->input('description'),
                $req->input('position', 0),
                $req->input('event_date'),
                $req->input('event_time'),
                $req->input('event_address'),
                $req->input('event_latitude'),
                $req->input('event_longitude'),
                $req->input('event_maps_url'),
            ]);

            $linkId = $db->lastInsertId();

            // Si el grupo es de eventos, se avisa a los seguidores en el acto.
            // Notificador es idempotente, así que el cron diario puede volver a
            // pasar por el mismo evento sin duplicar nada.
            if ($group && $group['type'] === 'eventos') {
                try {
                    Notificador::avisarEventoNuevo($db, $linkId);
                } catch (Exception $e) {
                    // Que falle el aviso no puede impedir que se cree el evento.
                    error_log('No se pudo notificar el evento ' . $linkId . ': ' . $e->getMessage());
                }
            }

            $stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
            $stmt->execute([$linkId]);
            $link = $stmt->fetch();

            return Response::created(['link' => $link]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ----------------------------------------------------------------- detail

    public static function detail($db, Request $req)
    {
        $linkId = (int) $req->param('id');

        if (!$linkId) {
            return Response::error(400, 'Link ID is required');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'PUT') {
            return self::update($db, $req, $linkId);
        }

        if ($req->method === 'DELETE') {
            return self::destroy($db, $req, $linkId);
        }

        return Response::methodNotAllowed();
    }

    private static function update($db, Request $req, $linkId)
    {
        try {
            if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
                return Response::notFound('Link not found');
            }

            $stmt = $db->prepare('
                SELECT l.id, lg.type
                FROM links l
                JOIN link_groups lg ON l.group_id = lg.id
                WHERE l.id = ?
            ');
            $stmt->execute([$linkId]);
            $linkData = $stmt->fetch();

            if ($linkData && $linkData['type'] === 'eventos') {
                $error = self::validarCoordenadasDeEvento($db, $req, $linkId);
                if ($error !== null) {
                    return $error;
                }
            }

            list($fields, $values) = self::camposAActualizar($req);

            if (empty($fields)) {
                return Response::error(400, 'No fields to update');
            }

            $values[] = $linkId;
            $stmt = $db->prepare('UPDATE links SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($values);

            $stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
            $stmt->execute([$linkId]);
            $link = $stmt->fetch();

            return Response::ok(['link' => $link]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function destroy($db, Request $req, $linkId)
    {
        try {
            if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
                return Response::notFound('Link not found');
            }

            $stmt = $db->prepare('DELETE FROM links WHERE id = ?');
            $stmt->execute([$linkId]);

            return Response::ok(['message' => 'Link deleted successfully']);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    /**
     * Un evento no puede quedar sin coordenadas: se combinan las que llegan en
     * la petición con las ya guardadas y se valida el resultado final.
     */
    private static function validarCoordenadasDeEvento($db, Request $req, $linkId)
    {
        $stmt = $db->prepare('SELECT event_latitude, event_longitude FROM links WHERE id = ?');
        $stmt->execute([$linkId]);
        $actual = $stmt->fetch();
        $actual = $actual === false ? [] : $actual;

        $lat = $req->has('event_latitude')
            ? $req->input('event_latitude')
            : (isset($actual['event_latitude']) ? $actual['event_latitude'] : null);

        $lng = $req->has('event_longitude')
            ? $req->input('event_longitude')
            : (isset($actual['event_longitude']) ? $actual['event_longitude'] : null);

        if (empty($lat) || empty($lng)) {
            return Response::error(400, self::ERROR_SIN_COORDENADAS);
        }

        return null;
    }

    /** @return array{0: string[], 1: array} Pares "campo = ?" y sus valores. */
    private static function camposAActualizar(Request $req)
    {
        $fields = [];
        $values = [];

        foreach (self::$updatableFields as $campo => $opciones) {
            if ($opciones['nullable']) {
                if (!array_key_exists($campo, $req->body)) {
                    continue;
                }
                $fields[] = $campo . ' = ?';
                $values[] = self::valorONull($req->body[$campo]);
                continue;
            }

            if (!$req->has($campo)) {
                continue;
            }
            $fields[] = $campo . ' = ?';
            $values[] = $req->body[$campo];
        }

        return [$fields, $values];
    }

    /** '' y null se guardan como NULL, para no dejar cadenas vacías en la base. */
    private static function valorONull($valor)
    {
        return ($valor === '' || $valor === null) ? null : $valor;
    }
}
