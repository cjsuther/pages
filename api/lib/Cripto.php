<?php

/**
 * Cifrado simétrico para secretos de terceros guardados en la base.
 *
 * Se usa para el access token de Mercado Pago de cada página: es una
 * credencial ajena que permite cobrar en nombre del dueño, así que un volcado
 * de la base no puede alcanzar para usarla. La clave vive en config.php, que
 * no está en el repositorio ni se despliega.
 *
 * AES-256-GCM y no CBC: GCM autentica además de cifrar, así que un texto
 * manipulado falla al descifrar en lugar de devolver basura silenciosamente.
 */
class Cripto
{
    const METODO = 'aes-256-gcm';
    const LARGO_IV = 12;   // 96 bits, lo recomendado para GCM
    const LARGO_TAG = 16;

    /** Marca de versión, para poder rotar el formato sin romper lo ya guardado. */
    const PREFIJO = 'v1:';

    /**
     * @throws RuntimeException si falta la clave o es demasiado corta
     */
    private static function clave()
    {
        if (!defined('PAYMENTS_ENCRYPTION_KEY') || PAYMENTS_ENCRYPTION_KEY === '') {
            throw new RuntimeException('Falta PAYMENTS_ENCRYPTION_KEY en config.php');
        }

        $clave = PAYMENTS_ENCRYPTION_KEY;

        if (strlen($clave) < 32) {
            throw new RuntimeException('PAYMENTS_ENCRYPTION_KEY es demasiado corta (mínimo 32 caracteres)');
        }

        // Se deriva a 32 bytes exactos: la constante es texto legible de largo
        // arbitrario y AES-256 necesita una clave binaria de 256 bits.
        return hash('sha256', $clave, true);
    }

    /** true si el servidor está en condiciones de guardar credenciales. */
    public static function disponible()
    {
        if (!function_exists('openssl_encrypt')) {
            return false;
        }

        try {
            self::clave();
        } catch (RuntimeException $e) {
            return false;
        }

        return true;
    }

    /**
     * @param  string $texto
     * @return string Cifrado en base64, listo para guardar
     */
    public static function cifrar($texto)
    {
        $iv = random_bytes(self::LARGO_IV);
        $tag = '';

        $cifrado = openssl_encrypt(
            (string) $texto,
            self::METODO,
            self::clave(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cifrado === false) {
            throw new RuntimeException('No se pudo cifrar la credencial');
        }

        return self::PREFIJO . base64_encode($iv . $tag . $cifrado);
    }

    /**
     * @param  string $guardado Lo que devolvió cifrar()
     * @return string|null      null si el dato está corrupto o la clave cambió
     */
    public static function descifrar($guardado)
    {
        $guardado = (string) $guardado;

        if (strpos($guardado, self::PREFIJO) !== 0) {
            return null;
        }

        $bruto = base64_decode(substr($guardado, strlen(self::PREFIJO)), true);

        if ($bruto === false || strlen($bruto) <= self::LARGO_IV + self::LARGO_TAG) {
            return null;
        }

        $iv = substr($bruto, 0, self::LARGO_IV);
        $tag = substr($bruto, self::LARGO_IV, self::LARGO_TAG);
        $cifrado = substr($bruto, self::LARGO_IV + self::LARGO_TAG);

        $texto = openssl_decrypt($cifrado, self::METODO, self::clave(), OPENSSL_RAW_DATA, $iv, $tag);

        // Con GCM esto es false cuando el texto fue manipulado o la clave no
        // es la que lo cifró, no sólo cuando el formato está mal.
        return $texto === false ? null : $texto;
    }

    /**
     * Los últimos cuatro caracteres, para que el dueño reconozca qué credencial
     * tiene cargada sin que se la devolvamos entera.
     */
    public static function ultimos4($texto)
    {
        return substr((string) $texto, -4);
    }
}
