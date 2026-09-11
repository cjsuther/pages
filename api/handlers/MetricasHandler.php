<?php

/**
 * Las métricas de una página, para quien la administra.
 *
 * Los datos son de Google Analytics y se piden filtrados por la ruta de la
 * página: quien administra la suya ve lo suyo y nada más. El permiso lo decide
 * PageAccess, igual que el resto del editor.
 */
class MetricasHandler
{
    public static function pagina($db, Request $req, $analytics = null)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $userId = $req->userId();

        if ($userId === null) {
            return Response::unauthorized();
        }

        $pageId = (int) $req->param('page_id');

        if (!PageAccess::canManage($db, $pageId, $userId)) {
            return Response::notFound('Page not found');
        }

        $stmt = $db->prepare('SELECT url_slug, dominio FROM pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $pagina = $stmt->fetch();

        if (!$pagina) {
            return Response::notFound('Page not found');
        }

        $analytics = $analytics === null ? new Analytics() : $analytics;

        // Sin credenciales no es un error: es una pantalla que explica qué
        // falta configurar. Un 500 le diría a quien administra una página que
        // algo se rompió, cuando lo que pasa es que nadie lo configuró todavía.
        if (!$analytics->estaListo()) {
            return Response::ok([
                'configurado' => false,
                'motivo' => 'Falta conectar Google Analytics en el servidor.',
            ]);
        }

        $informe = $analytics->dePagina(
            $pagina['url_slug'],
            $pagina['dominio'],
            Analytics::diasValidos($req->param('dias', 30))
        );

        if (isset($informe['error'])) {
            return Response::error(502, $informe['error']);
        }

        return Response::ok($informe);
    }
}
