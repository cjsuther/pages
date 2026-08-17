<?php

/**
 * Claves de API de la persona: listarlas, crear una y darlas de baja.
 *
 * Se maneja con la sesión web normal, no con una clave: una credencial no
 * puede servir para fabricarse otras, porque entonces revocar la que se filtró
 * no alcanzaría para cerrar la puerta.
 */
class ClavesHandler
{
    public static function index($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        // Las claves se administran desde el sitio, con la sesión de la
        // persona. Una clave de API no puede crear ni revocar claves.
        if ($req->user === null || !empty($req->user['por_clave_api'])) {
            return Response::error(403, 'Las claves se administran desde tu cuenta en el sitio');
        }

        if ($req->method === 'GET') {
            return Response::ok(['claves' => ClavesApi::listar($db, $req->userId())]);
        }

        if ($req->method === 'POST') {
            $resultado = ClavesApi::generar($db, $req->userId(), $req->input('nombre'));

            if (!$resultado['ok']) {
                return Response::error(400, $resultado['error']);
            }

            // La clave viaja una única vez, acá. Después queda sólo el hash.
            return Response::created([
                'clave' => $resultado['clave'],
                'id' => $resultado['id'],
                'aviso' => 'Guardala ahora: no la vas a poder volver a ver.',
            ]);
        }

        if ($req->method === 'DELETE') {
            $id = (int) $req->param('id');

            if (!$id) {
                return Response::error(400, 'id requerido');
            }

            if (!ClavesApi::revocar($db, $req->userId(), $id)) {
                return Response::notFound('No encontramos esa clave');
            }

            return Response::ok(['revocada' => true]);
        }

        return Response::methodNotAllowed();
    }
}
