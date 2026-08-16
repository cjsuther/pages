<?php

/**
 * Cálculos geográficos. Extraído de api/pages/feed-events.php, donde estaba
 * duplicado con api/notifications/process-daily.php.
 */
class Geo
{
    /** Radio medio de la Tierra en kilómetros. */
    const EARTH_RADIUS_KM = 6371;

    /**
     * Distancia en kilómetros entre dos puntos, por la fórmula de Haversine.
     */
    public static function distanceKm($lat1, $lon1, $lat2, $lon2)
    {
        $lat1 = (float) $lat1;
        $lon1 = (float) $lon1;
        $lat2 = (float) $lat2;
        $lon2 = (float) $lon2;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}
