<?php

/**
 * Lee el calendario del Teatro Colón.
 *
 * El calendario se pide por mes (/calendario/?a=2026&mes=8) y viene armado
 * desde el servidor, con una entrada por función: el mismo título repetido en
 * varias fechas son varias funciones, no un evento que dura un mes.
 *
 * Cada función publica el día, el horario, la producción a la que pertenece y
 * el enlace a la boletería. No hay JSON embebido ni API abierta —el REST de
 * WordPress tiene el tipo de contenido cerrado— así que se lee el HTML, pero
 * apoyándose en las clases estructurales del calendario (day-number, day-hour,
 * event-content) y no en la maqueta.
 *
 * El robots.txt del sitio no restringe nada.
 */
class Colon
{
    const BASE = 'https://teatrocolon.org.ar';

    /** Un bot que no se identifica es un bot que se bloquea con razón. */
    const AGENTE = 'Mozilla/5.0 (compatible; RezonarBot/1.0; +https://rezon.ar)';

    /**
     * La única dirección.
     *
     * Todas las salas del Colón —la principal, el Salón Dorado, el CETC—
     * están en el mismo edificio, así que se geocodifica una vez y listo.
     */
    const DIRECCION = 'Teatro Colón, Cerrito 628, San Nicolás, Ciudad Autónoma de Buenos Aires, Argentina';

    /** Segundos entre pedidos. Son pocos, pero es un sitio ajeno. */
    const PAUSA = 2;

    /** Meses que se miran hacia adelante por corrida. */
    const MESES = 3;

    /** Tope de meses, por si alguien carga un número grande en los parámetros. */
    const MAX_MESES = 6;

    /** Tope de funciones por corrida. */
    const MAX_EVENTOS = 80;

    /** @var callable Trae una URL; se sustituye en los tests. */
    private $traer;

    /** @var Geocodificador */
    private $geo;

    /** @var int Segundos entre pedidos. Cero cuando no se sale a la red. */
    private $pausa;

    public function __construct($traer = null, $geo = null)
    {
        $this->traer = $traer === null ? [$this, 'traerConCurl'] : $traer;
        $this->geo = $geo === null ? new Geocodificador() : $geo;

        // La pausa es cortesía con el sitio ajeno. Con un lector inyectado no
        // hay sitio ajeno, y esperar sólo haría lenta la suite de tests.
        $this->pausa = $traer === null ? self::PAUSA : 0;
    }

    /**
     * Funciones del calendario.
     *
     * @param array $parametros ['meses' => 3, 'filtro' => 'ballet', 'max_eventos' => 60]
     * @param PDO   $db         Para cachear la geocodificación
     * @return array Eventos con la forma que espera Importador
     */
    public function eventos(array $parametros = [], $db = null)
    {
        $filtro = isset($parametros['filtro']) ? self::paraComparar($parametros['filtro']) : '';
        $meses = min(self::MAX_MESES, max(1, (int) (isset($parametros['meses']) ? $parametros['meses'] : self::MESES)));
        $tope = min(self::MAX_EVENTOS, max(1, (int) (isset($parametros['max_eventos']) ? $parametros['max_eventos'] : self::MAX_EVENTOS)));

        $coords = $db === null ? null : $this->geo->coordenadas($db, self::DIRECCION);

        if (empty($coords)) {
            // Sin coordenadas ninguna función se puede ubicar en el mapa. Como
            // la dirección es una sola, o entran todas o no entra ninguna: no
            // tiene sentido pedir los meses para descartarlos después.
            return [];
        }

        $encontrados = [];
        $desde = self::mesActual();

        for ($i = 0; $i < $meses; $i++) {
            list($anio, $mes) = self::mesSumado($desde, $i);

            if ($i > 0 && $this->pausa > 0) {
                sleep($this->pausa);
            }

            $html = call_user_func($this->traer, self::BASE . '/calendario/?a=' . $anio . '&mes=' . $mes);

            if (!is_string($html) || $html === '') {
                continue;
            }

            foreach (self::items($html) as $item) {
                $evento = self::normalizar($item, $anio, $mes);

                if ($evento === null) {
                    continue;
                }

                if ($filtro !== '' && mb_strpos(self::paraComparar($evento['titulo']), $filtro) === false) {
                    continue;
                }

                $evento['latitud'] = $coords['latitud'];
                $evento['longitud'] = $coords['longitud'];

                $encontrados[$evento['id']] = $evento;

                if (count($encontrados) >= $tope) {
                    return array_values($encontrados);
                }
            }
        }

        return array_values($encontrados);
    }

    /** Corta el calendario en funciones. */
    public static function items($html)
    {
        // El <style> de la página nombra las mismas clases que el calendario:
        // buscar sobre el documento entero devolvería reglas de CSS.
        $partes = explode('</style>', $html);
        $cuerpo = array_pop($partes);

        // El $ final cierra la última función. Sin él, un calendario que no
        // termine en <footer> —o un recorte del HTML— devolvería una función
        // menos, y una función faltante no se nota como se nota un error.
        $patron = '/<div[^>]*class="calendar-item[^"]*".*?(?=<div[^>]*class="calendar-item|<footer|<\/body>|$)/s';

        return preg_match_all($patron, $cuerpo, $m) ? $m[0] : [];
    }

    /**
     * Pasa una función a la forma común.
     *
     * El año y el mes vienen de afuera: la entrada sólo publica el día, porque
     * el mes lo dice el encabezado del calendario que se pidió.
     *
     * @return array|null null si le falta algo que Rezonar necesita
     */
    public static function normalizar($item, $anio, $mes)
    {
        $titulo = preg_match('/<h1 class="colon-serif-regular">(.*?)<\/h1>/s', $item, $m)
            ? self::limpiar($m[1], 255)
            : '';

        $horario = self::horario($item);

        if ($titulo === '' || $horario === null) {
            return null;
        }

        if (!checkdate((int) $mes, $horario['dia'], (int) $anio)) {
            return null;
        }

        $produccion = preg_match('/href="(' . preg_quote(self::BASE, '/') . '\/produccion\/[^"]+)"/', $item, $m) ? $m[1] : '';
        $entradas = preg_match('/href="(https:\/\/(?:entradasba|comprar)[^"]+)"/', $item, $m) ? $m[1] : '';
        $imagen = preg_match('/<figure[^>]*>\s*<img[^>]*src="([^"]+)"/', $item, $m) ? $m[1] : '';
        $categoria = preg_match('/class="category">(.*?)<\/div>/s', $item, $m) ? self::limpiar($m[1], 1000) : '';

        $fecha = sprintf('%04d-%02d-%02d', (int) $anio, (int) $mes, $horario['dia']);

        return [
            // Una producción se da varias veces: el identificador tiene que
            // incluir la función, o las fechas siguientes pisarían a la
            // primera y la agenda mostraría una sola.
            'id'          => self::identificador($produccion, $titulo) . '-' . $fecha . '-' . str_replace(':', '', substr($horario['hora'], 0, 5)),
            'titulo'      => $titulo,
            // El calendario no trae sinopsis; la categoría (Ópera, Ballet,
            // Clásica Joven) es lo único que aporta contexto real.
            'descripcion' => $categoria === '' ? null : $categoria,
            'imagen'      => $imagen === '' ? null : $imagen,
            // Se prefiere la boletería: es lo que la persona busca. La ficha
            // de la producción queda de reserva cuando todavía no hay venta.
            'url'         => $entradas !== '' ? $entradas : $produccion,
            'fecha'       => $fecha,
            'hora'        => $horario['hora'],
            'direccion'   => self::DIRECCION,
            // El calendario no publica precios. Se deja sin dato en lugar de
            // suponer que es gratis, que sería anunciar algo falso.
            'precio_desde' => null,
        ];
    }

    /**
     * Día y hora de la función.
     *
     * El sitio los escribe como "mar_17.30 hs": el día de la semana no aporta
     * —se deduce de la fecha— y la hora usa punto en vez de dos puntos.
     */
    public static function horario($item)
    {
        if (!preg_match('/class="day-number">\s*(\d{1,2})/', $item, $dia)) {
            return null;
        }

        if (!preg_match('/class="day-hour">\s*[^\d<]*(\d{1,2})[.:](\d{2})/u', $item, $hora)) {
            return null;
        }

        if ((int) $hora[1] > 23 || (int) $hora[2] > 59) {
            return null;
        }

        return [
            'dia'  => (int) $dia[1],
            'hora' => sprintf('%02d:%s:00', (int) $hora[1], $hora[2]),
        ];
    }

    /** Parte estable del identificador: el slug de la producción. */
    private static function identificador($produccion, $titulo)
    {
        if ($produccion !== '') {
            $slug = basename(rtrim($produccion, '/'));

            if ($slug !== '') {
                return $slug;
            }
        }

        // Sin ficha de producción se cae al título, que es menos estable pero
        // sigue siendo el mismo mientras el sitio no lo cambie.
        $slug = preg_replace('/[^a-z0-9]+/', '-', self::paraComparar($titulo));

        return trim($slug, '-');
    }

    /** Año y mes de hoy, aparte para poder fijarlo en los tests. */
    public static function mesActual()
    {
        return [(int) date('Y'), (int) date('n')];
    }

    /** Suma meses cuidando el cambio de año. */
    public static function mesSumado(array $desde, $meses)
    {
        $total = ($desde[0] * 12) + ($desde[1] - 1) + (int) $meses;

        return [intdiv($total, 12), ($total % 12) + 1];
    }

    /** Para comparar sin que molesten mayúsculas, tildes ni espacios de más. */
    private static function paraComparar($texto)
    {
        $texto = mb_strtolower(trim((string) $texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return preg_replace('/\s+/u', ' ', $texto);
    }

    private static function limpiar($texto, $largo)
    {
        $texto = html_entity_decode((string) $texto, ENT_QUOTES, 'UTF-8');
        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags($texto)));

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
