<?php

/**
 * Informes de Google Analytics, por página.
 *
 * Google guarda cada visita con la ruta que se pidió, así que el informe de
 * una página sale filtrando por su ruta. Es la misma medición que ya se hace
 * del sitio entero: no se guarda nada por nuestra cuenta y no hay dos números
 * distintos para la misma cosa.
 *
 * Se habla con la API a mano y no con la librería de Google. La librería
 * arrastra medio SDK para lo que acá son dos llamadas: pedir un token firmando
 * un JWT —que este proyecto ya sabe hacer, en JWT.php— y pedir el informe.
 *
 * Lo que hay que saber antes de tocar esto:
 *
 * - La propiedad se identifica con un número, no con el G-XXXXXXXX. El G- es
 *   el identificador de medición, que es otra cosa y no sirve acá.
 * - Los datos no son de ahora: Google tarda hasta 48 horas en procesarlos del
 *   todo, así que el día de hoy siempre se ve incompleto. Se dice en pantalla.
 * - Quien bloquea publicidad no se mide. Es una parte real del público y hace
 *   que estos números sean un piso, no la verdad.
 */
class Analytics
{
    const ALCANCE = 'https://www.googleapis.com/auth/analytics.readonly';
    const URL_TOKEN = 'https://oauth2.googleapis.com/token';
    const URL_DATOS = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    /** Cuántas filas de cada listado. Más que esto no se lee, se estorba. */
    const FILAS = 8;

    /** @var HttpClient */
    private $http;

    /** El token vale una hora; dentro de un pedido alcanza con pedirlo una vez. */
    private static $token = null;

    public function __construct($http = null)
    {
        $this->http = $http === null ? new HttpClient() : $http;
    }

    /**
     * ¿Está configurado el acceso a los informes?
     *
     * Sin esto el panel no es un error: es una pantalla que explica qué falta.
     * Configurarlo es crear una cuenta de servicio en Google y darle permiso
     * de lectura sobre la propiedad, y eso no lo puede hacer el código.
     */
    public static function configurado()
    {
        return self::propiedad() !== '' && self::credenciales() !== null;
    }

    /**
     * Lo mismo, preguntado a la instancia.
     *
     * El handler pregunta por acá y no a la clase: así un test puede darle un
     * doble sin configurar y comprobar que la pantalla explica qué falta, en
     * vez de tener que desdefinir constantes.
     */
    public function estaListo()
    {
        return self::configurado();
    }

    /**
     * El informe de una página.
     *
     * @param string      $slug    la dirección de la página en rezon.ar
     * @param string|null $dominio su dominio propio, si tiene
     * @param int         $dias    la ventana, contando hoy
     */
    public function dePagina($slug, $dominio, $dias, $hoy = null)
    {
        $dias = self::diasValidos($dias);
        $hoy = $hoy === null ? Fechas::hoy() : $hoy;

        $desde = self::restar($hoy, $dias - 1);
        $previoHasta = self::restar($desde, 1);
        $previoDesde = self::restar($previoHasta, $dias - 1);

        $filtro = self::filtroDePagina($slug, $dominio);

        $informes = $this->pedir([
            // Totales, con el período anterior al lado para poder comparar. Un
            // número suelto no dice nada: 300 visitas puede ser el mejor mes o
            // la mitad del anterior.
            [
                'dateRanges' => [
                    ['startDate' => $desde, 'endDate' => $hoy, 'name' => 'actual'],
                    ['startDate' => $previoDesde, 'endDate' => $previoHasta, 'name' => 'previo'],
                ],
                'metrics' => self::metricas(),
                'dimensionFilter' => $filtro,
            ],
            [
                'dateRanges' => [['startDate' => $desde, 'endDate' => $hoy]],
                'dimensions' => [['name' => 'date']],
                'metrics' => self::metricas(),
                'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
                'dimensionFilter' => $filtro,
            ],
            [
                'dateRanges' => [['startDate' => $desde, 'endDate' => $hoy]],
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => self::metricas(),
                'dimensionFilter' => $filtro,
                'limit' => self::FILAS,
            ],
            [
                'dateRanges' => [['startDate' => $desde, 'endDate' => $hoy]],
                'dimensions' => [['name' => 'deviceCategory']],
                'metrics' => self::metricas(),
                'dimensionFilter' => $filtro,
                'limit' => self::FILAS,
            ],
            [
                'dateRanges' => [['startDate' => $desde, 'endDate' => $hoy]],
                'dimensions' => [['name' => 'city']],
                'metrics' => self::metricas(),
                'dimensionFilter' => $filtro,
                'limit' => self::FILAS,
            ],
        ]);

        if (isset($informes['error'])) {
            return $informes;
        }

        return [
            'configurado' => true,
            'dias'        => $dias,
            'desde'       => $desde,
            'hasta'       => $hoy,
            'resumen'     => self::totales($informes[0]),
            'por_dia'     => self::serieDiaria($informes[1], $desde, $hoy),
            'origen'      => self::listado($informes[2]),
            'dispositivo' => self::listado($informes[3]),
            'ciudad'      => self::listado($informes[4]),
        ];
    }

    // ------------------------------------------------------- hablar con Google

    /** Los cinco informes en una sola llamada. Es el máximo que acepta Google. */
    private function pedir(array $informes)
    {
        $token = $this->token();

        if ($token === null) {
            return ['error' => 'No pudimos autorizarnos contra Google Analytics'];
        }

        $respuesta = $this->http->postJson(
            self::URL_DATOS . self::propiedad() . ':batchRunReports',
            ['requests' => $informes],
            ['Authorization: Bearer ' . $token]
        );

        $cuerpo = json_decode($respuesta['body'], true);

        if ($respuesta['status'] !== 200 || !isset($cuerpo['reports'])) {
            return ['error' => self::motivo($cuerpo)];
        }

        return $cuerpo['reports'];
    }

    /**
     * Un token de acceso, a cambio de un JWT firmado con la clave de la cuenta
     * de servicio. Es el circuito de dos patas de OAuth: no hay nadie del otro
     * lado a quien pedirle permiso, el permiso ya se lo dio quien administra la
     * propiedad cuando agregó a esa cuenta como lectora.
     */
    private function token()
    {
        if (self::$token !== null) {
            return self::$token;
        }

        $cuenta = self::credenciales();

        if ($cuenta === null) {
            return null;
        }

        $ahora = time();
        $sinFirmar = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.'
            . self::base64url(json_encode([
                'iss'   => $cuenta['client_email'],
                'scope' => self::ALCANCE,
                'aud'   => self::URL_TOKEN,
                'iat'   => $ahora,
                'exp'   => $ahora + 3600,
            ]));

        $firma = '';

        if (!openssl_sign($sinFirmar, $firma, $cuenta['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $respuesta = $this->http->post(self::URL_TOKEN, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $sinFirmar . '.' . self::base64url($firma),
        ]);

        $cuerpo = json_decode($respuesta['body'], true);

        if ($respuesta['status'] !== 200 || !isset($cuerpo['access_token'])) {
            return null;
        }

        self::$token = $cuerpo['access_token'];

        return self::$token;
    }

    // ------------------------------------------------------- armar el pedido

    private static function metricas()
    {
        return [
            ['name' => 'screenPageViews'],
            ['name' => 'totalUsers'],
        ];
    }

    /**
     * Qué visitas son de esta página.
     *
     * Su ruta en rezon.ar, y además todo lo que pase por su dominio propio si
     * tiene uno: ahí la página vive en la raíz, así que la ruta no alcanza
     * para distinguirla de la home de Rezonar.
     */
    private static function filtroDePagina($slug, $dominio)
    {
        $condiciones = [[
            'filter' => [
                'fieldName' => 'pagePath',
                'stringFilter' => ['matchType' => 'EXACT', 'value' => '/' . $slug],
            ],
        ]];

        if (is_string($dominio) && $dominio !== '') {
            $condiciones[] = [
                'filter' => [
                    'fieldName' => 'hostName',
                    'stringFilter' => ['matchType' => 'EXACT', 'value' => $dominio],
                ],
            ];
        }

        return ['orGroup' => ['expressions' => $condiciones]];
    }

    // ------------------------------------------------------- leer la respuesta

    /** Los dos rangos del primer informe: el actual y el anterior. */
    private static function totales(array $informe)
    {
        $vacio = ['visitas' => 0, 'personas' => 0];
        $rangos = ['actual' => $vacio, 'previo' => $vacio];

        foreach (self::filas($informe) as $fila) {
            // Con dos rangos y sin dimensiones, Google agrega una dimensión
            // propia con el nombre del rango.
            $nombre = isset($fila['dimensionValues'][0]['value'])
                ? $fila['dimensionValues'][0]['value']
                : 'actual';

            if (isset($rangos[$nombre])) {
                $rangos[$nombre] = self::numeros($fila);
            }
        }

        return $rangos;
    }

    /**
     * Un punto por día, incluidos los días en que no entró nadie.
     *
     * Google no devuelve las filas en cero, y un hueco en la serie se lee como
     * si no se hubiera medido ese día.
     */
    private static function serieDiaria(array $informe, $desde, $hasta)
    {
        $medidos = [];

        foreach (self::filas($informe) as $fila) {
            $crudo = isset($fila['dimensionValues'][0]['value']) ? $fila['dimensionValues'][0]['value'] : '';

            if (preg_match('/^\d{8}$/', $crudo) !== 1) {
                continue;
            }

            $dia = substr($crudo, 0, 4) . '-' . substr($crudo, 4, 2) . '-' . substr($crudo, 6, 2);
            $medidos[$dia] = self::numeros($fila);
        }

        $serie = [];
        $dia = $desde;

        while (strcmp($dia, $hasta) <= 0) {
            $serie[] = array_merge(
                ['dia' => $dia],
                isset($medidos[$dia]) ? $medidos[$dia] : ['visitas' => 0, 'personas' => 0]
            );
            $dia = self::sumar($dia, 1);
        }

        return $serie;
    }

    private static function listado(array $informe)
    {
        $filas = [];

        foreach (self::filas($informe) as $fila) {
            $nombre = isset($fila['dimensionValues'][0]['value']) ? $fila['dimensionValues'][0]['value'] : '';

            // Google marca así lo que no pudo determinar. "(not set)" en
            // pantalla no le dice nada a nadie.
            if ($nombre === '' || $nombre === '(not set)' || $nombre === '(other)') {
                $nombre = 'Sin datos';
            }

            $filas[] = array_merge(['nombre' => $nombre], self::numeros($fila));
        }

        return $filas;
    }

    private static function filas(array $informe)
    {
        return isset($informe['rows']) && is_array($informe['rows']) ? $informe['rows'] : [];
    }

    private static function numeros(array $fila)
    {
        $valores = isset($fila['metricValues']) ? $fila['metricValues'] : [];

        return [
            'visitas'  => (int) (isset($valores[0]['value']) ? $valores[0]['value'] : 0),
            'personas' => (int) (isset($valores[1]['value']) ? $valores[1]['value'] : 0),
        ];
    }

    /** El motivo que da Google, que suele decir exactamente qué falta. */
    private static function motivo($cuerpo)
    {
        if (isset($cuerpo['error']['message'])) {
            return $cuerpo['error']['message'];
        }

        return 'Google Analytics no devolvió el informe';
    }

    // ------------------------------------------------------------- configuración

    private static function propiedad()
    {
        return defined('GA_PROPERTY_ID') ? trim((string) GA_PROPERTY_ID) : '';
    }

    /**
     * La cuenta de servicio, leída del archivo JSON que da Google.
     *
     * El archivo va fuera del directorio público y no entra al repositorio:
     * la clave privada que trae adentro da acceso de lectura a la propiedad.
     */
    private static function credenciales()
    {
        $ruta = defined('GA_SERVICE_ACCOUNT') ? trim((string) GA_SERVICE_ACCOUNT) : '';

        if ($ruta === '' || !is_readable($ruta)) {
            return null;
        }

        $cuenta = json_decode((string) file_get_contents($ruta), true);

        if (!is_array($cuenta) || !isset($cuenta['client_email'], $cuenta['private_key'])) {
            return null;
        }

        return $cuenta;
    }

    // ------------------------------------------------------------------ fechas

    const DIAS_MAXIMO = 365;

    public static function diasValidos($dias)
    {
        $dias = (int) $dias;

        if ($dias < 1) {
            return 30;
        }

        return min($dias, self::DIAS_MAXIMO);
    }

    private static function restar($dia, $cuantos)
    {
        $fecha = new DateTime($dia);
        $fecha->modify('-' . (int) $cuantos . ' day');

        return $fecha->format('Y-m-d');
    }

    private static function sumar($dia, $cuantos)
    {
        $fecha = new DateTime($dia);
        $fecha->modify('+' . (int) $cuantos . ' day');

        return $fecha->format('Y-m-d');
    }

    private static function base64url($datos)
    {
        return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
    }

    /** Para los tests: el token cacheado no puede sobrevivir a un caso. */
    public static function olvidarToken()
    {
        self::$token = null;
    }
}
