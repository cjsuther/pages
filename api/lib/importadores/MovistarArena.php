<?php

/**
 * Lee la cartelera del Movistar Arena (movistararena.com.ar).
 *
 * El sitio es Blazor Server: a un navegador le llega el HTML vacío y los shows
 * viajan después por una conexión en vivo con el servidor. No hay API, JSON
 * embebido ni schema.org. Lo que sí hace es armar la página completa en el
 * servidor cuando quien la pide es el lector de vistas previas de Facebook
 * —para que un link compartido muestre título e imagen—, así que se pide con
 * ese agente. Es una decisión tomada a sabiendas: si el sitio deja de
 * prerenderizar para ese agente, la fuente devuelve cero eventos y el
 * Importador lo informa como "¿cambió el sitio?".
 *
 * El listado (/shows) trae en un solo pedido todos los shows con título,
 * imagen y la primera fecha con año. Cada ficha agrega lo que falta: las
 * funciones —día y mes, sin año—, la hora del show, el precio "desde" y la
 * descripción. Cada función es un evento de Rezonar, como en el Colón: el
 * mismo show en dos noches son dos salidas distintas para el público.
 *
 * Todo pasa en una sola sala, así que la dirección es una y se geocodifica
 * una vez (el geocodificador además la cachea entre corridas).
 */
class MovistarArena
{
    const BASE = 'https://www.movistararena.com.ar';

    /** El único agente para el que el sitio arma la página en el servidor. */
    const AGENTE = 'facebookexternalhit/1.1';

    const DIRECCION = 'Movistar Arena — Humboldt 450, Villa Crespo, Ciudad Autónoma de Buenos Aires, Argentina';

    /** Lo que se le pasa al geocodificador: sin el nombre de la sala, que lo confunde. */
    const DIRECCION_PARA_MAPA = 'Humboldt 450, Villa Crespo, Ciudad Autónoma de Buenos Aires, Argentina';

    /** Segundos entre fichas. El sitio no limita, pero no hay apuro: corre de madrugada. */
    const PAUSA = 2;

    /**
     * Fichas por corrida.
     *
     * La corrida diaria de todas las fuentes tiene diez minutos. Con la pausa,
     * cuarenta fichas son un minuto y medio. Los shows que no llegan a tener
     * ficha entran igual con lo del listado —sin hora ni precio—, y la
     * completan en la corrida en que les toque, que es cuando se acercan.
     */
    const MAX_FICHAS = 40;

    const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    /** @var callable Trae una URL; se sustituye en los tests. */
    private $traer;

    /** @var Geocodificador */
    private $geo;

    /** @var int */
    private $pausa;

    public function __construct($traer = null, $geo = null)
    {
        $this->traer = $traer === null ? [$this, 'traerConCurl'] : $traer;
        $this->geo = $geo === null ? new Geocodificador() : $geo;

        // Con un lector inyectado no hay sitio ajeno que cuidar.
        $this->pausa = $traer === null ? self::PAUSA : 0;
    }

    /**
     * Eventos de la cartelera, uno por función.
     *
     * @param array $parametros ['filtro' => 'babasonicos', 'max_fichas' => 40]
     * @param PDO   $db         Para geocodificar la sala
     * @return array Eventos con la forma que espera Importador
     */
    public function eventos(array $parametros = [], $db = null)
    {
        $filtro = isset($parametros['filtro']) ? self::paraComparar($parametros['filtro']) : '';
        $tope = min(self::MAX_FICHAS, max(0, (int) (isset($parametros['max_fichas']) ? $parametros['max_fichas'] : self::MAX_FICHAS)));

        $listado = call_user_func($this->traer, self::BASE . '/shows');

        if (!is_string($listado) || $listado === '') {
            return [];
        }

        $coords = $db === null ? null : $this->geo->coordenadas($db, self::DIRECCION_PARA_MAPA);

        // Sin coordenadas ningún evento entra al mapa: el Importador los
        // descartaría uno por uno. Mejor decirlo una vez y claro.
        if ($db !== null && empty($coords)) {
            throw new RuntimeException('no se pudo geocodificar la dirección del Movistar Arena');
        }

        $encontrados = [];
        $fichas = 0;

        foreach (self::shows($listado) as $show) {
            if ($filtro !== '' && mb_strpos(self::paraComparar($show['titulo']), $filtro) === false) {
                continue;
            }

            $ficha = null;

            if ($fichas < $tope) {
                if ($fichas > 0 && $this->pausa > 0) {
                    sleep($this->pausa);
                }

                $fichas++;
                $html = call_user_func($this->traer, $show['url']);
                $ficha = is_string($html) && $html !== '' ? self::ficha($html, $show['fecha']) : null;
            }

            foreach (self::funciones($show, $ficha) as $evento) {
                $evento['latitud'] = $coords === null ? null : $coords['latitud'];
                $evento['longitud'] = $coords === null ? null : $coords['longitud'];
                $encontrados[$evento['id']] = $evento;
            }
        }

        return array_values($encontrados);
    }

    /**
     * Los shows del listado: id, título, primera fecha, imagen y ficha.
     *
     * El id es el de la URL (/show/{uuid}): estable entre corridas.
     */
    public static function shows($html)
    {
        preg_match_all(
            '#<div class="evento">\s*<div class="box-img" style="background-image:\s*url\(([^)]*)\);">\s*<a href="/show/([0-9a-f-]{36})"></a>.*?<h5>(.*?)</h5>\s*<span>(.*?)</span>#s',
            $html,
            $m,
            PREG_SET_ORDER
        );

        $porId = [];

        foreach ($m as $c) {
            $id = $c[2];
            $fecha = self::fechaDelListado(self::texto($c[4]));

            if (isset($porId[$id]) || $fecha === null) {
                continue;
            }

            $imagen = trim(html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), " '\"");

            $porId[$id] = [
                'id'     => $id,
                'titulo' => self::texto($c[3]),
                'fecha'  => $fecha,
                'imagen' => preg_match('#^https://#', $imagen) ? $imagen : null,
                'url'    => self::BASE . '/show/' . $id,
            ];
        }

        return array_values($porId);
    }

    /**
     * "10 octubre 2026", o "16 octubre 2026 y 1 fecha más": la primera fecha.
     *
     * @return string|null Y-m-d
     */
    public static function fechaDelListado($texto)
    {
        if (!preg_match('/^(\d{1,2})\s+([a-záéíóú]+)\s+(\d{4})/u', mb_strtolower(trim($texto)), $m) || !isset(self::MESES[$m[2]])) {
            return null;
        }

        return checkdate(self::MESES[$m[2]], (int) $m[1], (int) $m[3])
            ? sprintf('%04d-%02d-%02d', $m[3], self::MESES[$m[2]], $m[1])
            : null;
    }

    /**
     * Lo que agrega la ficha: funciones, precio y descripción.
     *
     * Las funciones publican día y mes, sin año. El año sale de la primera
     * fecha del listado: una función de un mes anterior a ese es del año
     * siguiente (un show de diciembre con otra fecha en enero).
     *
     * @param string $primera Y-m-d, la primera fecha según el listado
     * @return array{funciones: array<array{fecha: string, hora: string|null}>, precio: float|null, descripcion: string|null}
     */
    public static function ficha($html, $primera)
    {
        $anio = (int) substr($primera, 0, 4);
        $mesInicial = (int) substr($primera, 5, 2);
        $funciones = [];

        $filas = preg_split('#<div class="evento-row">#', $html);
        array_shift($filas);

        foreach ($filas as $fila) {
            if (!preg_match('#<div class="fecha">\s*<p>(\d{1,2})</p>\s*<span>([^<]+)</span>#u', $fila, $f)) {
                continue;
            }

            $mes = mb_strtolower(trim($f[2]));

            if (!isset(self::MESES[$mes])) {
                continue;
            }

            $numeroDeMes = self::MESES[$mes];
            $anioDeLaFuncion = $numeroDeMes < $mesInicial ? $anio + 1 : $anio;

            if (!checkdate($numeroDeMes, (int) $f[1], $anioDeLaFuncion)) {
                continue;
            }

            $hora = null;

            // La de "Show", no la de "Puertas": es la que anuncia el evento.
            if (preg_match('#<p>(\d{1,2}):(\d{2})\s*hs</p>\s*<span>\s*Show\s*</span>#iu', $fila, $h)) {
                $hora = sprintf('%02d:%02d:00', $h[1], $h[2]);
            }

            $fecha = sprintf('%04d-%02d-%02d', $anioDeLaFuncion, $numeroDeMes, $f[1]);
            $funciones[$fecha] = ['fecha' => $fecha, 'hora' => $hora];
        }

        ksort($funciones);

        return [
            'funciones'   => array_values($funciones),
            'precio'      => self::precio($html),
            'descripcion' => self::descripcion($html),
        ];
    }

    /** "Entradas desde $ 90.000" → 90000.0 */
    public static function precio($html)
    {
        if (!preg_match('#<p>\s*Entradas desde\s*</p>\s*<span>\s*\$\s*([\d.]+)(?:,(\d{1,2}))?\s*</span>#u', $html, $m)) {
            return null;
        }

        return (float) (str_replace('.', '', $m[1]) . (isset($m[2]) ? '.' . $m[2] : ''));
    }

    /** El texto de "Acerca del evento", sin la maqueta. */
    public static function descripcion($html)
    {
        // Termina donde empiezan los paneles de condiciones (máximo por
        // compra, menores, accesibilidad), que no son del show sino de la sala.
        if (!preg_match('#<h4>\s*Acerca del evento\s*</h4>(.*?)(?:<div class="mud-expansion-panels"|<h[1-6][\s>]|$)#su', $html, $m)) {
            return null;
        }

        // Los párrafos se separan con un espacio y no se pegan entre sí.
        $texto = self::texto(preg_replace('#</p>#i', ' ', $m[1]));

        return $texto === '' ? null : mb_substr($texto, 0, 1000);
    }

    /**
     * Los eventos de un show: uno por función de la ficha, o la fecha del
     * listado si la ficha no se pidió o no trajo funciones.
     */
    public static function funciones(array $show, $ficha)
    {
        $funciones = $ficha !== null && $ficha['funciones'] !== []
            ? $ficha['funciones']
            : [['fecha' => $show['fecha'], 'hora' => null]];

        return array_map(function ($funcion) use ($show, $ficha) {
            return [
                'id'           => $show['id'] . ':' . $funcion['fecha'],
                'titulo'       => mb_substr($show['titulo'], 0, 255),
                'descripcion'  => $ficha === null ? null : $ficha['descripcion'],
                'imagen'       => $show['imagen'],
                'url'          => $show['url'],
                'fecha'        => $funcion['fecha'],
                'hora'         => $funcion['hora'],
                'direccion'    => self::DIRECCION,
                'latitud'      => null,
                'longitud'     => null,
                'precio_desde' => $ficha === null ? null : $ficha['precio'],
            ];
        }, $funciones);
    }

    private static function texto($html)
    {
        $texto = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $texto)));
    }

    private static function paraComparar($texto)
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $texto)));
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

        return $estado === 200 && is_string($cuerpo) ? $cuerpo : '';
    }
}
