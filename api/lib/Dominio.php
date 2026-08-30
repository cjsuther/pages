<?php

/**
 * Dominio propio de una página.
 *
 * Se guarda y se compara siempre normalizado: en minúsculas, sin protocolo,
 * sin puerto, sin ruta y sin el `www.` de adelante. La razón es que del otro
 * lado lo que llega es el `Host` de la visita, y si lo guardado no tiene
 * exactamente esa forma la página no aparece y no hay manera de darse cuenta
 * mirando el administrador, donde se ve escrito igual.
 *
 * `www.maxipeque.com` y `maxipeque.com` son la misma página: se guarda la
 * segunda forma y el `www.` se descarta de los dos lados.
 */
class Dominio
{
    /** El dominio propio de Rezonar no se puede reclamar como dominio de una página. */
    const PROPIOS = ['rezon.ar', 'localhost'];

    /**
     * Deja el dominio en la forma en que se guarda, o null si no se entiende.
     *
     * Acepta lo que la gente pega de la barra del navegador —con https://, con
     * barra final, con www— porque es lo que tiene a mano.
     */
    public static function normalizar($valor)
    {
        if (!is_string($valor)) {
            return null;
        }

        $texto = strtolower(trim($valor));

        if ($texto === '') {
            return null;
        }

        // Protocolo, credenciales y todo lo que venga después de la primera barra.
        $texto = preg_replace('#^[a-z]+://#', '', $texto);
        $texto = preg_replace('#^[^/@]*@#', '', $texto);
        $texto = explode('/', $texto)[0];
        $texto = explode('?', $texto)[0];
        $texto = explode(':', $texto)[0];

        if (strpos($texto, 'www.') === 0) {
            $texto = substr($texto, 4);
        }

        return self::valido($texto) ? $texto : null;
    }

    /**
     * ¿Tiene forma de dominio?
     *
     * No alcanza con que tenga un punto: un espacio o un acento acá significa
     * que la página nunca va a resolver, y es mejor decirlo al guardar que
     * dejar que lo descubra cuando el dominio no funcione.
     */
    private static function valido($texto)
    {
        if ($texto === '' || strlen($texto) > 253) {
            return false;
        }

        // Al menos dos etiquetas, la última de dos letras o más.
        return (bool) preg_match(
            '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
            $texto
        );
    }

    /** true si es un dominio de Rezonar y no puede asignarse a una página. */
    public static function esPropio($dominio)
    {
        if ($dominio === null) {
            return false;
        }

        foreach (self::PROPIOS as $propio) {
            if ($dominio === $propio || substr($dominio, -strlen('.' . $propio)) === '.' . $propio) {
                return true;
            }
        }

        return false;
    }

    /** El dominio de una visita, listo para buscar en la base. */
    public static function deLaVisita($host)
    {
        return self::normalizar($host);
    }
}
