<?php

/**
 * "También te puede interesar" en el detalle de un evento o un producto
 * (#30). Público, sin sesión: lo pide cada detalle. El orden de prioridad
 * está en Sugerencias.
 */
class SugerenciasHandler
{
    const TIPOS = ['evento', 'producto'];

    public static function listar($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $tipo = (string) $req->param('tipo', '');
        $id = (int) $req->param('id');
        $pagina = (int) $req->param('pagina');

        if (!in_array($tipo, self::TIPOS, true)) {
            return Response::error(400, 'Tipo inválido: tiene que ser evento o producto');
        }

        if ($id <= 0) {
            return Response::error(400, 'ID requerido');
        }

        try {
            $sugerencias = Sugerencias::para($db, $tipo, $id, $pagina > 0 ? $pagina : null);
        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }

        if ($sugerencias === null) {
            return Response::notFound($tipo === 'evento' ? 'Evento no encontrado' : 'Producto no encontrado');
        }

        return Response::ok(['sugerencias' => $sugerencias]);
    }
}
