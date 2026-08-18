<?php

/**
 * Operaciones de sistema de archivos para las subidas.
 *
 * Se recibe por parámetro en el handler para que los tests puedan sustituirla
 * y no escribir en disco.
 */
class FileStorage
{
    /**
     * Datos de la imagen (como getimagesize) o false si no es una imagen válida.
     *
     * @return array|false
     */
    public function imageInfo($path)
    {
        return @getimagesize($path);
    }

    public function ensureDir($dir)
    {
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function moveUploaded($tmpPath, $destination)
    {
        return move_uploaded_file($tmpPath, $destination);
    }

    /**
     * Escribe bytes en un archivo temporal y devuelve su ruta.
     *
     * Hace falta porque la comprobación de que algo es realmente una imagen se
     * hace sobre un archivo, y lo que llega por MCP son bytes en memoria.
     *
     * @return string|false
     */
    public function guardarTemporal($contenido)
    {
        $ruta = tempnam(sys_get_temp_dir(), 'rezonar');

        if ($ruta === false) {
            return false;
        }

        return file_put_contents($ruta, $contenido) === false ? false : $ruta;
    }

    /**
     * Mueve el archivo y lo deja legible para el servidor web.
     *
     * El chmod no es adorno: tempnam() crea con permisos 0600 y rename() los
     * conserva, así que sin esto la imagen queda guardada pero Apache no la
     * puede leer y el evento se publica con un afiche que devuelve 403.
     *
     * @return bool
     */
    public function mover($origen, $destino)
    {
        if (!rename($origen, $destino)) {
            return false;
        }

        @chmod($destino, 0644);

        return true;
    }

    /**
     * Contenido de un archivo, o false.
     *
     * @return string|false
     */
    public function leer($ruta)
    {
        return @file_get_contents($ruta);
    }

    public function borrar($ruta)
    {
        if (is_file($ruta)) {
            unlink($ruta);
        }
    }
}
