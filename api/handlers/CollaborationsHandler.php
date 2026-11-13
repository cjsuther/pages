<?php

/**
 * Colaboraciones entre páginas sobre un evento: invitar, aceptar/rechazar y
 * eliminar, y las reglas para aceptar automáticamente las invitaciones de una
 * página (colaboraciones_automaticas).
 *
 * La regla es de página a página, igual que las colaboraciones: la página
 * invitada acepta de antemano lo que le mande una página concreta, y no otras
 * de la misma persona. La crea y la borra sólo quien administra la página
 * invitada —dueño o administrador aceptado, sin el acceso de plataforma—, y
 * siempre a partir de una invitación real que está aceptando.
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
        // Quien puede administrar el evento ve sus colaboraciones. Va por
        // PageAccess y no por una consulta propia: si no, este módulo queda
        // fuera de cualquier forma de acceso que no sea ser el dueño —los
        // administradores de la página y el acceso de plataforma incluidos—.
        if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
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

    /**
     * Invitaciones pendientes de responder.
     *
     * Con page_id contesta "qué tiene pendiente esta página", y ahí el permiso
     * lo resuelve PageAccess: sirve para el editor, donde se está trabajando
     * sobre una página concreta y no necesariamente propia.
     *
     * Sin page_id contesta "qué tengo que responder yo": las páginas propias y
     * las que se administran. Ese es el uso de la bandeja, y a propósito no
     * contempla el acceso de plataforma —quien lo tiene no necesita ver en su
     * lista las invitaciones de todo el mundo—.
     */
    private static function pendientesDeMisPaginas($db, Request $req)
    {
        $pageId = (int) $req->param('page_id', 0);

        if ($pageId) {
            if (!PageAccess::canManage($db, $pageId, $req->userId())) {
                return Response::error(403, 'Forbidden');
            }

            return self::pendientesConFiltro($db, 'cp.id = ?', [$pageId]);
        }

        return self::pendientesConFiltro($db, '(
                cp.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM page_admins pa
                    WHERE pa.page_id = cp.id AND pa.user_id = ? AND pa.status = "accepted"
                )
            )', [$req->userId(), $req->userId()]);
    }

    private static function pendientesConFiltro($db, $filtro, array $params)
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
            WHERE ec.status = "pending" AND ' . $filtro . '
            ORDER BY ec.created_at DESC
        ');
        $stmt->execute($params);

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
            if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
                return Response::notFound('Event not found or not authorized');
            }

            // El permiso ya está resuelto; esto sólo trae los datos y comprueba
            // que sea un evento: a un link común no se lo puede colaborar.
            $stmt = $db->prepare('
                SELECT l.id, l.text as event_title, p.id as page_id, p.title as page_title,
                    p.user_id as page_owner_id
                FROM links l
                JOIN link_groups lg ON l.group_id = lg.id
                JOIN pages p ON lg.page_id = p.id
                WHERE l.id = ? AND lg.type = "eventos"
            ');
            $stmt->execute([$linkId]);
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

            $db->beginTransaction();

            $stmt = $db->prepare('INSERT INTO event_collaborations (link_id, collaborator_page_id, requester_page_id) VALUES (?, ?, ?)');
            $stmt->execute([$linkId, $collaboratorPageId, $evento['page_id']]);
            $collabId = $db->lastInsertId();

            // Si la página invitada acepta siempre las de esta página, se
            // acepta ya, con el mismo efecto que si la hubiera aceptado a mano.
            $regla = self::reglaVigente($db, $collaboratorPageId, $evento['page_id']);

            if ($regla) {
                self::registrarAceptacion($db, $collabId, $linkId, (int) $regla['group_id']);

                self::avisarAlQueInvito(
                    $db,
                    $evento['page_owner_id'],
                    $evento['page_id'],
                    $linkId,
                    'Colaboración aceptada',
                    'La página "' . $regla['collaborator_page_title'] . '" aceptó colaborar en el evento "' . $evento['event_title'] . '" (aceptación automática)'
                );

                // El invitado no tiene nada que responder: se le avisa lo que
                // pasó, dónde quedó publicado y cómo dejar de aceptarlas.
                $stmt = $db->prepare('
                    INSERT INTO notifications (user_id, title, message, page_id, link_id, type, collaboration_id, is_read)
                    VALUES (?, ?, ?, ?, ?, "collaboration_auto_accepted", ?, 0)
                ');
                $stmt->execute([
                    $paginaColaboradora['user_id'],
                    'Colaboración aceptada automáticamente',
                    'Se aceptó automáticamente la invitación de "' . $evento['page_title'] . '" a colaborar en el evento "' . $evento['event_title'] . '": ya está en tu grupo "' . $regla['group_title'] . '". Podés dejar de aceptarlas automáticamente desde Configuración › Colaboraciones.',
                    $collaboratorPageId,
                    $linkId,
                    $collabId,
                ]);

                $db->commit();

                return Response::created([
                    'message' => 'Invitación aceptada automáticamente',
                    'collaboration_id' => $collabId,
                    'status' => 'accepted',
                    'aceptada_automaticamente' => true,
                ]);
            }

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

            $db->commit();

            return Response::created([
                'message' => 'Invitación enviada',
                'collaboration_id' => $collabId,
                'status' => 'pending',
                'aceptada_automaticamente' => false,
            ]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
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
        $aceptarSiempre = filter_var($req->input('aceptar_siempre', false), FILTER_VALIDATE_BOOLEAN);

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

            // Responde el invitado: quien pueda administrar la página invitada,
            // no sólo su dueño.
            if (!$collab || !PageAccess::canManage($db, $collab['collaborator_page_id'], $req->userId())) {
                return Response::error(403, 'Forbidden');
            }

            if ($collab['status'] !== 'pending') {
                return Response::error(400, 'Esta colaboración ya fue procesada');
            }

            if ($status === 'accepted') {
                // Tiene que ser un grupo de eventos de la página invitada, y no
                // de cualquier otra que la persona administre: el evento se
                // publica ahí.
                $stmt = $db->prepare('
                    SELECT lg.id FROM link_groups lg
                    WHERE lg.id = ? AND lg.page_id = ? AND lg.type = "eventos"
                ');
                $stmt->execute([$groupId, $collab['collaborator_page_id']]);

                if (!$stmt->fetch()) {
                    return Response::error(400, 'Grupo inválido: debe ser un grupo de eventos de tu página');
                }

                // Aceptar de antemano lo que mande otra página es una decisión
                // de la página invitada: no la toma el acceso de plataforma.
                if ($aceptarSiempre && !PageAccess::isOwnerOrAdmin($db, $collab['collaborator_page_id'], $req->userId())) {
                    return Response::error(403, 'Sólo quien administra la página invitada puede aceptar siempre sus invitaciones');
                }
            }

            $aceptada = $status === 'accepted';
            $reglaCreada = $aceptada && $aceptarSiempre;

            $db->beginTransaction();

            if ($aceptada) {
                self::registrarAceptacion($db, $collabId, (int) $collab['link_id'], $groupId);
            } else {
                $stmt = $db->prepare('UPDATE event_collaborations SET status = ?, collaborator_group_id = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$status, null, $collabId]);
            }

            if ($reglaCreada) {
                // Si ya había una regla con esta página, sólo cambia el grupo.
                $stmt = $db->prepare('
                    INSERT INTO colaboraciones_automaticas (collaborator_page_id, requester_page_id, group_id, created_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), created_by = VALUES(created_by)
                ');
                $stmt->execute([
                    (int) $collab['collaborator_page_id'],
                    (int) $collab['requester_page_id'],
                    $groupId,
                    $req->userId(),
                ]);
            }

            $notifTitulo = $aceptada ? 'Colaboración aceptada' : 'Colaboración rechazada';
            $notifMensaje = $aceptada
                ? 'La página "' . $collab['collaborator_page_title'] . '" aceptó colaborar en el evento "' . $collab['event_title'] . '"'
                : 'La página "' . $collab['collaborator_page_title'] . '" rechazó la colaboración en el evento "' . $collab['event_title'] . '"';

            self::avisarAlQueInvito(
                $db,
                $collab['requester_owner_id'],
                $collab['requester_page_id'],
                $collab['link_id'],
                $notifTitulo,
                $notifMensaje
            );

            $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE collaboration_id = ? AND user_id = ?');
            $stmt->execute([$collabId, $req->userId()]);

            $db->commit();

            return Response::ok([
                'message' => 'Colaboración ' . ($aceptada ? 'aceptada' : 'rechazada'),
                'aceptar_siempre' => $reglaCreada,
            ]);

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return Response::serverError($e->getMessage());
        }
    }

    /**
     * Lo que tiene aceptar, a mano o por una regla: la colaboración queda
     * aceptada en el grupo elegido y, desde ahí, la página colaboradora ve a
     * los clientes del evento —y los sigue viendo aunque el evento se borre—.
     */
    private static function registrarAceptacion($db, $collabId, $linkId, $groupId)
    {
        $stmt = $db->prepare('UPDATE event_collaborations SET status = ?, collaborator_group_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute(['accepted', $groupId, $collabId]);

        Clientes::registrarEvento($db, (int) $linkId);
    }

    /** La respuesta a una invitación le llega a quien es dueño de la página que invitó. */
    private static function avisarAlQueInvito($db, $userId, $pageId, $linkId, $titulo, $mensaje)
    {
        $stmt = $db->prepare('
            INSERT INTO notifications (user_id, title, message, page_id, link_id, type, is_read)
            VALUES (?, ?, ?, ?, ?, "collaboration_response", 0)
        ');
        $stmt->execute([$userId, $titulo, $mensaje, $pageId, $linkId]);
    }

    /**
     * La regla de la página invitada para las invitaciones de esta página, si
     * sigue sirviendo: el grupo tiene que seguir siendo un grupo de eventos de
     * la página invitada. Si no, la invitación queda pendiente como siempre.
     */
    private static function reglaVigente($db, $collaboratorPageId, $requesterPageId)
    {
        $stmt = $db->prepare('
            SELECT ca.id, ca.group_id, lg.title as group_title, cp.title as collaborator_page_title
            FROM colaboraciones_automaticas ca
            JOIN link_groups lg ON lg.id = ca.group_id
            JOIN pages cp ON cp.id = ca.collaborator_page_id
            WHERE ca.collaborator_page_id = ? AND ca.requester_page_id = ?
                AND lg.page_id = ca.collaborator_page_id AND lg.type = "eventos"
            LIMIT 1
        ');
        $stmt->execute([(int) $collaboratorPageId, (int) $requesterPageId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ------------------------------------------------------------ automaticas

    /**
     * Las colaboraciones que una página acepta automáticamente: verlas
     * (GET ?page_id=) y dejar de aceptarlas (DELETE ?id=). Se crean sólo al
     * aceptar una invitación (PUT a detail con aceptar_siempre).
     *
     * Todo es de la página invitada: ni la página que invita ni el acceso de
     * plataforma las ven o las tocan.
     */
    public static function automaticas($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'GET') {
            return self::listarAutomaticas($db, $req);
        }

        if ($req->method === 'DELETE') {
            return self::dejarDeAceptarAutomaticamente($db, $req);
        }

        return Response::methodNotAllowed();
    }

    private static function listarAutomaticas($db, Request $req)
    {
        $pageId = (int) $req->param('page_id', 0);

        if (!$pageId) {
            return Response::error(400, 'page_id es requerido');
        }

        try {
            if (!PageAccess::isOwnerOrAdmin($db, $pageId, $req->userId())) {
                return Response::error(403, 'Forbidden');
            }

            $stmt = $db->prepare('
                SELECT ca.id, ca.requester_page_id, ca.group_id, ca.created_at,
                    rp.title as requester_page_title, rp.url_slug as requester_page_slug,
                    rp.profile_image as requester_page_image,
                    lg.title as group_title
                FROM colaboraciones_automaticas ca
                JOIN pages rp ON rp.id = ca.requester_page_id
                JOIN link_groups lg ON lg.id = ca.group_id
                WHERE ca.collaborator_page_id = ?
                ORDER BY rp.title, ca.id
            ');
            $stmt->execute([$pageId]);

            return Response::ok(['automaticas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    /** Sólo deja de aceptar las nuevas: las colaboraciones ya aceptadas siguen. */
    private static function dejarDeAceptarAutomaticamente($db, Request $req)
    {
        $reglaId = (int) $req->param('id', 0);

        if (!$reglaId) {
            return Response::error(400, 'id es requerido');
        }

        try {
            $stmt = $db->prepare('SELECT id, collaborator_page_id FROM colaboraciones_automaticas WHERE id = ?');
            $stmt->execute([$reglaId]);
            $regla = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$regla) {
                return Response::notFound('Regla no encontrada');
            }

            if (!PageAccess::isOwnerOrAdmin($db, $regla['collaborator_page_id'], $req->userId())) {
                return Response::error(403, 'Forbidden');
            }

            $stmt = $db->prepare('DELETE FROM colaboraciones_automaticas WHERE id = ?');
            $stmt->execute([$reglaId]);

            return Response::ok(['message' => 'Ya no se aceptan automáticamente']);

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

            // Deshecha la colaboración, la página deja de ver a los clientes.
            Clientes::actualizarEvento($db, (int) $collab['link_id']);

            return Response::ok(['message' => 'Colaboración eliminada']);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }
}
