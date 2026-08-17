<?php

/**
 * Convierte una dirección en coordenadas, con caché.
 *
 * Los eventos importados traen la dirección en texto y Rezonar necesita
 * coordenadas para el mapa. Se usa Nominatim de OpenStreetMap, que es gratuito
 * y no pide credenciales, a cambio de un límite de un pedido por segundo.
 *
 * De ahí la caché: la misma sala aparece en decenas de shows, así que
 * resolverla una vez alcanza para siempre. Los fallos también se guardan, para
 * no reintentar en cada corrida una dirección que el servicio no sabe resolver.
 */
class Geocodificador
{
    const URL = 'https://nominatim.openstreetmap.org/search';

    /** Nominatim exige identificarse con algo que permita contactarnos. */
    const AGENTE = 'RezonarBot/1.0 (+https://rezon.ar; agenda de eventos)';

    /** Su política es un pedido por segundo. */
    const PAUSA = 1;

    /** Reintentos antes de dar una dirección por irresoluble. */
    const MAX_INTENTOS = 2;

    /** @var callable */
    private $traer;

    /** @var int */
    private $pausa;

    public function __construct($traer = null)
    {
        $this->traer = $traer === null ? [$this, 'traerConCurl'] : $traer;
        $this->pausa = $traer === null ? self::PAUSA : 0;
    }

    /**
     * Coordenadas de una dirección.
     *
     * @return array|null ['latitud' => string, 'longitud' => string] o null
     */
    public function coordenadas($db, $direccion)
    {
        $direccion = self::normalizar($direccion);

        if ($direccion === '') {
            return null;
        }

        $huella = hash('sha256', $direccion);
        $cacheado = self::enCache($db, $huella);

        if ($cacheado !== null) {
            // Una entrada sin coordenadas es un fallo ya conocido: no se
            // vuelve a preguntar hasta agotar los reintentos.
            if ($cacheado['latitud'] === null) {
                return (int) $cacheado['intentos'] >= self::MAX_INTENTOS ? null : $this->resolver($db, $huella, $direccion);
            }

            return ['latitud' => $cacheado['latitud'], 'longitud' => $cacheado['longitud']];
        }

        return $this->resolver($db, $huella, $direccion);
    }

    /** Le pregunta al servicio y guarda el resultado, sea bueno o malo. */
    private function resolver($db, $huella, $direccion)
    {
        if ($this->pausa > 0) {
            sleep($this->pausa);
        }

        $url = self::URL . '?' . http_build_query([
            'q' => $direccion,
            'format' => 'json',
            'limit' => 1,
            // Sesga a Argentina: "Rosario 272" existe en muchos países.
            'countrycodes' => 'ar',
        ]);

        $cuerpo = call_user_func($this->traer, $url);
        $coordenadas = self::leerRespuesta($cuerpo);

        self::guardar($db, $huella, $direccion, $coordenadas);

        return $coordenadas;
    }

    /** @return array|null */
    public static function leerRespuesta($cuerpo)
    {
        if (!is_string($cuerpo) || $cuerpo === '') {
            return null;
        }

        $datos = json_decode($cuerpo, true);

        if (!is_array($datos) || empty($datos[0]['lat']) || empty($datos[0]['lon'])) {
            return null;
        }

        if (!is_numeric($datos[0]['lat']) || !is_numeric($datos[0]['lon'])) {
            return null;
        }

        return [
            'latitud'  => (string) round((float) $datos[0]['lat'], 8),
            'longitud' => (string) round((float) $datos[0]['lon'], 8),
        ];
    }

    /**
     * Deja la dirección en una forma estable para que la caché acierte.
     *
     * Sin esto, "Bolívar 624 , CABA" y "Bolívar 624, CABA" serían dos entradas
     * distintas y se geocodificaría dos veces lo mismo.
     */
    public static function normalizar($direccion)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $direccion));
        $texto = preg_replace('/\s*,\s*/', ', ', $texto);

        return mb_substr($texto, 0, 500);
    }

    private static function enCache($db, $huella)
    {
        $stmt = $db->prepare('SELECT latitud, longitud, intentos FROM geocode_cache WHERE huella = ?');
        $stmt->execute([$huella]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    private static function guardar($db, $huella, $direccion, $coordenadas)
    {
        $stmt = $db->prepare('
            INSERT INTO geocode_cache (huella, direccion, latitud, longitud, intentos)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                latitud = VALUES(latitud),
                longitud = VALUES(longitud),
                intentos = intentos + 1
        ');
        $stmt->execute([
            $huella,
            $direccion,
            $coordenadas === null ? null : $coordenadas['latitud'],
            $coordenadas === null ? null : $coordenadas['longitud'],
        ]);
    }

    private function traerConCurl($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, self::AGENTE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept-Language: es']);

        $cuerpo = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $estado === 200 && is_string($cuerpo) ? $cuerpo : '';
    }
}
