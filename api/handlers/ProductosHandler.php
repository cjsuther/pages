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

        $anterior = ProductosDigitales::delLink($db, $linkId);

        // Un físico se manda, no se descarga (#42).
        if (ProductosFisicos::esFisico($anterior)) {
            return Response::error(400, 'Un producto físico no lleva archivo');
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

    // ------------------------------------------------------------------ ventas

    /**
     * Las ventas de un producto (?link_id=N) o de todos los productos de una
     * página (?page_id=N), los borrados incluidos. Con ?formato=excel baja el
     * Excel.
     *
     * Por página el permiso se mira sobre la página y no sobre el link: el
     * link de un producto borrado ya no existe.
     *
     * Son datos de contacto de terceros: sólo los ve quien administra la
     * página. El token y el link de descarga no salen nunca.
     */
    public static function ventas($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $linkId = (int) $req->param('link_id');
        $pageId = (int) $req->param('page_id');

        if ($linkId) {
            if (!PageAccess::canManageLink($db, $linkId, $req->userId())) {
                return Response::error(403, 'No podés ver las ventas de este producto');
            }

            if (!self::esProducto($db, $linkId)) {
                return Response::error(400, 'Ese item no es un producto digital');
            }

            $alcance = ['link', $linkId];
        } elseif ($pageId) {
            if (!PageAccess::canManage($db, $pageId, $req->userId())) {
                return Response::error(403, 'No podés ver las ventas de esta página');
            }

            $alcance = ['pagina', $pageId];
        } else {
            return Response::error(400, 'link_id o page_id requerido');
        }

        $tipo = $req->param('tipo');

        if ($req->param('formato') === 'excel' && $tipo === 'fisico') {
            return self::excelDePedidos($db, $alcance, $req);
        }

        $ventas = OrdenesDeProductos::ventas($db, $alcance[0], $alcance[1]);

        if ($req->param('formato') === 'excel') {
            $ordenes = $tipo === 'digital'
                ? array_values(array_filter($ventas['ordenes'], function ($o) { return $o['tipo'] === 'digital'; }))
                : $ventas['ordenes'];

            return Response::raw(200, OrdenesDeProductos::excel($ordenes),
                Excel::cabeceras(self::nombreDelExcel($req->param('nombre', ''))));
        }

        return Response::ok($ventas);
    }

    /**
     * Los pedidos de físicos con su dirección, para armar el recorrido de
     * entrega (#44). Con ?estado_pedido=X, sólo los de ese estado, y con
     * ?forma_entrega=Y, sólo los de esa forma (#53): los filtros que se están
     * mirando en el panel.
     */
    private static function excelDePedidos($db, array $alcance, Request $req)
    {
        $estado = $req->param('estado_pedido');
        $forma = $req->param('forma_entrega');

        if ($estado !== null && $estado !== '' && !isset(EstadosDePedidos::ESTADOS[$estado])) {
            return Response::error(400, 'Estado de pedido desconocido');
        }

        if ($forma !== null && $forma !== '' && !isset(PedidosFisicos::FORMAS[$forma])) {
            return Response::error(400, 'Forma de entrega desconocida');
        }

        $filas = OrdenesDeProductos::filasDeVentas($db, $alcance[0], $alcance[1]);

        return Response::raw(
            200,
            OrdenesDeProductos::excelDePedidos(
                $filas,
                $estado === null || $estado === '' ? null : $estado,
                $forma === null || $forma === '' ? null : $forma
            ),
            Excel::cabeceras(self::nombreDelExcel($req->param('nombre', ''), 'pedidos'))
        );
    }

    /**
     * Le vuelve a mandar el mail con el link a quien compró.
     *
     * Usa el mismo link de siempre (token_cifrado): no genera otro. Y respeta
     * el mínimo entre reenvíos de CorreoProductos, para que el botón no sirva
     * para llenarle la casilla a nadie.
     */
    public static function reenviar($db, Request $req, $mailer = null)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $codigo = trim((string) $req->input('codigo'));

        if ($codigo === '') {
            return Response::error(400, 'codigo requerido');
        }

        $orden = OrdenesDeProductos::orden($db, $codigo);

        if ($orden === null) {
            return Response::notFound('No encontramos esa compra');
        }

        // Por la página y no por el link: el producto puede estar borrado.
        if (empty($orden['pagina_id']) || !PageAccess::canManage($db, (int) $orden['pagina_id'], $req->userId())) {
            return Response::error(403, 'No podés reenviar compras de esta página');
        }

        if ($orden['estado'] !== 'pagada') {
            return Response::error(409, 'Sólo se puede reenviar el link de una compra pagada');
        }

        $resultado = CorreoProductos::reenviarPorElOrganizador($db, $orden['codigo'], $mailer);

        if ($resultado['enviado']) {
            return Response::ok(['enviado' => true, 'mensaje' => 'Le reenviamos el link a ' . $orden['email']]);
        }

        if ($resultado['motivo'] === 'se envió hace instantes') {
            return Response::error(429, 'El link se mandó hace instantes. Probá de nuevo en unos minutos.');
        }

        return Response::error(502, 'No pudimos mandar el mail: ' . $resultado['motivo']);
    }

    /** "ventas-guia-de-viaje.xlsx", como el de las entradas; "pedidos-…" el de los físicos. */
    private static function nombreDelExcel($nombre, $prefijo = 'ventas')
    {
        $limpio = strtolower(trim((string) $nombre));
        $limpio = strtr($limpio, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        $limpio = trim(preg_replace('/[^a-z0-9]+/', '-', $limpio), '-');

        return $prefijo . ($limpio === '' ? '-productos' : '-' . substr($limpio, 0, 60)) . '.xlsx';
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
