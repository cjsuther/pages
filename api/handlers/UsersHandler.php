<?php

/**
 * Perfil y ubicación del usuario.
 *
 * Extraído de api/users/profile.php y api/users/location.php.
 */
class UsersHandler
{
    const MAX_LONGITUD_NOMBRE = 255;

    // ---------------------------------------------------------------- profile

    public static function profile($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'GET') {
            $stmt = $db->prepare('SELECT id, email, name FROM users WHERE id = ?');
            $stmt->execute([$req->userId()]);

            return Response::ok(['user' => $stmt->fetch(PDO::FETCH_ASSOC)]);
        }

        if ($req->method === 'PUT') {
            return self::actualizarNombre($db, $req);
        }

        return Response::error(405, 'Método no permitido');
    }

    private static function actualizarNombre($db, Request $req)
    {
        $nombre = $req->has('name') ? trim($req->input('name')) : null;

        if ($nombre !== null && strlen($nombre) > self::MAX_LONGITUD_NOMBRE) {
            return Response::error(400, 'El nombre no puede superar los ' . self::MAX_LONGITUD_NOMBRE . ' caracteres');
        }

        // Un nombre vacío se guarda como NULL, no como cadena vacía.
        $aGuardar = $nombre ?: null;

        $stmt = $db->prepare('UPDATE users SET name = ? WHERE id = ?');
        $stmt->execute([$aGuardar, $req->userId()]);

        return Response::ok([
            'success' => true,
            'user' => [
                'id' => $req->userId(),
                'email' => isset($req->user['email']) ? $req->user['email'] : null,
                'name' => $aGuardar,
            ],
        ]);
    }

    // --------------------------------------------------------------- location

    public static function location($db, Request $req)
    {
        if (!$req->hasBearerToken()) {
            return Response::unauthorized('Token no proporcionado');
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'GET') {
            return self::verUbicacion($db, $req);
        }

        if ($req->method === 'POST') {
            return self::guardarUbicacion($db, $req);
        }

        // El endpoint original no contemplaba otros métodos y respondía 200 con
        // cuerpo vacío. Se responde 405, que es lo que el cliente espera.
        return Response::error(405, 'Método no permitido');
    }

    private static function verUbicacion($db, Request $req)
    {
        $stmt = $db->prepare('SELECT location_latitude, location_longitude, location_name, last_location_update FROM users WHERE id = ?');
        $stmt->execute([$req->userId()]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            return Response::notFound('Usuario no encontrado');
        }

        return Response::ok([
            'latitude' => $fila['location_latitude'] ? (float) $fila['location_latitude'] : null,
            'longitude' => $fila['location_longitude'] ? (float) $fila['location_longitude'] : null,
            'location_name' => $fila['location_name'],
            'last_update' => $fila['last_location_update'],
        ]);
    }

    private static function guardarUbicacion($db, Request $req)
    {
        $latitud = $req->input('latitude');
        $longitud = $req->input('longitude');
        $nombre = $req->input('address');

        if ($latitud === null || $longitud === null) {
            return Response::error(400, 'Latitud y longitud son requeridas');
        }

        if (!self::coordenadasValidas($latitud, $longitud)) {
            return Response::error(400, 'Coordenadas inválidas');
        }

        $stmt = $db->prepare('UPDATE users SET location_latitude = ?, location_longitude = ?, location_name = ?, last_location_update = NOW() WHERE id = ?');
        $stmt->execute([$latitud, $longitud, $nombre, $req->userId()]);

        return Response::ok([
            'success' => true,
            'message' => 'Ubicación actualizada correctamente',
            'latitude' => (float) $latitud,
            'longitude' => (float) $longitud,
            'location_name' => $nombre,
        ]);
    }

    public static function coordenadasValidas($latitud, $longitud)
    {
        return $latitud >= -90 && $latitud <= 90
            && $longitud >= -180 && $longitud <= 180;
    }
}
