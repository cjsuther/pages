<?php

/**
 * Administración compartida de páginas: invitar, aceptar/rechazar y remover.
 *
 * Extraído de api/admins/index.php y api/admins/detail.php sin cambios de
 * comportamiento. Regla base: invitar y listar exige ser dueño o admin, pero
 * *invitar* es exclusivo del dueño.
 */
class AdminsHandler
{
    private static $estadosValidos = ['accepted', 'rejected'];

    // ------------------------------------------------------------------ index

    public static function index($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'GET') {
            return $req->param('type') === 'pending'
                ? self::misInvitaciones($db, $req)
                : self::listarDeUnaPagina($db, $req);
        }

        if ($req->method === 'POST') {
            return self::invitar($db, $req);
        }

        return Response::methodNotAllowed();
    }

    /** Invitaciones pendientes que recibió el usuario. */
    private static function misInvitaciones($db, Request $req)
    {
        $stmt = $db->prepare('
            SELECT pa.id, pa.page_id, pa.status, pa.created_at,
                   p.title AS page_title, p.url_slug AS page_slug, p.profile_image AS page_image,
                   owner.name AS owner_name, owner.email AS owner_email
            FROM page_admins pa
            JOIN pages p ON pa.page_id = p.id
            JOIN users owner ON p.user_id = owner.id
            WHERE pa.user_id = ? AND pa.status = "pending"
            ORDER BY pa.created_at DESC
        ');
        $stmt->execute([$req->userId()]);

        return Response::ok(['invitations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private static function listarDeUnaPagina($db, Request $req)
    {
        $pageId = (int) $req->param('page_id');

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'Forbidden');
        }

        $stmt = $db->prepare('
            SELECT pa.id, pa.user_id, pa.status, pa.created_at,
                   u.name AS user_name, u.email AS user_email
            FROM page_admins pa
            JOIN users u ON pa.user_id = u.id
            WHERE pa.page_id = ?
            ORDER BY pa.status, pa.created_at
        ');
        $stmt->execute([$pageId]);

        return Response::ok(['admins' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private static function invitar($db, Request $req)
    {
        $pageId = (int) $req->input('page_id');
        $email = trim(strtolower((string) $req->input('email', '')));

        if (!$pageId || $email === '') {
            return Response::error(400, 'page_id y email son requeridos');
        }

        // Sin esto, un email mal escrito caía en "no hay ningún usuario
        // registrado con ese email", que hace buscar al invitado en vez de
        // mirar el error de tipeo que uno tiene delante.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error(400, 'El email no tiene un formato válido');
        }

        // Sólo el dueño: un admin no puede sumar más admins.
        if (!PageAccess::isOwner($db, $pageId, $req->userId())) {
            return Response::error(403, 'Solo el dueño de la página puede invitar administradores');
        }

        try {
            $stmt = $db->prepare('SELECT id, name, email FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $invitado = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invitado) {
                return Response::notFound('No hay ningún usuario registrado con ese email');
            }

            if ($invitado['id'] == $req->userId()) {
                return Response::error(400, 'No podés invitarte a vos mismo');
            }

            $stmt = $db->prepare('SELECT title FROM pages WHERE id = ?');
            $stmt->execute([$pageId]);
            $pageTitle = $stmt->fetchColumn();

            $stmt = $db->prepare('SELECT id, status FROM page_admins WHERE page_id = ? AND user_id = ?');
            $stmt->execute([$pageId, $invitado['id']]);
            $existente = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existente) {
                return Response::error(409, $existente['status'] === 'accepted'
                    ? 'Ese usuario ya es administrador de esta página'
                    : 'Ya hay una invitación pendiente para ese usuario');
            }

            $stmt = $db->prepare('INSERT INTO page_admins (page_id, user_id, status, invited_by) VALUES (?, ?, "pending", ?)');
            $stmt->execute([$pageId, $invitado['id'], $req->userId()]);
            $adminId = $db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO notifications (user_id, title, message, page_id, link_id, type, is_read)
                VALUES (?, ?, ?, ?, NULL, "admin_invitation", 0)
            ');
            $stmt->execute([
                $invitado['id'],
                'Invitación para administrar una página',
                'Te invitaron a administrar la página "' . $pageTitle . '". Aceptala desde "Mis páginas".',
                $pageId,
            ]);

            return Response::created([
                'message' => 'Invitación enviada',
                'admin' => [
                    'id' => $adminId,
                    'user_id' => $invitado['id'],
                    'user_name' => $invitado['name'],
                    'user_email' => $invitado['email'],
                    'status' => 'pending',
                ],
            ]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ----------------------------------------------------------------- detail

    public static function detail($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'PUT') {
            return self::responderInvitacion($db, $req);
        }

        if ($req->method === 'DELETE') {
            return self::remover($db, $req);
        }

        return Response::methodNotAllowed();
    }

    private static function responderInvitacion($db, Request $req)
    {
        $adminId = (int) $req->param('id');

        if (!$adminId) {
            return Response::error(400, 'id requerido');
        }

        $status = $req->input('status');

        if (!in_array($status, self::$estadosValidos, true)) {
            return Response::error(400, 'status inválido. Debe ser "accepted" o "rejected"');
        }

        try {
            $stmt = $db->prepare('
                SELECT pa.id, pa.page_id, pa.user_id, pa.status,
                       p.title AS page_title, p.user_id AS owner_id
                FROM page_admins pa
                JOIN pages p ON pa.page_id = p.id
                WHERE pa.id = ?
            ');
            $stmt->execute([$adminId]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);

            // Sólo el invitado puede responder su propia invitación.
            if (!$fila || $fila['user_id'] != $req->userId()) {
                return Response::error(403, 'Forbidden');
            }

            if ($fila['status'] !== 'pending') {
                return Response::error(400, 'Esta invitación ya fue procesada');
            }

            $stmt = $db->prepare('SELECT name, email FROM users WHERE id = ?');
            $stmt->execute([$req->userId()]);
            $yo = $stmt->fetch(PDO::FETCH_ASSOC);
            $miNombre = self::nombreVisible($yo);

            if ($status === 'accepted') {
                $stmt = $db->prepare('UPDATE page_admins SET status = "accepted", updated_at = NOW() WHERE id = ?');
                $stmt->execute([$adminId]);
                $notifTitulo = 'Invitación aceptada';
                $notifMensaje = $miNombre . ' aceptó administrar tu página "' . $fila['page_title'] . '"';
            } else {
                // Rechazar borra la fila: se puede volver a invitar más adelante.
                $stmt = $db->prepare('DELETE FROM page_admins WHERE id = ?');
                $stmt->execute([$adminId]);
                $notifTitulo = 'Invitación rechazada';
                $notifMensaje = $miNombre . ' rechazó administrar tu página "' . $fila['page_title'] . '"';
            }

            $stmt = $db->prepare('
                INSERT INTO notifications (user_id, title, message, page_id, link_id, type, is_read)
                VALUES (?, ?, ?, ?, NULL, "admin_response", 0)
            ');
            $stmt->execute([$fila['owner_id'], $notifTitulo, $notifMensaje, $fila['page_id']]);

            $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND page_id = ? AND type = "admin_invitation"');
            $stmt->execute([$req->userId(), $fila['page_id']]);

            return Response::ok([
                'message' => $status === 'accepted' ? 'Invitación aceptada' : 'Invitación rechazada',
            ]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function remover($db, Request $req)
    {
        $adminId = (int) $req->param('id');
        $pageId = (int) $req->param('page_id');

        try {
            if ($adminId) {
                return self::removerPorId($db, $req, $adminId);
            }

            if ($pageId) {
                return self::autoSalida($db, $req, $pageId);
            }

            return Response::error(400, 'id o page_id requerido');

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function removerPorId($db, Request $req, $adminId)
    {
        $stmt = $db->prepare('
            SELECT pa.id, pa.user_id, p.user_id AS owner_id
            FROM page_admins pa JOIN pages p ON pa.page_id = p.id
            WHERE pa.id = ?
        ');
        $stmt->execute([$adminId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            return Response::notFound('No encontrado');
        }

        // El dueño puede quitar a cualquiera; un admin sólo puede quitarse a sí mismo.
        $esDueno = $fila['owner_id'] == $req->userId();
        $esElMismo = $fila['user_id'] == $req->userId();

        if (!$esDueno && !$esElMismo) {
            return Response::error(403, 'Forbidden');
        }

        $stmt = $db->prepare('DELETE FROM page_admins WHERE id = ?');
        $stmt->execute([$adminId]);

        return Response::ok(['message' => 'Administrador removido']);
    }

    private static function autoSalida($db, Request $req, $pageId)
    {
        $stmt = $db->prepare('DELETE FROM page_admins WHERE page_id = ? AND user_id = ?');
        $stmt->execute([$pageId, $req->userId()]);

        if ($stmt->rowCount() === 0) {
            return Response::notFound('No sos administrador de esa página');
        }

        return Response::ok(['message' => 'Dejaste de administrar la página']);
    }

    /** Nombre para las notificaciones; si no tiene nombre cargado, el email. */
    private static function nombreVisible($usuario)
    {
        if (!is_array($usuario)) {
            return '';
        }

        $nombre = isset($usuario['name']) ? $usuario['name'] : null;

        return $nombre ?: (isset($usuario['email']) ? $usuario['email'] : '');
    }
}
