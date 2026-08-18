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
