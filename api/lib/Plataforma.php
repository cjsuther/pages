<?php

/**
 * Quién administra la plataforma, no una página.
 *
 * Es el acceso de soporte: poder entrar a editar la página de cualquiera para
 * arreglarle algo sin pedirle la contraseña ni que lo invite como
 * administrador. Se define por correo en config.php (SUPERADMIN_EMAILS) y no
 * en la base, para que sumar o sacar a alguien sea un cambio revisable y no
 * una fila que se puede tocar desde cualquier lado.
 *
 * El alcance es deliberadamente "editar": PageAccess::isOwner sigue mirando
 * sólo al dueño real, así que borrar una página o repartir sus
 * administradores no entra acá. Nadie pierde el control de lo suyo.
 */
class Plataforma
{
    /** Cache por petición: la misma sesión pregunta esto muchas veces. */
    private static $cache = [];

    /** Los correos habilitados, normalizados. Vacío si no hay configuración. */
    public static function correos()
    {
        if (!defined('SUPERADMIN_EMAILS')) {
            return [];
        }

        $crudos = SUPERADMIN_EMAILS;

        // Se acepta una lista o un solo correo suelto: config.php lo escribe
        // una persona y el formato de más de uno no es evidente.
        if (!is_array($crudos)) {
            $crudos = explode(',', (string) $crudos);
        }

        $correos = [];

        foreach ($crudos as $correo) {
            $limpio = strtolower(trim((string) $correo));

            if ($limpio !== '') {
                $correos[] = $limpio;
            }
        }

        return $correos;
    }

    /** ¿Este correo administra la plataforma? */
    public static function esCorreoAdmin($correo)
    {
        $correo = strtolower(trim((string) $correo));

        if ($correo === '') {
            return false;
        }

        return in_array($correo, self::correos(), true);
    }

    /**
     * ¿Este usuario administra la plataforma?
     *
     * Resuelve el correo en la base y no desde el token: si a alguien se le
     * saca el acceso, deja de tenerlo en la petición siguiente y no cuando se
     * le vence un token que dura un año.
     */
    public static function esAdmin($db, $userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0 || self::correos() === []) {
            return false;
        }

        if (array_key_exists($userId, self::$cache)) {
            return self::$cache[$userId];
        }

        $stmt = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $correo = $stmt->fetchColumn();

        return self::$cache[$userId] = ($correo !== false && self::esCorreoAdmin($correo));
    }

    /** Olvida lo cacheado. Sólo lo necesitan los tests. */
    public static function olvidar()
    {
        self::$cache = [];
    }
}
