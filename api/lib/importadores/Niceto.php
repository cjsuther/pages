<?php

/**
 * Lee la agenda de nicetoclub.com.
 *
 * La agenda es una grilla que el sitio arma en el servidor a partir de su
 * ticketera, y deja lo importante en atributos propios —data-name, data-lugar,
 * data-lazy-src— en lugar de sólo maquetarlo. Se lee eso: sobrevive a un
 * cambio de estilos, que es lo que más seguido rompe un adaptador.
 *
 * Todo sale de un único pedido, así que acá no hay pausa entre pedidos que
 * administrar. El robots.txt del sitio no restringe nada.
 *
 * Lo que la agenda no publica son coordenadas, y Rezonar las necesita para el
 * mapa. No hace falta pedirlas por evento: Niceto tiene dos direcciones fijas
 * —la sala de Niceto Vega y la de Humboldt— así que alcanza con saber en cuál
 * toca cada show y geocodificar esas dos, que además quedan cacheadas.
 */
class Niceto
{
    const BASE = 'https://nicetoclub.com';

    /** Un bot que no se identifica es un bot que se bloquea con razón. */
    const AGENTE = 'Mozilla/5.0 (compatible; RezonarBot/1.0; +https://rezon.ar)';

    /** Tope por corrida. La agenda publicada nunca pasa de unas decenas. */
    const MAX_EVENTOS = 60;

    /**
     * Las salas y su dirección.
     *
     * Niceto Club y Niceto Bar son el mismo edificio; Humboldt es la sala de
     * la vuelta, con entrada propia. Mandar a alguien a la dirección
     * equivocada es peor que no publicar el evento, así que se distinguen.
     */
    const SALAS = [
        'humboldt'    => 'Humboldt 1574, Palermo, Ciudad Autónoma de Buenos Aires, Argentina',
        'niceto bar'  => 'Niceto Vega 5510, Palermo, Ciudad Autónoma de Buenos Aires, Argentina',
        'niceto club' => 'Niceto Vega 5510, Palermo, Ciudad Autónoma de Buenos Aires, Argentina',
    ];

    /** Sala a la que se atribuye un evento cuando no se puede determinar. */
    const SALA_POR_DEFECTO = 'niceto club';

    const MESES = [
        'ENE' => '01', 'FEB' => '02', 'MAR' => '03', 'ABR' => '04',
        'MAY' => '05', 'JUN' => '06', 'JUL' => '07', 'AGO' => '08',
        'SEP' => '09', 'SET' => '09', 'OCT' => '10', 'NOV' => '11', 'DIC' => '12',
    ];

    /** @var callable Trae una URL; se sustituye en los tests. */
    private $traer;

    /** @var Geocodificador */
    private $geo;

    public function __construct($traer = null, $geo = null)
    {
        $this->traer = $traer === null ? [$this, 'traerConCurl'] : $traer;
        $this->geo = $geo === null ? new Geocodificador() : $geo;
    }

    /**
     * Eventos de la agenda.
     *
     * @param array $parametros ['filtro' => 'jazz', 'sala' => 'humboldt', 'max_eventos' => 40]
     * @param PDO   $db         Para cachear la geocodificación de las salas
     * @return array Eventos con la forma que espera Importador
     */
    public function eventos(array $parametros = [], $db = null)
    {
        $filtro = isset($parametros['filtro']) ? self::paraComparar($parametros['filtro']) : '';
        $sala = isset($parametros['sala']) ? self::paraComparar($parametros['sala']) : '';
        $tope = min(self::MAX_EVENTOS, max(1, (int) (isset($parametros['max_eventos']) ? $parametros['max_eventos'] : self::MAX_EVENTOS)));

        $html = call_user_func($this->traer, self::BASE . '/agenda/');

        if (!is_string($html) || $html === '') {
            return [];
        }

        $encontrados = [];
        $coordenadas = [];

        foreach (self::tarjetas($html) as $tarjeta) {
            $evento = self::normalizar($tarjeta);

            if ($evento === null) {
                continue;
            }

            if ($filtro !== '' && mb_strpos(self::paraComparar($evento['titulo']), $filtro) === false) {
                continue;
            }

            if ($sala !== '' && mb_strpos($evento['sala'], $sala) === false) {
                continue;
            }

            // Las direcciones son dos: se resuelven una vez por corrida y el
            // geocodificador además las cachea entre corridas.
            $direccion = $evento['direccion'];

            if (!array_key_exists($direccion, $coordenadas)) {
                $coordenadas[$direccion] = $db === null ? null : $this->geo->coordenadas($db, $direccion);
            }

            $coords = $coordenadas[$direccion];

            if (empty($coords)) {
                // Sin coordenadas el evento no entra en el mapa, que es media
                // razón de ser de la agenda. Se descarta en vez de entrar a
                // medias.
                continue;
            }

            $evento['latitud'] = $coords['latitud'];
            $evento['longitud'] = $coords['longitud'];
            unset($evento['sala']);

            $encontrados[$evento['id']] = $evento;

            if (count($encontrados) >= $tope) {
                break;
            }
        }

        return array_values($encontrados);
    }

    /** Corta el HTML en tarjetas de evento. */
    public static function tarjetas($html)
    {
        // El $ final cierra la última tarjeta. Sin él, una grilla que no
        // termine en </section> devolvería un evento menos, y un evento
        // faltante no se nota como se nota un error.
        if (!preg_match_all('/<div class="event-card".*?(?=<div class="event-card"|<\/section>|$)/s', $html, $m)) {
            return [];
        }

        return $m[0];
    }

    /**
     * Pasa una tarjeta a la forma común.
     *
     * Devuelve además `sala` para poder filtrar por ella; el llamador la saca
     * antes de entregar el evento.
     *
     * @return array|null null si le falta algo que Rezonar necesita
     */
    public static function normalizar($tarjeta)
    {
        $titulo = self::atributo($tarjeta, 'data-name');
        $url = preg_match('/<a href="(https:\/\/venti[^"]+)"/', $tarjeta, $m) ? $m[1] : '';

        if ($titulo === '' || $url === '') {
            return null;
        }

        $fecha = self::fechaYHora($tarjeta);

        if ($fecha === null) {
            return null;
        }

        $sala = self::sala(self::atributo($tarjeta, 'data-lugar'), $titulo);
        $imagen = self::atributo($tarjeta, 'data-lazy-src');

        return [
            // El tramo final de la URL de la ticketera: es lo único estable
            // que publica la agenda. El orden de las tarjetas cambia todos los
            // días y usar la posición duplicaría los eventos en cada corrida.
            'id'          => substr(rtrim($url, '/'), strrpos(rtrim($url, '/'), '/') + 1),
            'titulo'      => self::limpiar($titulo, 255),
            'descripcion' => null,
            'imagen'      => $imagen === '' ? null : $imagen,
            'url'         => $url,
            'fecha'       => $fecha['fecha'],
            'hora'        => $fecha['hora'],
            'direccion'   => self::SALAS[$sala],
            'sala'        => $sala,
            // La grilla no publica precios. Se deja sin dato en lugar de
            // suponer que es gratis, que sería anunciar algo falso.
            'precio_desde' => null,
        ];
    }

    /**
     * Fecha y hora del bloque que las muestra en números grandes.
     *
     * Se lee sobre el texto sin etiquetas porque los números viven cada uno en
     * su propio div con estilos en línea: mirar la estructura sería atarse a
     * la maqueta, y el orden de los números no cambia aunque cambie el diseño.
     */
    public static function fechaYHora($tarjeta)
    {
        $plano = preg_replace('/\s+/u', '', preg_replace('/<[^>]+>/', '|', $tarjeta));

        if (!preg_match('/(\d{1,2})\|+([A-ZÁÉÍÓÚ]{3})\|(\d{4})\|+(\d{1,2})\|+(\d{2})\|hs/u', $plano, $m)) {
            return null;
        }

        $mes = isset(self::MESES[$m[2]]) ? self::MESES[$m[2]] : null;

        if ($mes === null) {
            return null;
        }

        return [
            'fecha' => sprintf('%04d-%s-%02d', (int) $m[3], $mes, (int) $m[1]),
            'hora'  => sprintf('%02d:%s:00', (int) $m[4], $m[5]),
        ];
    }

    /**
     * En qué sala toca.
     *
     * El atributo del sitio a veces viene vacío; en esos casos el título dice
     * la sala, porque la agenda la incluye en el nombre del show.
     */
    public static function sala($declarada, $titulo)
    {
        foreach ([$declarada, $titulo] as $texto) {
            $comparable = self::paraComparar($texto);

            // Humboldt primero: sus eventos se anuncian como "en Humboldt |
            // Niceto Club" y quedarían atribuidos a la sala equivocada.
            foreach (['humboldt', 'niceto bar', 'niceto club'] as $sala) {
                if ($comparable !== '' && mb_strpos($comparable, $sala) !== false) {
                    return $sala;
                }
            }
        }

        return self::SALA_POR_DEFECTO;
    }

    private static function atributo($tarjeta, $nombre)
    {
        return preg_match('/' . preg_quote($nombre, '/') . '="([^"]*)"/', $tarjeta, $m)
            ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')
            : '';
    }

    /** Para comparar sin que molesten mayúsculas, tildes ni espacios de más. */
    private static function paraComparar($texto)
    {
        $texto = mb_strtolower(trim((string) $texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/u', ' ', $texto);
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, self::AGENTE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept-Language: es-AR,es;q=0.9']);

        $cuerpo = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $estado === 200 && is_string($cuerpo) ? $cuerpo : '';
    }
}
