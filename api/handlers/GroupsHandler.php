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
    public static $tiposValidos = ['links', 'galeria', 'eventos', 'productos'];

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

    public static function detail($db, Request $req, ?FileStorage $storage = null)
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
            return self::destroy($db, $req, $groupId, $storage === null ? new FileStorage() : $storage);
        }

        return Response::methodNotAllowed();
    }

    private static function update($db, Request $req, $groupId)
    {
        try {
            if (!PageAccess::canManageGroup($db, $groupId, $req->userId())) {
                return Response::notFound('Group not found');
            }

            if ($req->has('type')) {
                $error = self::validarCambioDeTipo($db, $groupId, $req->input('type'));

                if ($error !== null) {
                    return $error;
                }
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

    private static function destroy($db, Request $req, $groupId, FileStorage $storage)
    {
        try {
            if (!PageAccess::canManageGroup($db, $groupId, $req->userId())) {
                return Response::notFound('Group not found');
            }

            // Borrar el grupo borra sus eventos: lo mismo que al borrar uno.
            Clientes::actualizarEventosDelGrupo($db, $groupId);

            // Y sus productos, con sus archivos. Las rutas se leen antes,
            // porque después del DELETE ya no hay filas que las digan.
            $archivos = ProductosDigitales::rutasDelGrupo($db, $groupId);

            $stmt = $db->prepare('DELETE FROM link_groups WHERE id = ?');
            $stmt->execute([$groupId]);

            ProductosDigitales::borrarArchivos($archivos, $storage);

            return Response::ok(['message' => 'Group deleted successfully']);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    /**
     * Un grupo puede cambiar de tipo, salvo de o hacia productos.
     *
     * Un producto es un link con su fila de precio y archivo: convertir el
     * grupo en links dejaría los productos publicados como links vacíos, y
     * convertir links en productos dejaría productos sin precio ni archivo.
     *
     * @return Response|null
     */
    private static function validarCambioDeTipo($db, $groupId, $tipo)
    {
        if (!in_array($tipo, self::$tiposValidos, true)) {
            return Response::error(400, 'Tipo de grupo desconocido: ' . $tipo);
        }

        $stmt = $db->prepare('SELECT type FROM link_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        $actual = $stmt->fetchColumn();

        if ($actual !== $tipo && ($actual === 'productos' || $tipo === 'productos')) {
            return Response::error(400, 'Un grupo de productos digitales no puede cambiar de tipo, ni otro grupo pasar a serlo');
        }

        return null;
    }
}
