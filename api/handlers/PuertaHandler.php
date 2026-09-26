<?php

/**
 * Control de ingreso: el link de puerta y lo que se hace con él.
 *
 * Dos endpoints:
 *   - link:   quien administra el evento genera, ve o revoca el link de puerta
 *   - puerta: quien está en la puerta, con la clave del link y sin sesión,
 *             ve la lista y marca quién entró
 */
class PuertaHandler
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
            $clave = Puerta::claveDelEvento($db, $linkId);

            return Response::ok(['url' => $clave === null ? null : Puerta::url($clave)]);
        }

        // POST genera uno nuevo, y si ya había uno lo reemplaza: quien tenía
        // el anterior queda afuera.
        if ($req->method === 'POST') {
            if (!Cripto::disponible()) {
                return Response::error(503, 'El servidor no puede generar links de puerta en este momento');
            }

            return Response::ok(['url' => Puerta::url(Puerta::generarClave($db, $linkId))]);
        }

        if ($req->method === 'DELETE') {
            Puerta::revocar($db, $linkId);

            return Response::ok(['url' => null]);
        }

        return Response::methodNotAllowed();
    }

    // ---------------------------------------------------------------- puerta

    /**
     * Todo lo que hace la pantalla de la puerta, en un solo endpoint.
     *
     * Va siempre por POST, también para leer: la clave viaja en el cuerpo y
     * no en la URL, para que no quede escrita en ningún registro.
     *
     * Acciones: estado (la lista), mirar (una entrada), ingresar y deshacer.
     */
    public static function puerta($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $evento = Puerta::eventoDeLaClave($db, $req->input('clave'));

        if ($evento === null) {
            // Mismo mensaje si la clave no existe o se revocó: la diferencia
            // no le sirve a quien está en la puerta, y a quien prueba claves
            // le diría cuáles existieron.
            return Response::error(404, 'Este link de puerta no es válido. Pedile uno nuevo a quien organiza.');
        }

        $linkId = (int) $evento['id'];
        $accion = (string) $req->input('accion', 'estado');
        $codigo = (string) $req->input('codigo', '');
        $cantidad = (int) $req->input('cantidad', 1);

        switch ($accion) {
            case 'estado':
                return Response::ok(['evento' => self::evento($evento)] + Puerta::lista($db, $linkId));

            case 'mirar':
                return Response::ok(Puerta::mirar($db, $linkId, $codigo));

            case 'ingresar':
                return Response::ok(Puerta::ingresar($db, $linkId, $codigo, $cantidad));

            case 'deshacer':
                return Response::ok(Puerta::deshacer($db, $linkId, $codigo, $cantidad));
        }

        return Response::error(400, 'Acción desconocida');
    }

    private static function evento(array $evento)
    {
        return [
            'text'          => $evento['text'],
            'event_date'    => $evento['event_date'],
            'event_time'    => $evento['event_time'],
            'event_address' => $evento['event_address'],
            'pagina'        => $evento['pagina'],
            // Los colores de la página: la pantalla se pinta con ellos, así
            // que una página oscura no manda a nadie a una puerta blanca que
            // encandila a las once de la noche.
            'colores' => [
                'primary_color'    => $evento['primary_color'],
                'secondary_color'  => $evento['secondary_color'],
                'card_color'       => $evento['card_color'],
                'title_color'      => $evento['title_color'],
                'background_color' => $evento['background_color'],
                'text_color'       => $evento['text_color'],
            ],
        ];
    }
}
