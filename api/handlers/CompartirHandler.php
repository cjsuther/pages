<?php

/**
 * El link para compartir cómo viene la venta de un evento.
 *
 * Dos endpoints, igual que la puerta:
 *   - link:  quien administra el evento lo genera, lo ve o lo revoca
 *   - venta: quien tiene el link mira, sin cuenta y sin sesión
 */
class CompartirHandler
{
    // ------------------------------------------------------------------ link

    public static function link($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        $linkId = (int) $req->param('link_id');

        if (!$linkId) {
            return Response::error(400, 'link_id requerido');
        }

        if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
            return Response::error(403, 'No podés administrar este evento');
        }

        if ($req->method === 'GET') {
            $clave = ClaveDeEvento::clave($db, $linkId, ClaveDeEvento::VENTAS);

            return Response::ok(['url' => $clave === null ? null : ClaveDeEvento::url($clave, ClaveDeEvento::VENTAS)]);
        }

        // Generar otro reemplaza al anterior: es la forma de dejar afuera a
        // quien tenía el link.
        if ($req->method === 'POST') {
            if (!Cripto::disponible()) {
                return Response::error(503, 'El servidor no puede generar links compartidos en este momento');
            }

            $clave = ClaveDeEvento::generar($db, $linkId, ClaveDeEvento::VENTAS);

            return Response::ok(['url' => ClaveDeEvento::url($clave, ClaveDeEvento::VENTAS)]);
        }

        if ($req->method === 'DELETE') {
            ClaveDeEvento::revocar($db, $linkId, ClaveDeEvento::VENTAS);

            return Response::ok(['url' => null]);
        }

        return Response::methodNotAllowed();
    }

    // ----------------------------------------------------------------- venta

    /**
     * Cómo viene la venta, para quien tiene el link.
     *
     * Va por POST aunque sea una lectura: la clave viaja en el cuerpo y no en
     * la URL, para que no quede escrita en los registros del servidor.
     */
    public static function venta($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $evento = ClaveDeEvento::evento($db, $req->input('clave'), ClaveDeEvento::VENTAS);

        if ($evento === null) {
            // Mismo mensaje si la clave no existe o se revocó: la diferencia
            // no le sirve a quien mira, y a quien prueba claves le diría
            // cuáles existieron.
            return Response::error(404, 'Este link no es válido. Pedile uno nuevo a quien organiza.');
        }

        return Response::ok([
            'evento' => [
                'text'          => $evento['text'],
                'event_date'    => $evento['event_date'],
                'event_time'    => $evento['event_time'],
                'event_address' => $evento['event_address'],
                'image_url'     => $evento['image_url'],
                'pagina'        => $evento['pagina'],
                'url_slug'      => $evento['url_slug'],
                // Los colores de la página: quien abre el link reconoce de
                // quién es el show, igual que en el detalle del evento.
                'colores' => [
                    'primary_color'    => $evento['primary_color'],
                    'secondary_color'  => $evento['secondary_color'],
                    'card_color'       => $evento['card_color'],
                    'title_color'      => $evento['title_color'],
                    'background_color' => $evento['background_color'],
                    'text_color'       => $evento['text_color'],
                ],
            ],
        ] + VentasCompartidas::estado($db, (int) $evento['id']));
    }
}
