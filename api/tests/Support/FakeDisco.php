<?php

namespace Tests\Support;

use FileStorage;

/**
 * FileStorage en memoria, para las subidas que no son imágenes.
 *
 * Recuerda qué se escribió, qué se movió y qué se borró, sin tocar el disco.
 * Mover un archivo subido lo deja "existiendo" en el destino, así que un test
 * puede preguntar después por esa ruta.
 */
class FakeDisco extends FileStorage
{
    /** @var array ruta => contenido */
    public $archivos = [];

    /** @var string[] */
    public $carpetas = [];

    /** @var string[] */
    public $borrados = [];

    /** @var array [origen, destino] */
    public $movidos = [];

    /** Si es false, mover y escribir fallan. */
    public $funciona = true;

    /** Si es false, sólo falla escribir: la carpeta queda sin .htaccess. */
    public $escribeArchivos = true;

    /** Lo que devuelve tipoMime(). */
    public $mime = 'application/pdf';

    public function ensureDir($dir)
    {
        $this->carpetas[] = $dir;
    }

    public function moveUploaded($tmpPath, $destination)
    {
        if (!$this->funciona) {
            return false;
        }

        $this->movidos[] = [$tmpPath, $destination];
        $this->archivos[$destination] = 'subido desde ' . $tmpPath;

        return true;
    }

    public function existe($ruta)
    {
        return isset($this->archivos[$ruta]);
    }

    public function escribir($ruta, $contenido)
    {
        if (!$this->funciona || !$this->escribeArchivos) {
            return false;
        }

        $this->archivos[$ruta] = $contenido;

        return true;
    }

    public function borrar($ruta)
    {
        $this->borrados[] = $ruta;
        unset($this->archivos[$ruta]);
    }

    public function bytes($ruta)
    {
        return isset($this->archivos[$ruta]) ? strlen($this->archivos[$ruta]) : null;
    }

    public function tipoMime($ruta)
    {
        return $this->mime;
    }
}
