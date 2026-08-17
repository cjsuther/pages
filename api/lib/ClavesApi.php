<?php

/**
 * Claves de API: credenciales de larga vida para que un programa actúe en
 * nombre de una persona.
 *
 * Existen porque el token de sesión vence a las 24 horas. Pegarlo en la
 * configuración de un cliente MCP significaría volver a pegarlo todos los
 * días, así que se cambia por algo que no venza y se pueda revocar.
 *
 * De la clave sólo se guarda el hash. Se muestra entera una única vez, al
 * generarla: si se pierde, se genera otra. Es el mismo trato que se le da a
 * una contraseña, y por el mismo motivo.
 */
class ClavesApi
{
    /** Prefijo visible, para que se reconozca de dónde salió si aparece suelta. */
    const PREFIJO = 'rzn_';

    /** Bytes de azar. 32 bytes son 64 caracteres hexadecimales. */
    const BYTES = 32;

    /** Cuántos caracteres del principio se guardan en claro para el listado. */
    const LARGO_PREFIJO = 12;

    /** Tope por usuario, para que una integración con un bug no llene la tabla. */
    const MAX_POR_USUARIO = 10;

    /**
     * Genera una clave nueva.
     *
     * @return array{ok: bool, clave?: string, id?: int, error?: string}
     *         `clave` viene sólo acá: es la única vez que existe en claro.
     */
    public static function generar($db, $userId, $nombre)
    {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            return ['ok' => false, 'error' => 'Poné un nombre para reconocer la clave'];
        }

        if (self::cuantasTiene($db, $userId) >= self::MAX_POR_USUARIO) {
            return ['ok' => false, 'error' => 'Llegaste al máximo de claves. Revocá alguna para crear otra.'];
        }

        $clave = self::PREFIJO . bin2hex(random_bytes(self::BYTES));

        $stmt = $db->prepare('
            INSERT INTO api_keys (user_id, nombre, hash, prefijo)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            (int) $userId,
            mb_substr($nombre, 0, 80),
            self::hash($clave),
            mb_substr($clave, 0, self::LARGO_PREFIJO),
        ]);

        return ['ok' => true, 'clave' => $clave, 'id' => (int) $db->lastInsertId()];
    }

    /**
     * Quién es el dueño de una clave.
     *
     * Devuelve el usuario con la misma forma que el payload del JWT, para que
     * lo que ya sabe leer una sesión no tenga que aprender un formato nuevo.
     *
     * @return array|null null si la clave no existe, está revocada o es basura
     */
    public static function usuario($db, $clave)
    {
        $clave = trim((string) $clave);

        if (strpos($clave, self::PREFIJO) !== 0) {
            return null;
        }

        $stmt = $db->prepare('
            SELECT k.id, k.user_id, u.email, u.name
            FROM api_keys k
            INNER JOIN users u ON u.id = k.user_id
            WHERE k.hash = ? AND k.revocada_en IS NULL
        ');
        $stmt->execute([self::hash($clave)]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        self::anotarUso($db, (int) $fila['id']);

        return [
            'user_id' => (int) $fila['user_id'],
            'email'   => $fila['email'],
            'name'    => $fila['name'],
            // Marca de dónde viene la sesión. Lo usa la administración de
            // claves para no dejar que una credencial fabrique otras: si eso
            // se permitiera, revocar la que se filtró no cerraría la puerta.
            'por_clave_api' => true,
        ];
    }

    /** Las claves vigentes de una persona, sin el secreto. */
    public static function listar($db, $userId)
    {
        $stmt = $db->prepare('
            SELECT id, nombre, prefijo, ultimo_uso_en, created_at
            FROM api_keys
            WHERE user_id = ? AND revocada_en IS NULL
            ORDER BY created_at DESC
        ');
        $stmt->execute([(int) $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Da de baja una clave.
     *
     * No se borra: si una clave se filtró, conviene que quede el registro de
     * que existió y hasta cuándo anduvo.
     */
    public static function revocar($db, $userId, $id)
    {
        $stmt = $db->prepare('
            UPDATE api_keys
            SET revocada_en = NOW()
            WHERE id = ? AND user_id = ? AND revocada_en IS NULL
        ');
        $stmt->execute([(int) $id, (int) $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * El hash con el que se guarda y se busca.
     *
     * SHA-256 y no un hash de contraseña: la clave son 32 bytes de azar, no
     * algo que alguien pueda adivinar a fuerza de diccionario, y la búsqueda
     * tiene que poder hacerse por índice en cada llamada.
     */
    public static function hash($clave)
    {
        return hash('sha256', (string) $clave);
    }

    private static function cuantasTiene($db, $userId)
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND revocada_en IS NULL');
        $stmt->execute([(int) $userId]);

        return (int) $stmt->fetchColumn();
    }

    private static function anotarUso($db, $id)
    {
        $stmt = $db->prepare('UPDATE api_keys SET ultimo_uso_en = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }
}
