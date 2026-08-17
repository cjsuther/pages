<?php

/**
 * Lee la cartelera pública de Eventbrite.
 *
 * La página de búsqueda deja los resultados en un JSON embebido
 * (window.__SERVER_DATA__), así que no hay que interpretar HTML: se lee la
 * estructura, que es mucho más estable que un selector de CSS.
 *
 * robots.txt permite /d/ (sólo bloquea /directory/ y las vistas de checkout).
 * Aun así se pide de a una página por vez y con pausa entre pedidos: no hay
 * ninguna urgencia en una tarea que corre de madrugada una vez por día.
 */
class Eventbrite
{
    const BASE = 'https://www.eventbrite.com.ar';

    /** Un bot que no se identifica es un bot que se bloquea con razón. */
    const AGENTE = 'Mozilla/5.0 (compatible; RezonarBot/1.0; +https://rezon.ar)';

    /** Segundos entre pedidos. */
    const PAUSA = 2;

    /** Tope por corrida, para no traer la agenda entera de un año. */
    const MAX_PAGINAS = 5;

    /** @var callable Trae una URL; se sustituye en los tests. */
    private $traer;

    /** @var int Segundos entre pedidos. Cero cuando no se sale a la red. */
    private $pausa;

    public function __construct($traer = null)
    {
        $this->traer = $traer === null ? [$this, 'traerConCurl'] : $traer;

        // La pausa es cortesía con el sitio ajeno. Con un lector inyectado no
        // hay sitio ajeno, y esperar sólo haría lenta la suite de tests.
        $this->pausa = $traer === null ? self::PAUSA : 0;
    }

    /**
     * Eventos de una búsqueda.
     *
     * @param array $parametros ['busqueda' => 'stand-up', 'lugar' => 'argentina--buenos-aires', 'paginas' => 3]
     * @return array Eventos con la forma que espera Importador
     */
    public function eventos(array $parametros = [])
    {
        $busqueda = isset($parametros['busqueda']) ? $parametros['busqueda'] : 'stand-up';
        $lugar = isset($parametros['lugar']) ? $parametros['lugar'] : 'argentina--buenos-aires';
        $paginas = min(self::MAX_PAGINAS, max(1, (int) (isset($parametros['paginas']) ? $parametros['paginas'] : 2)));

        $encontrados = [];

        for ($pagina = 1; $pagina <= $paginas; $pagina++) {
            $url = self::BASE . '/d/' . rawurlencode($lugar) . '/' . rawurlencode($busqueda) . '/'
                 . ($pagina > 1 ? '?page=' . $pagina : '');

            $html = call_user_func($this->traer, $url);

            if (!is_string($html) || $html === '') {
                break;
            }

            $crudos = self::eventosDelHtml($html);

            if (empty($crudos)) {
                break;
            }

            foreach ($crudos as $crudo) {
                $normalizado = self::normalizar($crudo);

                if ($normalizado !== null) {
                    $encontrados[$normalizado['id']] = $normalizado;
                }
            }

            if ($pagina < $paginas && $this->pausa > 0) {
                sleep($this->pausa);
            }
        }

        return array_values($encontrados);
    }

    /** Extrae la lista de eventos del JSON que la página deja embebido. */
    public static function eventosDelHtml($html)
    {
        if (!preg_match('/window\.__SERVER_DATA__\s*=\s*(\{.*?\});/s', $html, $m)) {
            return [];
        }

        $datos = json_decode($m[1], true);

        if (!is_array($datos)) {
            return [];
        }

        $resultados = isset($datos['search_data']['events']['results'])
            ? $datos['search_data']['events']['results']
            : [];

        return is_array($resultados) ? $resultados : [];
    }

    /**
     * Pasa un evento de Eventbrite a la forma común.
     *
     * @return array|null null si le falta algo que Rezonar necesita
     */
    public static function normalizar(array $evento)
    {
        $direccion = isset($evento['primary_venue']['address']) ? $evento['primary_venue']['address'] : [];

        $latitud = isset($direccion['latitude']) ? $direccion['latitude'] : null;
        $longitud = isset($direccion['longitude']) ? $direccion['longitude'] : null;

        // Sin coordenadas el evento no se puede ubicar en el mapa, que es
        // media razón de ser de la agenda. Se descarta en vez de entrar a
        // medias.
        if (!is_numeric($latitud) || !is_numeric($longitud)) {
            return null;
        }

        if (empty($evento['id']) || empty($evento['name']) || empty($evento['start_date'])) {
            return null;
        }

        // Los eventos online no tienen dónde ir; la agenda es presencial.
        if (!empty($evento['is_online_event'])) {
            return null;
        }

        if (!empty($evento['is_cancelled'])) {
            return null;
        }

        $lugar = isset($evento['primary_venue']['name']) ? $evento['primary_venue']['name'] : '';
        $calle = isset($direccion['localized_address_display']) ? $direccion['localized_address_display'] : '';

        return [
            'id'          => (string) $evento['id'],
            'titulo'      => self::limpiar($evento['name'], 255),
            'descripcion' => isset($evento['summary']) ? self::limpiar($evento['summary'], 1000) : null,
            'imagen'      => isset($evento['image']['url']) ? $evento['image']['url'] : null,
            'url'         => isset($evento['url']) ? $evento['url'] : '',
            'fecha'       => $evento['start_date'],
            'hora'        => self::hora(isset($evento['start_time']) ? $evento['start_time'] : null),
            // El nombre del lugar delante: "Blue Velvet — Bolívar 624" ubica
            // mejor que la calle sola.
            'direccion'   => self::limpiar(trim($lugar && $calle ? "$lugar — $calle" : $lugar . $calle), 500),
            'latitud'     => $latitud,
            'longitud'    => $longitud,
            // El listado no trae precio. Se deja sin dato en lugar de suponer
            // que es gratis, que sería anunciar algo falso.
            'precio_desde' => null,
        ];
    }

    /** Normaliza HH:MM a HH:MM:SS, que es lo que espera la columna. */
    private static function hora($hora)
    {
        if (!is_string($hora) || !preg_match('/^(\d{1,2}):(\d{2})/', $hora, $m)) {
            return null;
        }

        return sprintf('%02d:%s:00', (int) $m[1], $m[2]);
    }

    private static function limpiar($texto, $largo)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $texto)));

        return mb_substr($texto, 0, $largo);
    }

    /** Único punto que sale a la red. */
    private function traerConCurl($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_USERAGENT, self::AGENTE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept-Language: es-AR,es;q=0.9']);

        $cuerpo = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $estado === 200 && is_string($cuerpo) ? $cuerpo : '';
    }
}
