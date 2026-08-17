<?php

/**
 * Lee la cartelera de boleteria.com.ar.
 *
 * El listado de la home da los enlaces a cada evento, y cada ficha publica
 * schema.org completo: fecha con zona horaria, lugar, dirección, imagen,
 * descripción y precio. Se lee eso en lugar de interpretar la maqueta, que
 * cambia con cada rediseño.
 *
 * Lo único que no publica son coordenadas, y Rezonar las necesita para el
 * mapa: se resuelven con el geocodificador, que las cachea por dirección.
 *
 * Entrar a cada ficha es un pedido más, así que el filtro se aplica sobre el
 * listado —donde ya vienen los títulos— y sólo se piden las que van a entrar.
 */
class Boleteria
{
    const BASE = 'https://www.boleteria.com.ar';

    const AGENTE = 'Mozilla/5.0 (compatible; RezonarBot/1.0; +https://rezon.ar)';

    /**
     * Segundos entre pedidos.
     *
     * Boletería está detrás de Cloudflare con un límite de ritmo que no avisa
     * cuánto es: el 429 no trae Retry-After. Seis segundos parecían alcanzar y
     * no alcanzaban —en producción cortaba en la primera ficha y la fuente
     * terminaba sin traer nada—; medido contra el sitio, a quince segundos
     * entran siete fichas seguidas sin un solo corte. La tarea corre de
     * madrugada una vez por día, así que esperar no cuesta nada.
     */
    const PAUSA = 15;

    /**
     * Espera antes de reintentar una ficha que el sitio cortó.
     *
     * Bien por encima de la pausa habitual: si el ritmo normal no alcanzó,
     * reintentar al mismo ritmo tampoco va a alcanzar.
     */
    const ESPERA_TRAS_CORTE = 60;

    /**
     * Tope de fichas por corrida.
     *
     * Bajo a propósito: a quince segundos cada una, pedir más alargaría la
     * corrida sin necesidad. Los eventos se acumulan noche a noche.
     */
    const MAX_EVENTOS = 15;

    /** @var callable */
    private $traer;

    /** @var Geocodificador */
    private $geo;

    /** @var int */
    private $pausa;

    /** @var int Espera antes de reintentar una ficha cortada. */
    private $espera;

    /** @var bool true si el sitio cortó los pedidos por ritmo. */
    private $limitado = false;

    public function __construct($traer = null, $geo = null)
    {
        $this->traer = $traer === null ? [$this, 'traerConCurl'] : $traer;
        $this->geo = $geo === null ? new Geocodificador() : $geo;

        // Las esperas son cortesía con el sitio ajeno. Con un lector inyectado
        // no hay sitio ajeno, y esperar sólo haría lenta la suite de tests.
        $this->pausa = $traer === null ? self::PAUSA : 0;
        $this->espera = $traer === null ? self::ESPERA_TRAS_CORTE : 0;
    }

    /**
     * @param array $parametros {
     *   @type string $filtro       Texto que debe aparecer en el título o el productor
     *   @type int    $max_eventos  Tope de fichas a pedir
     * }
     */
    public function eventos(array $parametros = [], $db = null)
    {
        $filtro = isset($parametros['filtro']) ? mb_strtolower(trim($parametros['filtro'])) : '';
        $tope = min(self::MAX_EVENTOS, max(1, (int) (isset($parametros['max_eventos']) ? $parametros['max_eventos'] : 40)));

        $listado = call_user_func($this->traer, self::BASE . '/');

        if (!is_string($listado) || $listado === '') {
            return [];
        }

        $candidatos = self::candidatos($listado);

        if ($filtro !== '') {
            $filtro = self::paraComparar($filtro);

            $candidatos = array_values(array_filter($candidatos, function ($c) use ($filtro) {
                return mb_strpos(self::paraComparar($c['titulo'] . ' ' . $c['productor']), $filtro) !== false;
            }));
        }

        $encontrados = [];

        foreach (array_slice($candidatos, 0, $tope) as $candidato) {
            if ($this->pausa > 0) {
                sleep($this->pausa);
            }

            $ficha = call_user_func($this->traer, $candidato['url']);

            if ($ficha === false) {
                $ficha = $this->reintentarTrasCorte($candidato['url']);
            }

            if ($ficha === false) {
                // Seguir pidiendo con el sitio cortando es maltratarlo y no
                // trae nada. Se corta y se aprovecha lo que ya vino.
                $this->limitado = true;
                break;
            }

            if (!is_string($ficha) || $ficha === '') {
                continue;
            }

            $evento = self::deLaFicha($ficha, $candidato['id']);

            if ($evento === null) {
                continue;
            }

            // Rezonar necesita coordenadas; Boletería no las publica.
            if ($db !== null && !empty($evento['direccion'])) {
                $coords = $this->geo->coordenadas($db, $evento['direccion']);

                if ($coords === null) {
                    continue;
                }

                $evento['latitud'] = $coords['latitud'];
                $evento['longitud'] = $coords['longitud'];
            }

            $encontrados[$evento['id']] = $evento;
        }

        // Sin nada y con el sitio limitando, el problema es el ritmo y no que
        // haya cambiado la página. Conviene que el mensaje lo diga.
        if (empty($encontrados) && $this->limitado) {
            throw new RuntimeException('Boletería limitó los pedidos (429); se reintenta en la próxima corrida');
        }

        return array_values($encontrados);
    }

    /**
     * Segunda oportunidad después de un corte.
     *
     * Un 429 aislado no puede terminar la corrida entera: si la fuente venía
     * trayendo bien y el sitio corta una vez, abandonar deja la agenda a
     * medias. Se espera un rato largo —más que la pausa habitual, porque
     * evidentemente el ritmo normal no alcanzó— y se pide esa misma ficha una
     * sola vez más.
     */
    private function reintentarTrasCorte($url)
    {
        if ($this->espera > 0) {
            sleep($this->espera);
        }

        return call_user_func($this->traer, $url);
    }

    /**
     * Enlaces a fichas del listado, con su título y su productor.
     *
     * El id sale de la URL (…-e2912): es estable y es lo que permite reconocer
     * el mismo evento en corridas siguientes.
     */
    public static function candidatos($html)
    {
        preg_match_all('#href="(/p/([^/"]+)/evento/([^"]*?-e(\d+)))"#', $html, $m, PREG_SET_ORDER);

        $porId = [];

        foreach ($m as $coincidencia) {
            $id = $coincidencia[4];

            if (isset($porId[$id])) {
                continue;
            }

            $porId[$id] = [
                'id'        => $id,
                'url'       => self::BASE . $coincidencia[1],
                'productor' => $coincidencia[2],
                // El slug del evento sirve de título aproximado para filtrar
                // sin tener que entrar a la ficha.
                'titulo'    => str_replace('-', ' ', preg_replace('/-e\d+$/', '', $coincidencia[3])),
            ];
        }

        return array_values($porId);
    }

    /**
     * Arma el evento a partir del schema.org de la ficha.
     *
     * @return array|null null si le falta lo indispensable
     */
    public static function deLaFicha($html, $id)
    {
        $schema = self::schemaEvent($html);

        if ($schema === null) {
            return null;
        }

        $fechaHora = self::fechaHora(isset($schema['startDate']) ? $schema['startDate'] : null);

        if ($fechaHora === null) {
            return null;
        }

        if (empty($schema['name'])) {
            return null;
        }

        return [
            'id'           => (string) $id,
            'titulo'       => self::limpiar($schema['name'], 255),
            'descripcion'  => isset($schema['description']) ? self::limpiar($schema['description'], 1000) : null,
            'imagen'       => self::imagen($schema),
            'url'          => isset($schema['url']) ? $schema['url'] : '',
            'fecha'        => $fechaHora['fecha'],
            'hora'         => $fechaHora['hora'],
            'direccion'    => self::direccion($schema),
            // Las completa el geocodificador; sin ellas el evento se descarta.
            'latitud'      => null,
            'longitud'     => null,
            'precio_desde' => self::precio($schema),
        ];
    }

    /** El bloque ld+json de tipo Event, entre los varios que trae la ficha. */
    public static function schemaEvent($html)
    {
        if (!preg_match_all('#<script[^>]*ld\+json[^>]*>(.*?)</script>#s', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $bruto) {
            $datos = json_decode(trim($bruto), true);

            if (!is_array($datos)) {
                continue;
            }

            foreach (isset($datos[0]) || !isset($datos['@type']) ? $datos : [$datos] as $item) {
                if (is_array($item) && isset($item['@type']) && $item['@type'] === 'Event') {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Separa "2026-11-27T21:00-03:00" en fecha y hora.
     *
     * Se toma tal cual y no se convierte a UTC: la hora publicada es la hora
     * local del show, que es la que tiene que ver el público.
     */
    public static function fechaHora($inicio)
    {
        if (!is_string($inicio) || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}):(\d{2}))?/', $inicio, $m)) {
            return null;
        }

        return [
            'fecha' => $m[1],
            'hora'  => isset($m[2]) ? "{$m[2]}:{$m[3]}:00" : null,
        ];
    }

    /** "Studio Theater — Rosario de Santa Fe 272, Córdoba" */
    public static function direccion(array $schema)
    {
        $lugar = isset($schema['location']['name']) ? $schema['location']['name'] : '';
        $dir = isset($schema['location']['address']) ? $schema['location']['address'] : [];

        $partes = array_values(array_filter([
            isset($dir['streetAddress']) ? $dir['streetAddress'] : null,
            isset($dir['addressRegion']) ? $dir['addressRegion'] : null,
        ], function ($p) { return is_string($p) && trim($p) !== ''; }));

        $calle = implode(', ', $partes);

        if ($lugar !== '' && $calle !== '') {
            return self::limpiar("$lugar — $calle", 500);
        }

        return self::limpiar($lugar . $calle, 500);
    }

    /**
     * Precio de la oferta.
     *
     * Un cero de Boletería significa que la entrada es gratis, y así se
     * anuncia; que no venga el dato es distinto y queda en null.
     */
    public static function precio(array $schema)
    {
        $precio = isset($schema['offers']['price']) ? $schema['offers']['price'] : null;

        if ($precio === null && isset($schema['offers'][0]['price'])) {
            $precio = $schema['offers'][0]['price'];
        }

        return is_numeric($precio) ? round((float) $precio, 2) : null;
    }

    private static function imagen(array $schema)
    {
        $imagen = isset($schema['image']) ? $schema['image'] : null;

        if (is_array($imagen)) {
            $imagen = isset($imagen[0]) ? $imagen[0] : null;
        }

        return is_string($imagen) && $imagen !== '' ? $imagen : null;
    }

    /**
     * Forma en la que se comparan filtro y candidato.
     *
     * Los slugs usan guiones ("chiste-stand-up") y uno escribe el filtro con
     * espacios ("stand up"). Sin unificar, el filtro no encontraría nada.
     */
    public static function paraComparar($texto)
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', mb_strtolower((string) $texto))));
    }

    private static function limpiar($texto, $largo)
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $texto))), 0, $largo);
    }

    private function traerConCurl($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_USERAGENT, self::AGENTE);

        $cuerpo = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // false y cadena vacía no son lo mismo: false es "el sitio me cortó
        // por ritmo, esperá y volvé", y eso merece un reintento. La cadena
        // vacía es cualquier otro problema, que no mejora esperando.
        if ($estado === 429) {
            return false;
        }

        return $estado === 200 && is_string($cuerpo) ? $cuerpo : '';
    }
}
