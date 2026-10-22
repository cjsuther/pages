<?php

/**
 * La casilla SMTP propia de una página (#36): para que les escriba a sus
 * clientes desde su dirección y no desde la de Rezonar.
 *
 * Tres cuidados, porque es una credencial ajena y un servidor que elige quien
 * usa la página:
 *
 * - La contraseña se guarda cifrada con libsodium (secretbox). La clave es
 *   CLAVE_CIFRADO, de config.php. Sin clave no se guarda nada: nunca en claro.
 * - La contraseña no vuelve nunca al navegador: sólo si hay una cargada.
 * - El servidor tiene que ser público. Si no, el botón de prueba serviría
 *   para que cualquiera con una página tantee la red interna del hosting.
 *
 * Las entradas y los productos siguen saliendo por la casilla de la
 * plataforma: esto no los toca.
 */
class CuentaSmtp
{
    const SEGURIDADES = ['ssl', 'tls', 'ninguna'];

    /** Los puertos de correo. Cualquier otro sería usar el SMTP para tocar otro servicio. */
    const PUERTOS = [25, 465, 587, 2525];

    const PRUEBAS_POR_HORA = 5;

    /** Ráfagas de los envíos a clientes: lo que se usa si no se cambió, y los límites. */
    const RAFAGA_TAMANO = 20;
    const RAFAGA_MIN = 5;
    const RAFAGA_MAX = 100;
    const RAFAGA_PAUSA = 10;
    const PAUSA_MIN = 5;
    const PAUSA_MAX = 1440;
    const TOPE_MAX = 100000;

    /** Marca de versión, para poder rotar el formato sin romper lo ya guardado. */
    const PREFIJO = 'v1:';

    /** @var string|null|false La clave fijada por un test; false es "la de config.php". */
    private static $clave = false;

    /** @var callable|null host => string[] de IPv4. null es el DNS de verdad. */
    private static $resolver = null;

    // ------------------------------------------------------------ dobles

    /** Para los tests: fija la clave, o null para simular que falta. */
    public static function usarClave($clave)
    {
        self::$clave = $clave;
    }

    /** Para los tests: resuelve los nombres sin salir a la red. */
    public static function usarResolver(?callable $resolver = null)
    {
        self::$resolver = $resolver;
    }

    public static function olvidar()
    {
        self::$clave = false;
        self::$resolver = null;
    }

    // ----------------------------------------------------------- cifrado

    /** @return string|null 32 bytes, o null si no hay una clave utilizable */
    private static function clave()
    {
        if (self::$clave !== false) {
            $texto = self::$clave;
        } else {
            $texto = defined('CLAVE_CIFRADO') ? CLAVE_CIFRADO : null;
        }

        // La del ejemplo está publicada en el repositorio: es como no tener.
        if (!is_string($texto) || strlen($texto) < 32 || strpos($texto, 'CAMBIA_ESTO') === 0) {
            return null;
        }

        // Se deriva a 32 bytes exactos: la constante es texto de largo libre
        // y secretbox necesita una clave binaria de ese tamaño.
        return hash('sha256', $texto, true);
    }

    /** true si el servidor puede guardar y leer contraseñas SMTP. */
    public static function disponible()
    {
        return function_exists('sodium_crypto_secretbox') && self::clave() !== null;
    }

    /**
     * @return string Cifrado en base64, listo para guardar
     * @throws RuntimeException si no hay clave
     */
    public static function cifrar($texto)
    {
        $clave = self::clave();

        if ($clave === null || !function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('Falta CLAVE_CIFRADO en config.php');
        }

        // Un nonce nuevo cada vez: con el mismo, dos páginas con la misma
        // contraseña guardarían el mismo texto.
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIJO . base64_encode($nonce . sodium_crypto_secretbox((string) $texto, $nonce, $clave));
    }

    /** @return string|null null si el dato está corrupto o la clave cambió */
    public static function descifrar($guardado)
    {
        $clave = self::clave();
        $guardado = (string) $guardado;

        if ($clave === null || strpos($guardado, self::PREFIJO) !== 0) {
            return null;
        }

        $bruto = base64_decode(substr($guardado, strlen(self::PREFIJO)), true);

        if ($bruto === false || strlen($bruto) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $texto = sodium_crypto_secretbox_open(
            substr($bruto, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bruto, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $clave
        );

        // secretbox autentica: un texto manipulado o cifrado con otra clave
        // da false, no basura.
        return $texto === false ? null : $texto;
    }

    // -------------------------------------------------------- validación

    /**
     * Valida y normaliza lo que mandó el formulario.
     *
     * @param  array      $entrada
     * @param  array|null $anterior La fila guardada, si hay: una contraseña vacía la conserva
     * @return array ['error' => string] o ['datos' => array] (sin cifrar: contrasena null es "la de antes")
     */
    public static function validar(array $entrada, $anterior = null)
    {
        $host = strtolower(trim((string) self::texto($entrada, 'host')));

        if ($host === '') {
            return ['error' => 'Falta el servidor'];
        }

        if (!self::esNombreDeHost($host)) {
            return ['error' => 'El servidor no es válido. Es algo como smtp.gmail.com'];
        }

        $puerto = self::texto($entrada, 'port');

        if (!preg_match('/^\d{1,5}$/', trim((string) $puerto)) || (int) $puerto < 1 || (int) $puerto > 65535) {
            return ['error' => 'El puerto tiene que ser un número entre 1 y 65535'];
        }

        $puerto = (int) $puerto;

        if (!in_array($puerto, self::PUERTOS, true)) {
            return ['error' => 'Usá uno de los puertos de correo: 25, 465, 587 o 2525'];
        }

        $seguridad = (string) self::texto($entrada, 'seguridad');

        if (!in_array($seguridad, self::SEGURIDADES, true)) {
            return ['error' => 'La seguridad tiene que ser SSL, TLS o ninguna'];
        }

        $usuario = trim((string) self::texto($entrada, 'usuario'));

        if ($usuario === '') {
            return ['error' => 'Falta el usuario'];
        }

        if (strlen($usuario) > 255 || preg_match('/[\r\n]/', $usuario)) {
            return ['error' => 'El usuario no es válido'];
        }

        // Sin trim: un espacio puede ser parte de la contraseña.
        $contrasena = (string) self::texto($entrada, 'contrasena');

        if ($contrasena === '') {
            if (empty($anterior['contrasena_cifrada'])) {
                return ['error' => 'Falta la contraseña'];
            }
            $contrasena = null;
        } elseif (strlen($contrasena) > 255) {
            return ['error' => 'La contraseña es demasiado larga'];
        }

        $remitente = trim((string) self::texto($entrada, 'remitente_email'));

        if (!self::esEmail($remitente)) {
            return ['error' => 'El email del remitente no es válido'];
        }

        // Va en una cabecera: sin saltos de línea.
        $nombre = trim(preg_replace('/[\r\n]+/', ' ', (string) self::texto($entrada, 'remitente_nombre')));

        if (mb_strlen($nombre) > 255) {
            return ['error' => 'El nombre del remitente es demasiado largo'];
        }

        $responder = trim((string) self::texto($entrada, 'responder_a'));

        if ($responder !== '' && !self::esEmail($responder)) {
            return ['error' => 'El email de "Responder a" no es válido'];
        }

        return ['datos' => [
            'host' => $host,
            'port' => $puerto,
            'seguridad' => $seguridad,
            'usuario' => $usuario,
            'contrasena' => $contrasena,
            'remitente_email' => $remitente,
            'remitente_nombre' => $nombre === '' ? null : $nombre,
            'responder_a' => $responder === '' ? null : $responder,
        ]];
    }

    /**
     * Comprueba que el servidor sea público, resolviendo el nombre.
     *
     * Todas las direcciones del nombre tienen que serlo, y se devuelve la que
     * hay que usar: si se volviera a resolver al conectar, un DNS que cambia
     * entre medio podría llevarnos a la red interna igual.
     *
     * @return array ['ok' => true, 'ip' => string] o ['ok' => false, 'error' => string]
     */
    public static function revisarHost($host)
    {
        $host = strtolower(trim((string) $host));
        $interna = ['ok' => false, 'error' => 'Ese servidor es de una red interna. Usá el servidor de correo de tu proveedor.'];

        if ($host === 'localhost' || substr($host, -10) === '.localhost') {
            return $interna;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ips = [$host];
        } else {
            $resolver = self::$resolver !== null ? self::$resolver : function ($nombre) {
                return gethostbynamel($nombre);
            };
            $ips = call_user_func($resolver, $host);
        }

        if (!is_array($ips) || count($ips) === 0) {
            return ['ok' => false, 'error' => "No encontramos el servidor $host. Revisá que esté bien escrito."];
        }

        foreach ($ips as $ip) {
            if (!self::esIpPublica($ip)) {
                return $interna;
            }
        }

        return ['ok' => true, 'ip' => $ips[0]];
    }

    public static function esIpPublica($ip)
    {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        // Desde PHP 8.2: suma lo que las otras dos no cubren, como la red
        // compartida de los proveedores (100.64.0.0/10).
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }

    // ------------------------------------------------------------- datos

    public static function deLaPagina($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT page_id, host, port, seguridad, usuario, contrasena_cifrada,
                   remitente_email, remitente_nombre, responder_a, verificado_en, actualizado_en,
                   rafaga_tamano, rafaga_pausa_minutos, tope_diario, ultima_rafaga_en
            FROM page_smtp WHERE page_id = ?
        ');
        $stmt->execute([(int) $pageId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /**
     * Guarda la cuenta. Cambiarla borra la verificación: la prueba que salió
     * bien era de otra configuración.
     *
     * @param array  $datos             Lo que devolvió validar()
     * @param string $contrasenaCifrada La nueva cifrada, o la que ya estaba
     */
    public static function guardar($db, $pageId, array $datos, $contrasenaCifrada)
    {
        $stmt = $db->prepare('
            INSERT INTO page_smtp
                (page_id, host, port, seguridad, usuario, contrasena_cifrada,
                 remitente_email, remitente_nombre, responder_a, verificado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE
                host = VALUES(host), port = VALUES(port), seguridad = VALUES(seguridad),
                usuario = VALUES(usuario), contrasena_cifrada = VALUES(contrasena_cifrada),
                remitente_email = VALUES(remitente_email), remitente_nombre = VALUES(remitente_nombre),
                responder_a = VALUES(responder_a), verificado_en = NULL
        ');
        $stmt->execute([
            (int) $pageId,
            $datos['host'],
            $datos['port'],
            $datos['seguridad'],
            $datos['usuario'],
            $contrasenaCifrada,
            $datos['remitente_email'],
            $datos['remitente_nombre'],
            $datos['responder_a'],
        ]);
    }

    public static function quitar($db, $pageId)
    {
        $stmt = $db->prepare('DELETE FROM page_smtp WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);
    }

    /** Lo que ve el navegador: todo menos la contraseña. */
    public static function paraMostrar($fila)
    {
        if ($fila === null) {
            return null;
        }

        return [
            'host' => $fila['host'],
            'port' => (int) $fila['port'],
            'seguridad' => $fila['seguridad'],
            'usuario' => $fila['usuario'],
            'tiene_contrasena' => !empty($fila['contrasena_cifrada']),
            'remitente_email' => $fila['remitente_email'],
            'remitente_nombre' => $fila['remitente_nombre'],
            'responder_a' => $fila['responder_a'],
            'verificado_en' => $fila['verificado_en'],
            'rafagas' => self::rafagas($fila),
        ];
    }

    // ----------------------------------------------------------- ráfagas

    /**
     * Cómo se reparten los envíos a clientes (#37): cuántos mails por ráfaga,
     * cuántos minutos entre una y otra, y un tope opcional por día. Una
     * casilla que manda cientos de mails seguidos termina marcada como spam.
     *
     * @return array{tamano: int, pausa_minutos: int, tope_diario: int|null}
     */
    public static function rafagas($fila)
    {
        $tamano = isset($fila['rafaga_tamano']) ? (int) $fila['rafaga_tamano'] : self::RAFAGA_TAMANO;
        $pausa = isset($fila['rafaga_pausa_minutos']) ? (int) $fila['rafaga_pausa_minutos'] : self::RAFAGA_PAUSA;
        $tope = isset($fila['tope_diario']) && (int) $fila['tope_diario'] > 0 ? (int) $fila['tope_diario'] : null;

        // Lo guardado ya se validó; esto es por si alguien lo tocó a mano.
        return [
            'tamano' => max(self::RAFAGA_MIN, min(self::RAFAGA_MAX, $tamano)),
            'pausa_minutos' => max(self::PAUSA_MIN, $pausa),
            'tope_diario' => $tope,
        ];
    }

    /** @return array ['error' => string] o ['datos' => array] */
    public static function validarRafagas(array $entrada)
    {
        $tamano = self::texto($entrada, 'tamano');

        if (!preg_match('/^\d+$/', trim((string) $tamano)) || (int) $tamano < self::RAFAGA_MIN || (int) $tamano > self::RAFAGA_MAX) {
            return ['error' => 'Cada ráfaga tiene que ser de entre ' . self::RAFAGA_MIN . ' y ' . self::RAFAGA_MAX . ' mails'];
        }

        $pausa = self::texto($entrada, 'pausa_minutos');

        if (!preg_match('/^\d+$/', trim((string) $pausa)) || (int) $pausa < self::PAUSA_MIN || (int) $pausa > self::PAUSA_MAX) {
            return ['error' => 'La pausa entre ráfagas tiene que ser de entre ' . self::PAUSA_MIN . ' y ' . self::PAUSA_MAX . ' minutos'];
        }

        $tope = trim((string) self::texto($entrada, 'tope_diario'));

        if ($tope !== '' && (!preg_match('/^\d+$/', $tope) || (int) $tope < 1 || (int) $tope > self::TOPE_MAX)) {
            return ['error' => 'El tope diario tiene que ser un número entre 1 y ' . self::TOPE_MAX . ', o quedar vacío'];
        }

        return ['datos' => [
            'tamano' => (int) $tamano,
            'pausa_minutos' => (int) $pausa,
            'tope_diario' => $tope === '' ? null : (int) $tope,
        ]];
    }

    public static function guardarRafagas($db, $pageId, array $datos)
    {
        $stmt = $db->prepare('
            UPDATE page_smtp SET rafaga_tamano = ?, rafaga_pausa_minutos = ?, tope_diario = ?
            WHERE page_id = ?
        ');
        $stmt->execute([$datos['tamano'], $datos['pausa_minutos'], $datos['tope_diario'], (int) $pageId]);
    }

    // ----------------------------------------------------------- pruebas

    public static function pruebasDeLaUltimaHora($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM page_smtp_pruebas
            WHERE page_id = ? AND creado_en >= (NOW() - INTERVAL 1 HOUR)
        ');
        $stmt->execute([(int) $pageId]);

        return (int) $stmt->fetchColumn();
    }

    /** Anota la prueba antes de mandarla: una que se cuelga también cuenta. */
    public static function anotarPrueba($db, $pageId, $userId)
    {
        $stmt = $db->prepare('INSERT INTO page_smtp_pruebas (page_id, user_id, ok) VALUES (?, ?, 0)');
        $stmt->execute([(int) $pageId, $userId === null ? null : (int) $userId]);

        return (int) $db->lastInsertId();
    }

    public static function pruebaExitosa($db, $pageId, $pruebaId)
    {
        $db->prepare('UPDATE page_smtp_pruebas SET ok = 1 WHERE id = ?')->execute([(int) $pruebaId]);
        $db->prepare('UPDATE page_smtp SET verificado_en = NOW() WHERE page_id = ?')->execute([(int) $pageId]);
    }

    /** Por qué falló, en castellano llano. */
    public static function explicar($motivo, $error, $host, $puerto)
    {
        switch ($motivo) {
            case 'autenticacion':
                return 'El servidor rechazó el usuario o la contraseña. Si es Gmail, tenés que usar una '
                    . 'contraseña de aplicación: la contraseña normal de la cuenta no funciona.';
            case 'conexion':
                return "No pudimos conectarnos a $host por el puerto $puerto. "
                    . 'Revisá el servidor, el puerto y la seguridad.';
            case 'certificado':
                return "El certificado de seguridad de $host no es válido. "
                    . 'Revisá que el servidor esté bien escrito, tal como te lo da tu proveedor.';
            default:
                return 'El servidor de correo no aceptó el envío'
                    . ($error ? ': ' . $error : '.');
        }
    }

    // ----------------------------------------------------------- interno

    private static function texto(array $entrada, $clave)
    {
        if (!isset($entrada[$clave]) || is_array($entrada[$clave])) {
            return '';
        }

        return $entrada[$clave];
    }

    /** Un nombre con dominio (smtp.gmail.com) o una IPv4. Sin puertos, rutas ni esquemas. */
    private static function esNombreDeHost($host)
    {
        if (strlen($host) > 253) {
            return false;
        }

        $etiqueta = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        return (bool) preg_match('/^' . $etiqueta . '(?:\.' . $etiqueta . ')+$/', $host);
    }

    private static function esEmail($email)
    {
        return $email !== '' && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
