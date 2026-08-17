<?php

/**
 * Lee la cartelera de C Art Media.
 *
 * El sitio es una aplicación de una sola página: el HTML llega vacío y la
 * cartelera la pide el navegador a una API propia. Se le pide a esa API, que
 * devuelve la agenda entera en un único pedido y con los datos ya separados
 * —fecha, hora, sala, imagen, link de venta—, sin nada que interpretar.
 *
 * Es la fuente más liviana de todas: un pedido por corrida contra un endpoint
 * que el propio sitio usa en cada visita.
 *
 * Todas las salas —Central, Corrientes, Sótano— son del mismo complejo, así
 * que la dirección es una y se geocodifica una vez.
 */
class ArtMedia
{
    /** La API que usa el sitio para dibujar su cartelera. */
    const API = 'https://ojtwazeqh7.execute-api.us-east-1.amazonaws.com/prod/events';

    /** Un bot que no se identifica es un bot que se bloquea con razón. */
    const AGENTE = 'Mozilla/5.0 (compatible; RezonarBot/1.0; +https://rezon.ar)';

    const DIRECCION = 'Av. Corrientes 6271, Chacarita, Ciudad Autónoma de Buenos Aires, Argentina';

    /** Nombre con el que se muestra el lugar delante de la calle. */
    const LUGAR = 'C Art Media';

    /** Tope por corrida. La cartelera publicada ronda los 40 shows. */
    const MAX_EVENTOS = 60;

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
     * Eventos de la cartelera.
     *
     * @param array $parametros ['filtro' => 'rock', 'sala' => 'sotano', 'max_eventos' => 40]
     * @param PDO   $db         Para cachear la geocodificación
     * @return array Eventos con la forma que espera Importador
     */
    public function eventos(array $parametros = [], $db = null)
    {
        $filtro = isset($parametros['filtro']) ? self::paraComparar($parametros['filtro']) : '';
        $sala = isset($parametros['sala']) ? self::paraComparar($parametros['sala']) : '';
        $tope = min(self::MAX_EVENTOS, max(1, (int) (isset($parametros['max_eventos']) ? $parametros['max_eventos'] : self::MAX_EVENTOS)));

        $coords = $db === null ? null : $this->geo->coordenadas($db, self::DIRECCION);

        if (empty($coords)) {
            // Todos los shows comparten dirección: si no se puede geocodificar
            // no entra ninguno, así que no tiene sentido pedir la cartelera.
            return [];
        }

        $cuerpo = call_user_func($this->traer, self::API);
        $encontrados = [];

        foreach (self::eventosDeLaRespuesta($cuerpo) as $crudo) {
            $evento = self::normalizar($crudo);

            if ($evento === null) {
                continue;
            }

            if ($filtro !== '' && mb_strpos(self::paraComparar($evento['titulo']), $filtro) === false) {
                continue;
            }

            if ($sala !== '' && mb_strpos(self::paraComparar($evento['sala']), $sala) === false) {
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

    /**
     * Saca la lista de eventos de la respuesta.
     *
     * La API contesta con la forma de una función Lambda —un sobre con
     * statusCode y body— y el body a veces viaja como objeto y a veces como
     * texto JSON. Se contemplan los dos: cuál toca no es asunto nuestro.
     */
    public static function eventosDeLaRespuesta($cuerpo)
    {
        $datos = is_string($cuerpo) ? json_decode($cuerpo, true) : $cuerpo;

        if (!is_array($datos)) {
            return [];
        }

        if (isset($datos['body'])) {
            $datos = is_string($datos['body']) ? json_decode($datos['body'], true) : $datos['body'];
        }

        if (!is_array($datos) || !isset($datos['events']) || !is_array($datos['events'])) {
            return [];
        }

        return $datos['events'];
    }

    /**
     * Pasa un evento de la API a la forma común.
     *
     * Devuelve además `sala` para poder filtrar por ella; el llamador la saca
     * antes de entregar el evento.
     *
     * @return array|null null si le falta algo que Rezonar necesita
     */
    public static function normalizar(array $evento)
    {
        // La API publica borradores junto con lo que está al aire.
        if (isset($evento['Publicado']) && mb_strtolower(trim($evento['Publicado'])) !== 'si') {
            return null;
        }

        $titulo = self::limpiar(isset($evento['title']) ? $evento['title'] : '', 255);
        $fecha = self::fecha(isset($evento['day']) ? $evento['day'] : null);

        if ($titulo === '' || $fecha === null || empty($evento['id'])) {
            return null;
        }

        $sala = self::limpiar(isset($evento['nave']) ? $evento['nave'] : '', 60);

        return [
            'id'          => (string) $evento['id'],
            'titulo'      => $titulo,
            'descripcion' => self::descripcion($evento),
            'imagen'      => self::imagen($evento),
            'url'         => isset($evento['linkPago']) ? trim($evento['linkPago']) : '',
            'fecha'       => $fecha,
            'hora'        => self::hora(isset($evento['hs']) ? $evento['hs'] : null),
            // El nombre del lugar y la sala delante de la calle: "C Art Media
            // (Sótano) — Av. Corrientes 6271" ubica mejor que la calle sola.
            'direccion'   => self::LUGAR . ($sala === '' ? '' : " ($sala)") . ' — ' . self::DIRECCION,
            'sala'        => $sala,
            // La API no publica precios. Se deja sin dato en lugar de suponer
            // que es gratis, que sería anunciar algo falso.
            'precio_desde' => null,
        ];
    }

    /**
     * Fecha del show.
     *
     * El campo viene como instante ISO a medianoche UTC. Se toma el tramo de
     * la fecha tal cual: convertir a la zona local correría los shows al día
     * anterior, porque en Buenos Aires esa medianoche son las 21 del día que
     * viene antes.
     */
    public static function fecha($dia)
    {
        if (!is_string($dia) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($dia), $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }

    /** La hora viene como "20" o como "23:30", según cómo la haya cargado el lugar. */
    public static function hora($hs)
    {
        if (!is_string($hs) && !is_numeric($hs)) {
            return null;
        }

        if (!preg_match('/^\s*(\d{1,2})(?:[:.](\d{2}))?\s*$/', (string) $hs, $m)) {
            return null;
        }

        $minutos = isset($m[2]) ? $m[2] : '00';

        if ((int) $m[1] > 23 || (int) $minutos > 59) {
            return null;
        }

        return sprintf('%02d:%s:00', (int) $m[1], $minutos);
    }

    /**
     * Imagen del evento.
     *
     * Cuando el lugar no cargó una, la API arma igual la URL y termina
     * apuntando a un archivo llamado "null". Pasar eso sería publicar una
     * imagen rota.
     */
    public static function imagen(array $evento)
    {
        foreach (['imageAgendaUrl', 'imageUrl'] as $campo) {
            $url = isset($evento[$campo]) ? trim($evento[$campo]) : '';

            if ($url !== '' && substr($url, -5) !== '/null' && strpos($url, 'http') === 0) {
                return $url;
            }
        }

        return null;
    }

    /** El copete y el texto largo, que viene con HTML del editor del sitio. */
    private static function descripcion(array $evento)
    {
        $partes = [];

        foreach (['subTitle', 'description'] as $campo) {
            $texto = self::limpiar(isset($evento[$campo]) ? $evento[$campo] : '', 1000);

            if ($texto !== '') {
                $partes[] = $texto;
            }
        }

        return empty($partes) ? null : mb_substr(implode('. ', $partes), 0, 1000);
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
        $texto = html_entity_decode((string) $texto, ENT_QUOTES, 'UTF-8');
        // El espacio duro entra por el editor del sitio y sobrevive al trim.
        $texto = str_replace("\xc2\xa0", ' ', strip_tags($texto));
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));

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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        $cuerpo = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $estado === 200 && is_string($cuerpo) ? $cuerpo : '';
    }
}
