<?php

/**
 * Las bajas de los mails de una página (#37).
 *
 * Cada mail lleva un link para no recibir más mails de esa página. El link
 * lleva un token firmado con JWT_SECRET: no hace falta guardarlo, y nadie
 * puede armar uno para dar de baja a otro. La baja vale por página: el mismo
 * email puede seguir recibiendo los de otra.
 *
 * Los emails se guardan en minúsculas: es la misma clave con la que Clientes
 * identifica a una persona.
 */
class BajasDeCorreo
{
    // -------------------------------------------------------------- token

    public static function token($pageId, $email)
    {
        $datos = (int) $pageId . ':' . self::clave($email);

        return self::base64($datos) . '.' . self::base64(self::firma($datos));
    }

    /** @return array{page_id: int, email: string}|null */
    public static function leerToken($token)
    {
        if (!is_string($token) || strlen($token) > 1000 || substr_count($token, '.') !== 1) {
            return null;
        }

        list($datos64, $firma64) = explode('.', $token);
        $datos = self::desdeBase64($datos64);
        $firma = self::desdeBase64($firma64);

        if ($datos === null || $firma === null || !hash_equals(self::firma($datos), $firma)) {
            return null;
        }

        if (!preg_match('/^(\d+):(.+)$/s', $datos, $m) || (int) $m[1] <= 0) {
            return null;
        }

        return ['page_id' => (int) $m[1], 'email' => $m[2]];
    }

    /** La página de baja, para el link del pie. */
    public static function urlPagina($token)
    {
        return rtrim(FRONTEND_URL, '/') . '/baja-de-mails?t=' . rawurlencode($token);
    }

    /** La baja de un solo paso, para List-Unsubscribe: el lector de correo la pide por POST. */
    public static function urlUnClic($token)
    {
        return rtrim(UPLOAD_URL, '/') . '/public/baja.php?t=' . rawurlencode($token);
    }

    /** Las cabeceras que hacen aparecer el botón "Anular suscripción" de Gmail y compañía. */
    public static function cabeceras($token)
    {
        return [
            'List-Unsubscribe' => '<' . self::urlUnClic($token) . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    // -------------------------------------------------------------- datos

    public static function dar($db, $pageId, $email)
    {
        $stmt = $db->prepare('INSERT IGNORE INTO bajas_de_correo (page_id, email) VALUES (?, ?)');
        $stmt->execute([(int) $pageId, self::clave($email)]);
    }

    public static function estaDeBaja($db, $pageId, $email)
    {
        $stmt = $db->prepare('SELECT 1 FROM bajas_de_correo WHERE page_id = ? AND email = ?');
        $stmt->execute([(int) $pageId, self::clave($email)]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return array<string, true> email en minúsculas => true */
    public static function dePagina($db, $pageId)
    {
        $stmt = $db->prepare('SELECT email FROM bajas_de_correo WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);

        $bajas = [];

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $bajas[self::clave($email)] = true;
        }

        return $bajas;
    }

    public static function clave($email)
    {
        return mb_strtolower(trim((string) $email));
    }

    // ------------------------------------------------------------ interno

    private static function firma($datos)
    {
        return hash_hmac('sha256', 'baja-de-correo:' . $datos, JWT_SECRET, true);
    }

    private static function base64($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function desdeBase64($texto)
    {
        if ($texto === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $texto)) {
            return null;
        }

        $bytes = base64_decode(strtr($texto, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
