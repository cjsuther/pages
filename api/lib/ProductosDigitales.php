<?php

/**
 * Productos digitales: lo que se vende para descargar desde una página.
 *
 * Cada producto es una fila de links dentro de un grupo "productos", con su
 * fila en digital_products para el precio y el archivo.
 *
 * El archivo es lo delicado. Todo lo que está en api/uploads/ se sirve tal
 * cual, y un producto pago no puede estar a una URL de distancia. Por eso va
 * en uploads/privado/productos/:
 *
 * - Dentro de uploads/ porque deploy.sh lo excluye del rsync con --delete.
 *   Cualquier otra carpeta de api/ se borraría en el siguiente deploy.
 * - Con su propio .htaccess que niega todo. Como uploads/ no se despliega,
 *   ese .htaccess no puede venir del repo: lo escribe este código antes de
 *   guardar el primer archivo, y si no lo puede escribir no guarda nada.
 * - Con un nombre aleatorio y sin extensión. El .htaccess de uploads/ habilita
 *   los .jpg, .png, etc. con un FilesMatch, y un FilesMatch pesa más que el
 *   "deny" de la carpeta: un producto llamado foto.jpg quedaría público. Sin
 *   extensión, ese FilesMatch nunca aplica. Y el nombre que manda el usuario
 *   jamás toca la ruta.
 */
class ProductosDigitales
{
    const MAX_BYTES = 52428800; // 50 MB

    /** El techo de DECIMAL(12,2). */
    const PRECIO_MAXIMO = 9999999999.99;

    /** Lo que el editor ve de un producto; archivo_ruta queda afuera. */
    const COLUMNAS_PUBLICAS = 'COALESCE(dp.precio, 0) AS precio, dp.archivo_nombre, dp.archivo_bytes, dp.archivo_tipo';

    const MENSAJE_EXCESO ='El archivo pesa más de 50 MB, que es el máximo. Si son varios archivos, comprimilos en un ZIP.';

    /**
     * Lo que va en el .htaccess de la carpeta privada.
     *
     * El FilesMatch no sobra: los de un .htaccess de más adentro se aplican
     * después que los de uploads/, así que este gana aunque algún día un
     * archivo llegue a tener una extensión de imagen.
     */
    const HTACCESS = "# Archivos privados: nunca se sirven directo. Se descargan por la API.\n"
        . "Require all denied\n"
        . "<FilesMatch \".\">\n"
        . "  Require all denied\n"
        . "</FilesMatch>\n";

    /** Raíz del almacenamiento privado, con la barra final. */
    public static function raiz()
    {
        return dirname(__DIR__) . '/uploads/privado/';
    }

    /** Ruta en disco de una ruta relativa guardada en la base. */
    public static function rutaEnDisco($relativa)
    {
        return self::raiz() . $relativa;
    }

    /**
     * Si una ruta guardada tiene la forma que genera este código.
     *
     * Se mira antes de borrar: sin esto, una fila tocada a mano —o una
     * migración que se equivoque— podría hacer borrar cualquier archivo.
     */
    public static function rutaValida($relativa)
    {
        return is_string($relativa) && preg_match('#^productos/[0-9a-f]{32}$#', $relativa) === 1;
    }

    /** Una ruta relativa nueva, con nombre aleatorio. */
    public static function rutaNueva()
    {
        return 'productos/' . bin2hex(random_bytes(16));
    }

    /**
     * Deja la carpeta lista para guardar: creada y con su .htaccess.
     *
     * El .htaccess va en privado/ y en privado/productos/. El de privado/
     * cubre cualquier carpeta que se sume más adelante.
     *
     * @return bool false si quedó alguna carpeta sin proteger
     */
    public static function prepararCarpeta(FileStorage $storage)
    {
        $carpetas = [self::raiz(), self::raiz() . 'productos/'];

        foreach ($carpetas as $carpeta) {
            $storage->ensureDir($carpeta);
            $htaccess = $carpeta . '.htaccess';

            if (!$storage->existe($htaccess) && !$storage->escribir($htaccess, self::HTACCESS)) {
                return false;
            }
        }

        return true;
    }

    /**
     * El precio que se guarda, o null si no se puede aceptar.
     *
     * Vacío es gratis: es lo que queda en el campo si nadie lo toca.
     *
     * @return float|null
     */
    public static function precio($valor)
    {
        if ($valor === null || $valor === '') {
            return 0.0;
        }

        if (!is_numeric($valor)) {
            return null;
        }

        $precio = round((float) $valor, 2);

        if ($precio < 0 || $precio > self::PRECIO_MAXIMO) {
            return null;
        }

        return $precio;
    }

    /**
     * El nombre original, apto para usarlo después en la descarga.
     *
     * Se queda sólo con el nombre —sin carpetas— y sin lo que rompería una
     * cabecera Content-Disposition: comillas, barras y caracteres de control.
     */
    public static function nombreOriginal($nombre)
    {
        $nombre = str_replace('\\', '/', (string) $nombre);
        $nombre = basename($nombre);
        $nombre = preg_replace('/[\x00-\x1F\x7F"\/]/u', '', $nombre);
        $nombre = trim((string) $nombre);

        if (function_exists('mb_substr')) {
            $nombre = mb_substr($nombre, 0, 255);
        } else {
            $nombre = substr($nombre, 0, 255);
        }

        return $nombre === '' || $nombre === '.' || $nombre === '..' ? 'archivo' : $nombre;
    }

    // --------------------------------------------------------------- base

    /** El producto de un link, o null. */
    public static function delLink($db, $linkId)
    {
        $stmt = $db->prepare('SELECT * FROM digital_products WHERE link_id = ?');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /** Crea o actualiza el precio de un producto. */
    public static function guardarPrecio($db, $linkId, $precio)
    {
        $stmt = $db->prepare('
            INSERT INTO digital_products (link_id, precio) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE precio = VALUES(precio)
        ');
        $stmt->execute([(int) $linkId, $precio]);
    }

    /** Deja asociado el archivo nuevo. */
    public static function guardarArchivo($db, $linkId, $ruta, $nombre, $bytes, $tipo)
    {
        $stmt = $db->prepare('
            INSERT INTO digital_products (link_id, archivo_ruta, archivo_nombre, archivo_bytes, archivo_tipo)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                archivo_ruta = VALUES(archivo_ruta),
                archivo_nombre = VALUES(archivo_nombre),
                archivo_bytes = VALUES(archivo_bytes),
                archivo_tipo = VALUES(archivo_tipo)
        ');
        $stmt->execute([(int) $linkId, $ruta, $nombre, (int) $bytes, $tipo]);
    }

    /**
     * El link con los datos de su producto, como lo usa el editor.
     *
     * La ruta en disco no sale nunca: a nadie de afuera le sirve, y es mejor
     * no regalar ni la forma de los nombres.
     */
    public static function linkConProducto($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT l.*, ' . self::COLUMNAS_PUBLICAS . '
            FROM links l
            LEFT JOIN digital_products dp ON dp.link_id = l.id
            WHERE l.id = ?
        ');
        $stmt->execute([(int) $linkId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    /** Los productos de un grupo, para el editor. */
    public static function delGrupo($db, $groupId)
    {
        $stmt = $db->prepare('
            SELECT l.*, ' . self::COLUMNAS_PUBLICAS . '
            FROM links l
            LEFT JOIN digital_products dp ON dp.link_id = l.id
            WHERE l.group_id = ?
            ORDER BY l.position, l.id
        ');
        $stmt->execute([(int) $groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Por qué un producto no se puede borrar, o null si se puede.
     *
     * Hoy siempre se puede. Cuando haya ventas (parte 2), uno con ventas
     * pagadas no: quien lo compró tiene que poder seguir descargándolo.
     *
     * @return string|null
     */
    public static function motivoParaNoBorrar($db, $linkId)
    {
        return null;
    }

    // ------------------------------------------------------------ archivos

    /** Las rutas de los archivos de un grupo, para borrarlas con él. */
    public static function rutasDelGrupo($db, $groupId)
    {
        $stmt = $db->prepare('
            SELECT dp.archivo_ruta
            FROM digital_products dp
            INNER JOIN links l ON l.id = dp.link_id
            WHERE l.group_id = ? AND dp.archivo_ruta IS NOT NULL
        ');
        $stmt->execute([(int) $groupId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Las rutas de los archivos de una página, para borrarlas con ella. */
    public static function rutasDeLaPagina($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT dp.archivo_ruta
            FROM digital_products dp
            INNER JOIN links l ON l.id = dp.link_id
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE lg.page_id = ? AND dp.archivo_ruta IS NOT NULL
        ');
        $stmt->execute([(int) $pageId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Borra del disco los archivos de estas rutas.
     *
     * Va siempre después de borrar las filas: si la base falla, el producto
     * sigue entero; al revés, quedaría un producto apuntando a la nada.
     */
    public static function borrarArchivos(array $rutas, FileStorage $storage)
    {
        foreach ($rutas as $ruta) {
            if (self::rutaValida($ruta)) {
                $storage->borrar(self::rutaEnDisco($ruta));
            }
        }
    }
}
