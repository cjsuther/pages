<?php

/**
 * Clientes de una página: quién compró, reservó o la sigue, con los eventos
 * en los que participó —los borrados incluidos— y la exportación a Excel.
 *
 * Los ve quien administra la página. Incluye a quienes compraron en eventos
 * en los que la página colabora: la colaboración comparte el público.
 */
class ClientesHandler
{
    public static function index($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $pageId = (int) $req->param('page_id');

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        // Nombres, emails y teléfonos de terceros: el mismo criterio que las
        // ventas de un evento.
        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'No podés ver los clientes de esta página');
        }

        $listado = Clientes::dePagina($db, $pageId, [
            'q'          => $req->param('q', ''),
            'evento'     => $req->param('evento', ''),
            'desde'      => $req->param('desde', ''),
            'hasta'      => $req->param('hasta', ''),
            'origen'     => $req->param('origen', ''),
            'asistencia' => $req->param('asistencia', ''),
            'cuenta'     => $req->param('cuenta', ''),
        ]);

        if ($req->param('formato') === 'excel') {
            return Response::raw(200, Clientes::excel($listado['clientes']),
                Excel::cabeceras('clientes-' . self::slug($db, $pageId) . '.xlsx'));
        }

        return Response::ok($listado);
    }

    private static function slug($db, $pageId)
    {
        $stmt = $db->prepare('SELECT url_slug FROM pages WHERE id = ?');
        $stmt->execute([(int) $pageId]);
        $slug = $stmt->fetchColumn();

        return $slug === false || $slug === null || $slug === '' ? (string) (int) $pageId : $slug;
    }
}
