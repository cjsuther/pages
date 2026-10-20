<?php

/**
 * Subida del archivo de un producto digital.
 *
 * Va aparte de UploadHandler porque es otra cosa: aquello acepta sólo
 * imágenes y las deja públicas; esto acepta cualquier archivo y lo deja donde
 * no se puede pedir por URL (ver ProductosDigitales). El archivo nunca se
 * ejecuta ni se muestra como página: se va a descargar siempre como adjunto.
 *
 * El producto se crea primero, por la API de links, y el archivo se le sube
 * después: POST productos/archivo.php?link_id=N con el campo "archivo".
 */
class ProductosHandler
{
    public static function archivo($db, Request $req, ?FileStorage $storage = null)
    {
        $storage = $storage === null ? new FileStorage() : $storage;

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        $linkId = (int) $req->param('link_id');

        if (!$linkId) {
            return Response::error(400, 'Falta el producto (link_id)');
        }

        if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
            return Response::notFound('Producto no encontrado');
        }

        if (!self::esProducto($db, $linkId)) {
            return Response::error(400, 'Ese item no es un producto digital');
        }

        $archivo = isset($req->files['archivo']) ? $req->files['archivo'] : null;
        $error = self::errorDeSubida($archivo, $req);

        if ($error !== null) {
            return $error;
        }

        if (!ProductosDigitales::prepararCarpeta($storage)) {
            // Sin el .htaccess el archivo quedaría a una URL de distancia:
            // mejor no guardarlo.
            return Response::serverError('No pudimos preparar el almacenamiento privado');
        }

        $ruta = ProductosDigitales::rutaNueva();
        $destino = ProductosDigitales::rutaEnDisco($ruta);

        if (!$storage->moveUploaded($archivo['tmp_name'], $destino)) {
            return Response::serverError('No pudimos guardar el archivo');
        }

        $anterior = ProductosDigitales::delLink($db, $linkId);
        $tipo = $storage->tipoMime($destino);

        try {
            ProductosDigitales::guardarArchivo(
                $db,
                $linkId,
                $ruta,
                ProductosDigitales::nombreOriginal(isset($archivo['name']) ? $archivo['name'] : ''),
                (int) $archivo['size'],
                $tipo === null ? 'application/octet-stream' : $tipo
            );
            ProductosDigitales::actualizarFoto($db, $linkId);
        } catch (Exception $e) {
            // Sin fila que lo nombre, el archivo no lo va a encontrar nadie.
            $storage->borrar($destino);

            return Response::serverError($e->getMessage());
        }

        // Recién con el nuevo guardado se borra el viejo: si algo fallaba
        // antes, el producto seguía con su archivo de siempre.
        if ($anterior !== null && !empty($anterior['archivo_ruta'])) {
            ProductosDigitales::borrarArchivos([$anterior['archivo_ruta']], $storage);
        }

        return Response::ok(['link' => ProductosDigitales::linkConProducto($db, $linkId)]);
    }

    private static function esProducto($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT lg.type
            FROM links l
            JOIN link_groups lg ON l.group_id = lg.id
            WHERE l.id = ?
        ');
        $stmt->execute([$linkId]);

        return $stmt->fetchColumn() === 'productos';
    }

    /**
     * Qué responder si el archivo no sirve, o null si está bien.
     *
     * Lo más común con 50 MB es que PHP lo corte antes de llegar acá, y de dos
     * formas distintas: con upload_max_filesize marca el archivo con un error;
     * con post_max_size descarta el POST entero y $_FILES llega vacío, aunque
     * el navegador sí haya mandado algo. En los dos casos el mensaje tiene que
     * decir el máximo: "no llegó ningún archivo" haría pensar que no se eligió.
     */
    private static function errorDeSubida($archivo, Request $req)
    {
        if ($archivo === null) {
            if ((int) $req->header('Content-Length', 0) > 0) {
                return Response::error(413, ProductosDigitales::MENSAJE_EXCESO);
            }

            return Response::error(400, 'No llegó ningún archivo');
        }

        $codigo = isset($archivo['error']) ? (int) $archivo['error'] : UPLOAD_ERR_OK;

        if ($codigo === UPLOAD_ERR_INI_SIZE || $codigo === UPLOAD_ERR_FORM_SIZE) {
            return Response::error(413, ProductosDigitales::MENSAJE_EXCESO);
        }

        if ($codigo === UPLOAD_ERR_NO_FILE) {
            return Response::error(400, 'No llegó ningún archivo');
        }

        if ($codigo !== UPLOAD_ERR_OK || empty($archivo['tmp_name'])) {
            return Response::error(400, 'El archivo no llegó entero. Probá subirlo de nuevo.');
        }

        $bytes = isset($archivo['size']) ? (int) $archivo['size'] : 0;

        if ($bytes > ProductosDigitales::MAX_BYTES) {
            return Response::error(413, ProductosDigitales::MENSAJE_EXCESO);
        }

        if ($bytes <= 0) {
            return Response::error(400, 'El archivo está vacío');
        }

        return null;
    }
}
