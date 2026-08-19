<?php

/**
 * Grupos de links: alta (index) y edición/borrado (detail).
 *
 * Extraído de api/groups/index.php y api/groups/detail.php sin cambios de
 * comportamiento.
 */
class GroupsHandler
{
    /**
     * Tipos de grupo que existen.
     *
     * Se valida acá y no sólo en la base: con el ENUM alcanza para que no
     * entre basura, pero el error de MySQL no le dice nada a quien lo lee.
     */
    public static $tiposValidos = ['links', 'galeria', 'eventos'];

    private static $updatableFields = ['title', 'type', 'position'];

    // ------------------------------------------------------------------ index

    public static function index($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->missing(['page_id', 'title'])) {
            return Response::error(400, 'Page ID and title are required');
        }

        try {
            if (!PageAccess::canManage($db, $req->input('page_id'), $req->userId())) {
                return Response::notFound('Page not found');
            }

            $tipo = $req->input('type', 'links');

            if (!in_array($tipo, self::$tiposValidos, true)) {
                return Response::error(400, 'Tipo de grupo desconocido: ' . $tipo);
            }

            $stmt = $db->prepare('INSERT INTO link_groups (page_id, title, type, position) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $req->input('page_id'),
                $req->input('title'),
                $tipo,
                $req->input('position', 0),
            ]);

            $groupId = $db->lastInsertId();

            $stmt = $db->prepare('SELECT * FROM link_groups WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();

            return Response::created(['group' => $group]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ----------------------------------------------------------------- detail

    public static function detail($db, Request $req)
    {
        $groupId = (int) $req->param('id');

        if (!$groupId) {
            return Response::error(400, 'Group ID is required');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'PUT') {
            return self::update($db, $req, $groupId);
        }

        if ($req->method === 'DELETE') {
            return self::destroy($db, $req, $groupId);
        }

        return Response::methodNotAllowed();
    }

    private static function update($db, Request $req, $groupId)
    {
        try {
            if (!PageAccess::canManageGroup($db, $groupId, $req->userId())) {
                return Response::notFound('Group not found');
            }

            $fields = [];
            $values = [];

            foreach (self::$updatableFields as $campo) {
                if (!$req->has($campo)) {
                    continue;
                }
                $fields[] = $campo . ' = ?';
                $values[] = $req->input($campo);
            }

            if (empty($fields)) {
                return Response::error(400, 'No fields to update');
            }

            $values[] = $groupId;
            $stmt = $db->prepare('UPDATE link_groups SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->execute($values);

            $stmt = $db->prepare('SELECT * FROM link_groups WHERE id = ?');
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();

            return Response::ok(['group' => $group]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function destroy($db, Request $req, $groupId)
    {
        try {
            if (!PageAccess::canManageGroup($db, $groupId, $req->userId())) {
                return Response::notFound('Group not found');
            }

            $stmt = $db->prepare('DELETE FROM link_groups WHERE id = ?');
            $stmt->execute([$groupId]);

            return Response::ok(['message' => 'Group deleted successfully']);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

}
