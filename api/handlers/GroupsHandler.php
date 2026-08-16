<?php

/**
 * Grupos de links: alta (index) y edición/borrado (detail).
 *
 * Extraído de api/groups/index.php y api/groups/detail.php sin cambios de
 * comportamiento.
 */
class GroupsHandler
{
    /** Links precargados al crear un grupo de tipo "redes". */
    private static $redesPorDefecto = [
        ['Instagram', 'https://instagram.com/', '/social/instagram.svg'],
        ['TikTok',    'https://tiktok.com/',    '/social/tiktok.svg'],
        ['YouTube',   'https://youtube.com/',   '/social/youtube.svg'],
        ['Facebook',  'https://facebook.com/',  '/social/facebook.svg'],
        ['WhatsApp',  'https://wa.me/',         '/social/whatsapp.svg'],
        ['Cafecito',  'https://cafecito.app/',  '/social/cafecito.svg'],
    ];

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

            $stmt = $db->prepare('INSERT INTO link_groups (page_id, title, type, position) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $req->input('page_id'),
                $req->input('title'),
                $tipo,
                $req->input('position', 0),
            ]);

            $groupId = $db->lastInsertId();

            if ($tipo === 'redes') {
                self::precargarRedes($db, $groupId);
            }

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

    private static function precargarRedes($db, $groupId)
    {
        $stmt = $db->prepare('INSERT INTO links (group_id, url, text, image_url, position) VALUES (?, ?, ?, ?, ?)');

        foreach (self::$redesPorDefecto as $i => $red) {
            $stmt->execute([$groupId, $red[1], $red[0], $red[2], $i]);
        }
    }

    /** Expuesto para que los tests verifiquen el catálogo sin duplicarlo. */
    public static function redesPorDefecto()
    {
        return self::$redesPorDefecto;
    }
}
