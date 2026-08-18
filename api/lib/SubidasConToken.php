<?php

/**
 * Subidas de imagen con un link de un solo uso.
 *
 * Existe por una limitación que no se puede sortear: los argumentos de una
 * herramienta MCP son texto que el modelo escribe, y una imagen de verdad no
 * entra ahí. Así que en vez de pedirle al asistente que mande el archivo, se
 * le da a la persona una dirección donde soltarlo.
 *
 * El link vive poco y sirve una sola vez. No lleva sesión: el token es la
 * credencial, y por eso se guarda hasheado y se ata de antemano al evento que
 * va a recibir la imagen —quien tenga el link no puede elegir otro—.
 */
class SubidasConToken
{
    /** Minutos que vive el link. Alcanza para ir a buscar el archivo. */
    const VIDA_MINUTOS = 30;

    /** Crea el permiso y devuelve el token en claro, que sólo existe acá. */
    public static function crear($db, $userId, $linkId)
    {
        $token = bin2hex(random_bytes(24));

        $stmt = $db->prepare('
            INSERT INTO image_uploads (token_hash, user_id, link_id, expira_en)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            self::hash($token),
            (int) $userId,
            (int) $linkId,
            date('Y-m-d H:i:s', time() + self::VIDA_MINUTOS * 60),
        ]);

        return $token;
    }

    /**
     * El permiso vigente que corresponde a un token.
     *
     * @return array|null null si no existe, ya se usó o venció
     */
    public static function vigente($db, $token)
    {
        $stmt = $db->prepare('SELECT * FROM image_uploads WHERE token_hash = ?');
        $stmt->execute([self::hash((string) $token)]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false || $fila['usado_en'] !== null) {
            return null;
        }

        return strtotime($fila['expira_en']) < time() ? null : $fila;
    }

    /**
     * Marca el permiso como usado.
     *
     * La condición va en el UPDATE y no sólo en la lectura: dos personas
     * soltando el archivo a la vez leerían lo mismo, y sólo una tiene que
     * poder seguir.
     */
    public static function marcarUsado($db, $id)
    {
        $stmt = $db->prepare('UPDATE image_uploads SET usado_en = NOW() WHERE id = ? AND usado_en IS NULL');
        $stmt->execute([(int) $id]);

        return $stmt->rowCount() > 0;
    }

    public static function hash($token)
    {
        return hash('sha256', (string) $token);
    }
}
