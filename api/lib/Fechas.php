<?php

/**
 * Qué día es "hoy" para decidir si un evento ya pasó.
 *
 * El servidor corre en UTC, tres horas adelante de Argentina. Usar CURDATE()
 * hace que entre las 21:00 y la medianoche la base ya crea que es el día
 * siguiente, y los eventos de esa misma noche —justo los que la gente está por
 * ir a ver— desaparecen de la página.
 *
 * Argentina no tiene horario de verano, así que el desfase es siempre el mismo.
 */
class Fechas
{
    const ZONA = 'America/Argentina/Buenos_Aires';

    /**
     * Fecha de hoy en Argentina, en formato Y-m-d.
     *
     * @param string|null $ahora Momento a evaluar, para poder testearlo
     */
    public static function hoy($ahora = null)
    {
        $momento = new DateTime($ahora === null ? 'now' : $ahora, new DateTimeZone('UTC'));
        $momento->setTimezone(new DateTimeZone(self::ZONA));

        return $momento->format('Y-m-d');
    }
}
