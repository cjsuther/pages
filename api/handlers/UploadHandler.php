<?php

/**
 * Subida de imágenes.
 *
 * Extraído de api/upload/image.php con una corrección de seguridad: la
 * extensión del archivo guardado ya no se toma del nombre que envía el
 * cliente, sino del tipo de imagen que detecta el servidor.
 *
 * El código original hacía pathinfo($file['name'], PATHINFO_EXTENSION), de modo
 * que subir una imagen válida llamada "x.php" la guardaba como .php dentro de
 * api/uploads/, que es servido por Apache con PHP habilitado. Combinado con que
 * $file['type'] lo controla el cliente, eso permitía ejecución remota de código
 * a cualquier usuario registrado.
 */
class UploadHandler
{
    const MAX_BYTES = 5242880; // 5 MB

    /** Tipos aceptados y la extensión con la que se guarda cada uno. */
    private static $extensionPorTipoDetectado = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /** Tipos MIME declarados por el cliente que se aceptan. */
    private static $tiposDeclaradosPermitidos = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
    ];

    public static function image($db, Request $req, FileStorage $storage = null)
    {
        $storage = $storage === null ? new FileStorage() : $storage;

        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if (!isset($req->files['image'])) {
            return Response::error(400, 'No image file provided');
        }

        $file = $req->files['image'];

        $tipoDeclarado = isset($file['type']) ? $file['type'] : '';
        if (!in_array($tipoDeclarado, self::$tiposDeclaradosPermitidos, true)) {
            return Response::error(400, 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed');
        }

        if (isset($file['size']) && $file['size'] > self::MAX_BYTES) {
            return Response::error(400, 'File size too large. Maximum 5MB allowed');
        }

        $info = $storage->imageInfo(isset($file['tmp_name']) ? $file['tmp_name'] : '');

        if ($info === false) {
            return Response::error(400, 'File is not a valid image');
        }

        // La extensión sale del tipo real detectado, nunca del nombre recibido.
        $extension = self::extensionSegura($info);

        if ($extension === null) {
            return Response::error(400, 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed');
        }

        $uploadDir = dirname(__DIR__) . '/uploads/';
        $storage->ensureDir($uploadDir);

        $filename = uniqid() . '_' . time() . '.' . $extension;

        if (!$storage->moveUploaded($file['tmp_name'], $uploadDir . $filename)) {
            return Response::error(500, 'Failed to upload file');
        }

        return Response::ok(['url' => UPLOAD_URL . '/uploads/' . $filename]);
    }

    /**
     * Recibe el archivo que alguien suelta en un link de un solo uso.
     *
     * No lleva sesión: el token es la credencial. Por eso se revalida todo
     * acá —que esté vigente, que no se haya usado, y que quien lo pidió
     * siga pudiendo administrar ese evento— en lugar de confiar en que el
     * permiso se comprobó al emitirlo.
     */
    public static function conToken($db, Request $req, FileStorage $storage = null)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $permiso = SubidasConToken::vigente($db, $req->param('token'));

        if ($permiso === null) {
            return Response::error(404, 'Este link ya se usó o venció. Pedí uno nuevo.');
        }

        if (!PageAccess::canManageLink($db, (int) $permiso['link_id'], (int) $permiso['user_id'])) {
            return Response::error(403, 'Quien pidió este link ya no administra ese evento');
        }

        if (!isset($req->files['image']['tmp_name'])) {
            return Response::error(400, 'No llegó ninguna imagen');
        }

        $storage = $storage === null ? new FileStorage() : $storage;
        $bytes = $storage->leer($req->files['image']['tmp_name']);

        if ($bytes === false) {
            return Response::error(400, 'No pudimos leer el archivo');
        }

        $guardada = self::guardarBytes($bytes, $storage);

        if (!$guardada['ok']) {
            return Response::error(400, $guardada['error']);
        }

        // Recién acá se quema el token: si algo falló antes, la persona puede
        // volver a intentar con el mismo link.
        if (!SubidasConToken::marcarUsado($db, (int) $permiso['id'])) {
            return Response::error(409, 'Este link ya se usó');
        }

        $stmt = $db->prepare('UPDATE links SET image_url = ? WHERE id = ?');
        $stmt->execute([$guardada['url'], (int) $permiso['link_id']]);

        return Response::ok(['url' => $guardada['url']]);
    }

    /**
     * Guarda una imagen que llegó como bytes en base64.
     *
     * Es el camino del server MCP: un asistente no puede mandar un formulario
     * con un archivo, manda el contenido codificado dentro del JSON.
     *
     * La comprobación importante es la misma que en la subida por formulario, y
     * por eso vive acá y no allá: la extensión sale del tipo que detecta el
     * servidor mirando los bytes, nunca de lo que diga quien sube. En
     * api/uploads/ Apache ejecuta PHP, así que guardar con una extensión
     * elegida por el cliente sería ejecución remota de código.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function guardarBase64($contenido, FileStorage $storage = null)
    {
        $storage = $storage === null ? new FileStorage() : $storage;

        $bytes = self::decodificar($contenido);

        if ($bytes === null) {
            return ['ok' => false, 'error' => 'La imagen no está en base64 válido'];
        }

        return self::guardarBytes($bytes, $storage);
    }

    /** Valida los bytes y los deja guardados. Único punto que escribe. */
    private static function guardarBytes($bytes, FileStorage $storage = null)
    {
        $storage = $storage === null ? new FileStorage() : $storage;

        if (strlen($bytes) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'La imagen pesa más de 5 MB'];
        }

        $temporal = $storage->guardarTemporal($bytes);

        if ($temporal === false) {
            return ['ok' => false, 'error' => 'No pudimos guardar la imagen'];
        }

        $extension = self::extensionSegura($storage->imageInfo($temporal));

        if ($extension === null) {
            $storage->borrar($temporal);

            return ['ok' => false, 'error' => 'El archivo no es una imagen JPG, PNG, GIF o WebP'];
        }

        // Mirar el encabezado no alcanza. Una imagen cortada por la mitad
        // conserva la firma y hasta el cierre del formato, así que
        // getimagesize() la da por buena y devuelve sus dimensiones: el
        // archivo se guardaba, el evento quedaba apuntando a él, y recién el
        // navegador o el CDN descubrían que no se puede dibujar. Pasó con una
        // imagen que llegó truncada por MCP. Decodificarla entera es la única
        // comprobación que distingue una imagen de algo que empieza como una.
        if (!self::seDecodificaEntera($bytes)) {
            $storage->borrar($temporal);

            return ['ok' => false, 'error' => 'La imagen llegó incompleta o dañada. Volvé a mandarla; '
                . 'si la estás pasando en base64, usá subir_imagen y subí el archivo con un POST.'];
        }

        $directorio = dirname(__DIR__) . '/uploads/';
        $storage->ensureDir($directorio);

        $nombre = uniqid() . '_' . time() . '.' . $extension;

        if (!$storage->mover($temporal, $directorio . $nombre)) {
            $storage->borrar($temporal);

            return ['ok' => false, 'error' => 'No pudimos guardar la imagen'];
        }

        return ['ok' => true, 'url' => UPLOAD_URL . '/uploads/' . $nombre];
    }

    /**
     * Si los bytes son una imagen completa y no sólo el principio de una.
     *
     * Sin GD no se puede comprobar; en ese caso se deja pasar en lugar de
     * rechazar todo, porque el encabezado ya se validó y bloquear cada subida
     * sería peor que el problema que esto evita.
     */
    public static function seDecodificaEntera($bytes)
    {
        if (!function_exists('imagecreatefromstring')) {
            return true;
        }

        $imagen = @imagecreatefromstring($bytes);

        if ($imagen === false) {
            return false;
        }

        imagedestroy($imagen);

        return true;
    }

    /**
     * Guarda una imagen que está publicada en otra dirección.
     *
     * Rezonar se queda con una copia en lugar de enlazar al sitio ajeno: un
     * enlace prestado deja el evento sin afiche el día que ese sitio cambia la
     * URL o se cae.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function guardarDesdeUrl($url, FileStorage $storage = null, HttpClient $http = null)
    {
        if (!self::direccionSegura($url)) {
            return ['ok' => false, 'error' => 'La dirección de la imagen no es una URL pública válida'];
        }

        $http = $http === null ? new HttpClient() : $http;
        $r = $http->get(trim($url), ['Accept: image/*']);

        if ($r['status'] !== 200 || $r['body'] === '') {
            return ['ok' => false, 'error' => 'No pudimos descargar la imagen de esa dirección'];
        }

        // Se reutiliza el mismo camino que el resto: lo que decide si esto es
        // una imagen son los bytes, no de dónde vinieron.
        return self::guardarBytes($r['body'], $storage);
    }

    /**
     * Una dirección a la que el servidor puede ir a buscar algo.
     *
     * Descargar lo que diga un tercero es pedirle al servidor que haga
     * pedidos por cuenta ajena: sin este filtro, alguien podría hacerle leer
     * la red interna del hosting o los metadatos de la nube apuntando a una
     * IP privada. Sólo http y https, y nunca hacia adentro.
     */
    public static function direccionSegura($url)
    {
        $partes = parse_url(trim((string) $url));

        if (!$partes || !isset($partes['scheme'], $partes['host'])) {
            return false;
        }

        if (!in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $ip = filter_var($partes['host'], FILTER_VALIDATE_IP) ? $partes['host'] : gethostbyname($partes['host']);

        // Sin resolver no se arriesga: un nombre que hoy no resuelve puede
        // resolver a algo interno en el próximo intento.
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Los bytes de una imagen en base64.
     *
     * Se acepta tanto el base64 pelado como un data URI completo, que es lo que
     * suelen tener a mano las herramientas que manipulan imágenes.
     *
     * @return string|null
     */
    public static function decodificar($contenido)
    {
        if (!is_string($contenido) || trim($contenido) === '') {
            return null;
        }

        $limpio = preg_replace('#^data:image/[a-z.+-]+;base64,#i', '', trim($contenido));
        $limpio = preg_replace('/\s+/', '', $limpio);

        $bytes = base64_decode($limpio, true);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    /**
     * Extensión correspondiente al tipo detectado por getimagesize(), o null si
     * el archivo es una imagen de un formato que no aceptamos.
     */
    public static function extensionSegura($imageInfo)
    {
        $tipo = isset($imageInfo[2]) ? $imageInfo[2] : null;

        return isset(self::$extensionPorTipoDetectado[$tipo])
            ? self::$extensionPorTipoDetectado[$tipo]
            : null;
    }
}
