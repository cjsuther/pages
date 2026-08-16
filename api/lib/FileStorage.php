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
}
