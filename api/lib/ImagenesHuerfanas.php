<?php

/**
 * Borra del disco las imágenes que ya no usa nadie.
 *
 * Las subidas se acumulan: cambiar el afiche de un evento deja la anterior
 * ocupando lugar para siempre, y lo mismo pasa con cada foto de perfil que se
 * reemplaza o con un evento que se borra.
 *
 * Dos cuidados ordenan todo lo demás, porque el error acá no se deshace:
 *
 * Se compara por nombre de archivo y no por URL entera. La dirección con la
 * que se guardó una imagen depende de UPLOAD_URL, que cambió y puede volver a
 * cambiar; comparar URLs completas daría "no la usa nadie" para media
 * biblioteca.
 *
 * Y no se toca nada recién subido. Entre que una imagen se sube y que se
 * guarda la fila que la referencia pasan segundos —o los minutos que alguien
 * tarde en apretar Guardar—, y en esa ventana el archivo existe sin que nada
 * lo mencione todavía.
 */
class ImagenesHuerfanas
{
    /**
     * Horas que una imagen está protegida por ser nueva.
     *
     * Generoso a propósito: el costo de esperar de más es disco, y el de
     * esperar de menos es borrarle a alguien el afiche mientras lo carga.
     */
    const HORAS_DE_GRACIA = 48;

    /**
     * Dónde puede estar en uso una imagen.
     *
     * Si alguna vez se agrega otra columna que guarde una imagen subida, tiene
     * que sumarse acá. No hacerlo no rompe nada visible: simplemente esta
     * tarea empieza a borrar imágenes que sí se usaban.
     */
    const REFERENCIAS = [
        ['links', 'image_url'],
        ['pages', 'profile_image'],
        ['pages', 'background_image'],
        ['users', 'avatar_url'],
    ];

    /**
     * Los nombres de archivo que alguien está usando.
     *
     * @return array nombre => true
     */
    public static function enUso($db)
    {
        $usadas = [];

        foreach (self::REFERENCIAS as $referencia) {
            list($tabla, $columna) = $referencia;

            $stmt = $db->query("SELECT $columna FROM $tabla WHERE $columna IS NOT NULL AND $columna <> ''");

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $valor) {
                $nombre = self::nombreDeArchivo($valor);

                if ($nombre !== null) {
                    $usadas[$nombre] = true;
                }
            }
        }

        return $usadas;
    }

    /**
     * El nombre de archivo de una referencia, sea una URL o una ruta.
     *
     * Devuelve null para lo que no tenga forma de archivo, que es lo que hay
     * que ignorar en vez de tratar como nombre.
     */
    public static function nombreDeArchivo($valor)
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        // Se descarta el query string: la misma imagen puede estar referida
        // con y sin parámetros de caché.
        $camino = parse_url($valor, PHP_URL_PATH);
        $nombre = basename($camino === null || $camino === false ? $valor : $camino);

        return $nombre === '' || $nombre === '.' || $nombre === '..' ? null : $nombre;
    }

    /**
     * Las imágenes que se pueden borrar.
     *
     * @param callable $listar   Devuelve [nombre => timestamp de modificación]
     * @param int      $ahora    Para poder fijar el tiempo en los tests
     * @return array nombres de archivo
     */
    public static function huerfanas($db, callable $listar, $ahora = null)
    {
        $ahora = $ahora === null ? time() : $ahora;
        $limite = $ahora - self::HORAS_DE_GRACIA * 3600;
        $enUso = self::enUso($db);

        $huerfanas = [];

        foreach (call_user_func($listar) as $nombre => $modificado) {
            // Los archivos ocultos son configuración del directorio, no
            // subidas: borrar el .htaccess dejaría a Apache ejecutando PHP acá.
            if (strpos($nombre, '.') === 0) {
                continue;
            }

            if (isset($enUso[$nombre]) || $modificado > $limite) {
                continue;
            }

            $huerfanas[] = $nombre;
        }

        return $huerfanas;
    }
}
