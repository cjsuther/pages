<?php

/**
 * Colaboraciones entre páginas sobre un evento: invitar, aceptar/rechazar y
 * eliminar.
 *
 * Extraído de api/collaborations/index.php y api/collaborations/detail.php sin
 * cambios de comportamiento.
 */
class CollaborationsHandler
{
    private static $estadosValidos = ['accepted', 'rejected'];

    // ------------------------------------------------------------------ index

    public static function index($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'GET') {
            return self::listar($db, $req);
        }

        if ($req->method === 'POST') {
            return self::invitar($db, $req);
        }

        return Response::methodNotAllowed();
    }

    private static function listar($db, Request $req)
    {
        $linkId = (int) $req->param('link_id');
        $type = $req->param('type');

        try {
            if ($linkId) {
                return self::deUnEvento($db, $req, $linkId);
            }

            if ($type === 'pending') {
                return self::pendientesDeMisPaginas($db, $req);
            }

            return Response::error(400, 'Missing parameters: link_id or type=pending required');

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function deUnEvento($db, Request $req, $linkId)
    {
        // Sólo el dueño de la página del evento ve sus colaboraciones.
        $stmt = $db->prepare('
            SELECT l.id FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            JOIN pages p ON lg.page_id = p.id
            WHERE l.id = ? AND p.user_id = ?
        ');
        $stmt->execute([$linkId, $req->userId()]);

        if (!$stmt->fetch()) {
            return Response::error(403, 'Forbidden');
        }

        $stmt = $db->prepare('
            SELECT ec.id, ec.status, ec.collaborator_page_id,
                p.title as page_title, p.url_slug as page_slug, p.profile_image as page_image
            FROM event_collaborations ec
            JOIN pages p ON ec.collaborator_page_id = p.id
            WHERE ec.link_id = ?
            ORDER BY ec.created_at
        ');
        $stmt->execute([$linkId]);

        return Response::ok(['collaborations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private static function pendientesDeMisPaginas($db, Request $req)
    {
        $stmt = $db->prepare('
            SELECT ec.id, ec.status, ec.link_id, ec.requester_page_id, ec.collaborator_page_id,
                l.text as event_title, l.event_date, l.event_time, l.image_url as event_image,
                rp.title as requester_page_title, rp.url_slug as requester_page_slug, rp.profile_image as requester_page_image,
                cp.title as collaborator_page_title, cp.id as collaborator_page_id
            FROM event_collaborations ec
            JOIN links l ON ec.link_id = l.id
            JOIN pages rp ON ec.requester_page_id = rp.id
            JOIN pages cp ON ec.collaborator_page_id = cp.id
            WHERE cp.user_id = ? AND ec.status = "pending"
            ORDER BY ec.created_at DESC
        ');
        $stmt->execute([$req->userId()]);

        return Response::ok(['pending' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private static function invitar($db, Request $req)
    {
        $linkId = (int) $req->input('link_id', 0);
        $collaboratorPageId = (int) $req->input('collaborator_page_id', 0);

        if (!$linkId || !$collaboratorPageId) {
            return Response::error(400, 'link_id and collaborator_page_id are required');
        }

        try {
            $stmt = $db->prepare('
                SELECT l.id, l.text as event_title, p.id as page_id, p.title as page_title
                FROM links l
                JOIN link_groups lg ON l.group_id = lg.id
                JOIN pages p ON lg.page_id = p.id
                WHERE l.id = ? AND p.user_id = ? AND lg.type = "eventos"
            ');
            $stmt->execute([$linkId, $req->userId()]);
            $evento = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$evento) {
                return Response::notFound('Event not found or not authorized');
            }

            $stmt = $db->prepare('SELECT id, user_id FROM pages WHERE id = ?');
            $stmt->execute([$collaboratorPageId]);
            $paginaColaboradora = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$paginaColaboradora) {
                return Response::notFound('Collaborator page not found');
            }

            if ($collaboratorPageId == $evento['page_id']) {
                return Response::error(400, 'No puedes invitar tu propia página');
            }

            $stmt = $db->prepare('SELECT id FROM event_collaborations WHERE link_id = ? AND collaborator_page_id = ?');
            $stmt->execute([$linkId, $collaboratorPageId]);

            if ($stmt->fetch()) {
                return Response::error(409, 'Esta página ya fue invitada a colaborar');
            }

            $stmt = $db->prepare('INSERT INTO event_collaborations (link_id, collaborator_page_id, requester_page_id) VALUES (?, ?, ?)');
            $stmt->execute([$linkId, $collaboratorPageId, $evento['page_id']]);
            $collabId = $db->lastInsertId();

            // page_id apunta a la página del invitado para que la notificación
            // lo lleve a su propio editor.
            $stmt = $db->prepare('
                INSERT INTO notifications (user_id, title, message, page_id, link_id, type, collaboration_id, is_read)
                VALUES (?, ?, ?, ?, ?, "collaboration_request", ?, 0)
            ');
            $stmt->execute([
                $paginaColaboradora['user_id'],
                'Invitación a colaborar',
                'La página "' . $evento['page_title'] . '" te invita a colaborar en el evento "' . $evento['event_title'] . '"',
                $collaboratorPageId,
                $linkId,
                $collabId,
            ]);

            return Response::created([
                'message' => 'Invitación enviada',
                'collaboration_id' => $collabId,
            ]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ----------------------------------------------------------------- detail

    public static function detail($db, Request $req)
    {
        $collabId = (int) $req->param('id');

        if (!$collabId) {
            return Response::error(400, 'Collaboration ID required');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'PUT') {
            return self::responder($db, $req, $collabId);
        }

        if ($req->method === 'DELETE') {
            return self::eliminar($db, $req, $collabId);
        }

        return Response::methodNotAllowed();
    }

    private static function responder($db, Request $req, $collabId)
    {
        $status = $req->input('status');
        $groupId = (int) $req->input('group_id');

        if (!in_array($status, self::$estadosValidos, true)) {
            return Response::error(400, 'Estado inválido. Debe ser "accepted" o "rejected"');
        }

        // Al aceptar hay que decir en qué grupo de eventos se publica.
        if ($status === 'accepted' && !$groupId) {
            return Response::error(400, 'group_id es requerido al aceptar');
        }

        try {
            $stmt = $db->prepare('
                SELECT ec.*, ec.requester_page_id,
                    cp.user_id as collaborator_owner_id, cp.title as collaborator_page_title,
                    rp.user_id as requester_owner_id, rp.title as requester_page_title,
                    l.text as event_title
                FROM event_collaborations ec
                JOIN pages cp ON ec.collaborator_page_id = cp.id
                JOIN pages rp ON ec.requester_page_id = rp.id
                JOIN links l ON ec.link_id = l.id
                WHERE ec.id = ?
            ');
            $stmt->execute([$collabId]);
            $collab = $stmt->fetch(PDO::FETCH_ASSOC);

            // Sólo el invitado responde.
            if (!$collab || $collab['collaborator_owner_id'] != $req->userId()) {
                return Response::error(403, 'Forbidden');
            }

            if ($collab['status'] !== 'pending') {
                return Response::error(400, 'Esta colaboración ya fue procesada');
            }

            if ($status === 'accepted') {
                $stmt = $db->prepare('
                    SELECT lg.id FROM link_groups lg
                    JOIN pages p ON lg.page_id = p.id
                    WHERE lg.id = ? AND p.user_id = ? AND lg.type = "eventos"
                ');
                $stmt->execute([$groupId, $req->userId()]);

                if (!$stmt->fetch()) {
                    return Response::error(400, 'Grupo inválido: debe ser un grupo de eventos de tu página');
                }
            }

            $stmt = $db->prepare('UPDATE event_collaborations SET status = ?, collaborator_group_id = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$status, $status === 'accepted' ? $groupId : null, $collabId]);

            $aceptada = $status === 'accepted';
            $notifTitulo = $aceptada ? 'Colaboración aceptada' : 'Colaboración rechazada';
            $notifMensaje = $aceptada
                ? 'La página "' . $collab['collaborator_page_title'] . '" aceptó colaborar en el evento "' . $collab['event_title'] . '"'
                : 'La página "' . $collab['collaborator_page_title'] . '" rechazó la colaboración en el evento "' . $collab['event_title'] . '"';

            $stmt = $db->prepare('
                INSERT INTO notifications (user_id, title, message, page_id, link_id, type, is_read)
                VALUES (?, ?, ?, ?, ?, "collaboration_response", 0)
            ');
            $stmt->execute([
                $collab['requester_owner_id'],
                $notifTitulo,
                $notifMensaje,
                $collab['requester_page_id'],
                $collab['link_id'],
            ]);

            $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE collaboration_id = ? AND user_id = ?');
            $stmt->execute([$collabId, $req->userId()]);

            return Response::ok([
                'message' => 'Colaboración ' . ($aceptada ? 'aceptada' : 'rechazada'),
            ]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function eliminar($db, Request $req, $collabId)
    {
        try {
            $stmt = $db->prepare('
                SELECT ec.*,
                    rp.user_id as requester_owner_id,
                    cp.user_id as collaborator_owner_id
                FROM event_collaborations ec
                JOIN pages rp ON ec.requester_page_id = rp.id
                JOIN pages cp ON ec.collaborator_page_id = cp.id
                WHERE ec.id = ?
            ');
            $stmt->execute([$collabId]);
            $collab = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$collab) {
                return Response::notFound('Colaboración no encontrada');
            }

            // Cualquiera de las dos partes puede deshacer la colaboración.
            $esSolicitante = $collab['requester_owner_id'] == $req->userId();
            $esColaborador = $collab['collaborator_owner_id'] == $req->userId();

            if (!$esSolicitante && !$esColaborador) {
                return Response::error(403, 'Forbidden');
            }

            $stmt = $db->prepare('DELETE FROM event_collaborations WHERE id = ?');
            $stmt->execute([$collabId]);

            return Response::ok(['message' => 'Colaboración eliminada']);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }
}
