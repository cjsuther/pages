<?php

/**
 * Links con clave de un evento: los que se le pasan a alguien que no tiene
 * cuenta en la plataforma.
 *
 * Hay dos tipos y comparten todo salvo qué pantalla abren: el de la puerta,
 * para marcar quién entró, y el de la venta, para mirar cómo viene
 * vendiéndose el show. Las reglas son las mismas y por eso están en un solo
 * lugar:
 *
 * - La clave es la credencial, así que no se guarda en claro: se busca por su
 *   hash. Quien lea la base no puede entrar con lo que ve.
 * - Se guarda además cifrada, para que quien organiza pueda volver a copiar el
 *   link sin generar otro y dejar afuera a quien ya lo tenía.
 * - Hay uno por evento y por tipo: generar otro reemplaza al anterior, y eso
 *   es lo que permite sacarle el acceso a alguien.
 * - Viaja después del # de la dirección, que el navegador no manda al pedir la
 *   página: no queda escrita en los registros del servidor.
 */
class ClaveDeEvento
{
    const PUERTA = 'puerta';
    const VENTAS = 'ventas';

    /** Qué pantalla abre cada tipo. */
    private static $pantallas = [
        self::PUERTA => '/puerta',
        self::VENTAS => '/venta',
    ];

    public static function tipoValido($tipo)
    {
        return isset(self::$pantallas[$tipo]);
    }

    /** Genera la clave del evento y la devuelve en claro. */
    public static function generar($db, $linkId, $tipo)
    {
        $clave = bin2hex(random_bytes(16));

        $stmt = $db->prepare('
            INSERT INTO event_access_links (link_id, tipo, clave_hash, clave_cifrada)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                clave_hash = VALUES(clave_hash),
                clave_cifrada = VALUES(clave_cifrada),
                created_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([(int) $linkId, $tipo, self::hash($clave), Cripto::cifrar($clave)]);

        return $clave;
    }

    /** La clave vigente, en claro, o null si el evento no tiene ese link. */
    public static function clave($db, $linkId, $tipo)
    {
        $stmt = $db->prepare('SELECT clave_cifrada FROM event_access_links WHERE link_id = ? AND tipo = ?');
        $stmt->execute([(int) $linkId, $tipo]);
        $cifrada = $stmt->fetchColumn();

        if ($cifrada === false) {
            return null;
        }

        $clave = Cripto::descifrar($cifrada);

        return $clave === null || $clave === false || $clave === '' ? null : $clave;
    }

    public static function revocar($db, $linkId, $tipo)
    {
        $stmt = $db->prepare('DELETE FROM event_access_links WHERE link_id = ? AND tipo = ?');
        $stmt->execute([(int) $linkId, $tipo]);
    }

    /** La dirección que se comparte. La clave va después del #. */
    public static function url($clave, $tipo)
    {
        $pantalla = isset(self::$pantallas[$tipo]) ? self::$pantallas[$tipo] : '/';

        return rtrim(FRONTEND_URL, '/') . $pantalla . '#' . $clave;
    }

    /**
     * El evento al que da acceso una clave de ese tipo, o null.
     *
     * El tipo se exige en la consulta: la clave de la puerta no puede abrir la
     * pantalla de ventas ni al revés, aunque las dos salgan de la misma tabla.
     */
    public static function evento($db, $clave, $tipo)
    {
        $clave = (string) $clave;

        // Una clave con otra forma no puede ser válida: no hace falta ir a la base.
        if (!preg_match('/^[a-f0-9]{32}$/', $clave)) {
            return null;
        }

        $stmt = $db->prepare('
            SELECT l.id, l.text, l.event_date, l.event_time, l.event_address, l.image_url,
                   p.title AS pagina, p.url_slug,
                   p.primary_color, p.secondary_color, p.card_color, p.title_color,
                   p.background_color, p.text_color
            FROM event_access_links al
            INNER JOIN links l ON l.id = al.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            INNER JOIN pages p ON p.id = lg.page_id
            WHERE al.clave_hash = ? AND al.tipo = ?
        ');
        $stmt->execute([self::hash($clave), $tipo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    public static function hash($clave)
    {
        return hash('sha256', (string) $clave);
    }
}
