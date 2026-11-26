<?php

/**
 * Cliente de la API de Mercado Pago (Checkout Pro).
 *
 * El comprador se va a la pantalla de Mercado Pago y vuelve: los datos de la
 * tarjeta nunca pasan por este servidor. Lo único que hacemos es crear la
 * preferencia de pago y después consultar cómo terminó.
 *
 * Recibe el HttpClient por parámetro, igual que el login de Google o Apple:
 * es la frontera con la red y los tests la sustituyen por un doble.
 */
class MercadoPago
{
    const BASE = 'https://api.mercadopago.com';

    /** @var string Access token del dueño de la página, ya descifrado */
    private $token;

    /** @var HttpClient */
    private $http;

    public function __construct($token, $http = null)
    {
        $this->token = (string) $token;
        $this->http = $http === null ? new HttpClient() : $http;
    }

    /**
     * Los tokens de prueba de Mercado Pago empiezan con TEST-.
     *
     * Cobrar de verdad creyendo que se está probando (o mostrar un checkout de
     * prueba a compradores reales) es un error caro y silencioso, así que el
     * modo se deduce del propio token y se le muestra al dueño.
     */
    public static function modoDelToken($token)
    {
        return strpos((string) $token, 'TEST-') === 0 ? 'prueba' : 'produccion';
    }

    /** Forma mínima de un access token, para no llamar a la API con cualquier cosa. */
    public static function pareceToken($token)
    {
        return (bool) preg_match('/^(APP_USR|TEST)-[A-Za-z0-9._-]{20,}$/', (string) $token);
    }

    /** Forma mínima de una public key. */
    public static function pareceClavePublica($clave)
    {
        return (bool) preg_match('/^(APP_USR|TEST)-[A-Za-z0-9._-]{20,}$/', (string) $clave);
    }

    /**
     * Comprueba que la credencial sirva, pidiendo los datos de la cuenta.
     *
     * @return array{ok: bool, error: string|null, cuenta: string|null}
     */
    public function verificar()
    {
        $r = $this->http->get(self::BASE . '/users/me', $this->cabeceras());

        if ($r['status'] === 401 || $r['status'] === 403) {
            return ['ok' => false, 'error' => 'Mercado Pago rechazó la credencial', 'cuenta' => null];
        }

        if ($r['status'] !== 200) {
            return ['ok' => false, 'error' => 'Mercado Pago respondió ' . $r['status'], 'cuenta' => null];
        }

        $datos = json_decode($r['body'], true);

        if (!is_array($datos)) {
            return ['ok' => false, 'error' => 'Respuesta ilegible de Mercado Pago', 'cuenta' => null];
        }

        $cuenta = isset($datos['nickname']) ? $datos['nickname']
            : (isset($datos['email']) ? $datos['email'] : null);

        return ['ok' => true, 'error' => null, 'cuenta' => $cuenta];
    }

    /**
     * Crea la preferencia de pago y devuelve a dónde mandar al comprador.
     *
     * @param array $datos {
     *   @type string $titulo      Lo que ve el comprador en el checkout
     *   @type int    $cantidad
     *   @type float  $precio      Unitario
     *   @type array  $items       Opcional: [['titulo', 'cantidad', 'precio']], uno por
     *                             tipo de entrada. Si viene, reemplaza a título,
     *                             cantidad y precio.
     *   @type string $moneda
     *   @type string $referencia  Código de nuestra orden, vuelve en el aviso
     *   @type string $urlRetorno  A dónde vuelve el comprador
     *   @type string $urlAviso    A dónde Mercado Pago avisa el pago
     *   @type array  $comprador   ['nombre', 'email', 'telefono']
     *   @type float  $comision    Monto para la plataforma (0 si no hay split)
     * }
     * @return array{ok: bool, error: string|null, id: string|null, url: string|null}
     */
    public function crearPreferencia(array $datos)
    {
        $items = empty($datos['items'])
            ? [['titulo' => $datos['titulo'], 'cantidad' => $datos['cantidad'], 'precio' => $datos['precio']]]
            : $datos['items'];

        $cuerpo = [
            'items' => array_map(function ($item) use ($datos) {
                return [
                    'title'       => $item['titulo'],
                    'quantity'    => (int) $item['cantidad'],
                    'unit_price'  => (float) $item['precio'],
                    'currency_id' => $datos['moneda'],
                ];
            }, $items),
            'payer' => [
                'name'  => $datos['comprador']['nombre'],
                'email' => $datos['comprador']['email'],
            ],
            // Vuelve en el aviso de pago: es lo que nos permite saber a qué
            // orden corresponde sin confiar en nada que venga del navegador.
            'external_reference' => $datos['referencia'],
            'notification_url'   => $datos['urlAviso'],
            'back_urls' => [
                'success' => $datos['urlRetorno'],
                'pending' => $datos['urlRetorno'],
                'failure' => $datos['urlRetorno'],
            ],
            'auto_return' => 'approved',
            // Sin cuotas ni medios en efectivo: el pago tiene que resolverse
            // dentro de la ventana de la reserva, y un cupón de pago fácil se
            // acredita días después, cuando el cupo ya se liberó.
            'payment_methods' => [
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
            ],
        ];

        // Comisión de la plataforma. Mercado Pago la descuenta del total y la
        // deposita en la cuenta de la aplicación: el comprador paga una sola
        // vez y el reparto lo hace Mercado Pago.
        //
        // Sólo tiene efecto si el token es de una cuenta conectada por OAuth
        // desde esta aplicación. Con un token pegado a mano se ignora sin dar
        // error, así que quien llama tiene que haberlo verificado antes.
        $comision = isset($datos['comision']) ? round((float) $datos['comision'], 2) : 0.0;

        if ($comision > 0) {
            $cuerpo['marketplace_fee'] = $comision;
        }

        $r = $this->http->postJson(self::BASE . '/checkout/preferences', $cuerpo, $this->cabeceras());

        if ($r['status'] !== 200 && $r['status'] !== 201) {
            return ['ok' => false, 'error' => $this->motivoDelError($r), 'id' => null, 'url' => null];
        }

        $datosRespuesta = json_decode($r['body'], true);

        if (!is_array($datosRespuesta) || !isset($datosRespuesta['id'])) {
            return ['ok' => false, 'error' => 'Respuesta ilegible de Mercado Pago', 'id' => null, 'url' => null];
        }

        // En modo prueba el checkout real está en sandbox_init_point.
        $url = self::modoDelToken($this->token) === 'prueba' && isset($datosRespuesta['sandbox_init_point'])
            ? $datosRespuesta['sandbox_init_point']
            : (isset($datosRespuesta['init_point']) ? $datosRespuesta['init_point'] : null);

        if (!$url) {
            return ['ok' => false, 'error' => 'Mercado Pago no devolvió el link de pago', 'id' => null, 'url' => null];
        }

        return ['ok' => true, 'error' => null, 'id' => (string) $datosRespuesta['id'], 'url' => $url];
    }

    /**
     * Consulta un pago. El aviso de Mercado Pago sólo trae el id: el estado
     * hay que preguntárselo a la API, porque el aviso puede venir de cualquiera.
     *
     * @return array{ok: bool, estado: string|null, referencia: string|null, monto: float|null}
     */
    public function consultarPago($pagoId)
    {
        $r = $this->http->get(self::BASE . '/v1/payments/' . urlencode($pagoId), $this->cabeceras());

        if ($r['status'] !== 200) {
            return ['ok' => false, 'estado' => null, 'referencia' => null, 'monto' => null];
        }

        $datos = json_decode($r['body'], true);

        if (!is_array($datos) || !isset($datos['status'])) {
            return ['ok' => false, 'estado' => null, 'referencia' => null, 'monto' => null];
        }

        return self::leerPago($datos);
    }

    /**
     * Busca el pago de una orden sin conocer su id, por la referencia que le
     * pusimos al crear la preferencia.
     *
     * Hace falta para recuperar una compra cuyo aviso nunca llegó: ahí lo único
     * que tenemos es el código de la orden. Si hay más de un intento —alguien
     * que reintenta con otra tarjeta— gana el aprobado; entre varios aprobados,
     * el más nuevo. Un rechazado no puede tapar a un aprobado.
     *
     * @return array Igual que consultarPago, más 'id'. Sin resultados, ok=false.
     */
    public function buscarPagoPorReferencia($referencia)
    {
        $vacio = ['ok' => false, 'id' => null, 'estado' => null, 'referencia' => null, 'monto' => null];

        $referencia = trim((string) $referencia);

        if ($referencia === '') {
            return $vacio;
        }

        $r = $this->http->get(
            self::BASE . '/v1/payments/search?sort=date_created&criteria=desc&external_reference='
                . urlencode($referencia),
            $this->cabeceras()
        );

        if ($r['status'] !== 200) {
            return $vacio;
        }

        $datos = json_decode($r['body'], true);

        if (!is_array($datos) || !isset($datos['results']) || !is_array($datos['results'])) {
            return $vacio;
        }

        $elegido = null;

        foreach ($datos['results'] as $pago) {
            if (!is_array($pago) || !isset($pago['status'])) {
                continue;
            }

            if ($pago['status'] === 'approved') {
                $elegido = $pago;
                break;
            }

            if ($elegido === null) {
                $elegido = $pago;
            }
        }

        if ($elegido === null) {
            return $vacio;
        }

        return ['id' => isset($elegido['id']) ? (string) $elegido['id'] : null] + self::leerPago($elegido);
    }

    /** Los campos de un pago que nos interesan, vengan de donde vengan. */
    private static function leerPago(array $datos)
    {
        return [
            'ok'         => true,
            'estado'     => $datos['status'],
            'referencia' => isset($datos['external_reference']) ? $datos['external_reference'] : null,
            'monto'      => isset($datos['transaction_amount']) ? (float) $datos['transaction_amount'] : null,
            // Lo que Mercado Pago dice que entra a la cuenta, ya descontadas
            // su comisión y la nuestra. Es más confiable que calcularlo: el
            // costo depende del plazo de acreditación y de la provincia, que
            // son cosas de la cuenta del vendedor y acá no se conocen.
            'neto'       => isset($datos['transaction_details']['net_received_amount'])
                ? (float) $datos['transaction_details']['net_received_amount']
                : null,
            'comisiones' => self::comisiones($datos),
            // Aparte de la suma: es la única forma de saber si el split se
            // aplicó o si Mercado Pago ignoró el marketplace_fee.
            'comision_plataforma' => self::comisionDePlataforma($datos),
            // Cuándo queda disponible la plata. Con tarjeta de crédito puede
            // ser un mes después; con dinero en cuenta, el mismo día.
            'acreditacion' => isset($datos['money_release_date']) ? $datos['money_release_date'] : null,
            // Cuánto del pago ya se devolvió, según Mercado Pago (por la
            // plataforma o por fuera). Sirve de tope: nunca se pide devolver
            // más de lo que queda.
            'devuelto' => isset($datos['transaction_amount_refunded']) && is_numeric($datos['transaction_amount_refunded'])
                ? (float) $datos['transaction_amount_refunded']
                : 0.0,
            'estado_detalle' => isset($datos['status_detail']) ? (string) $datos['status_detail'] : null,
        ];
    }

    // ------------------------------------------------------------ reembolsos

    /**
     * Pide un reembolso del pago.
     *
     * Con $monto es parcial ({"amount": X}); con null es total y el cuerpo va
     * vacío, como objeto: {} y no []. La plataforma siempre pide un monto
     * (lo que le llegó al organizador): un reembolso total devolvería también
     * lo que se quedaron Mercado Pago y Rezonar.
     *
     * X-Idempotency-Key es obligatorio para Mercado Pago y es lo que hace que
     * repetir el mismo pedido (un reintento de red) no genere dos reembolsos.
     * La clave la arma quien llama, una por intento, y la guarda antes.
     *
     * El resultado distingue tres cosas, porque se tratan distinto:
     *   aprobada  Mercado Pago lo hizo.
     *   rechazada Mercado Pago contestó que no (4xx o estado rechazado): la
     *             plata no se movió.
     *   incierta  No sabemos: se cortó la conexión, venció el tiempo, contestó
     *             5xx o algo ilegible, o el reembolso quedó "en proceso".
     *             Nunca se reintenta con otra clave sin preguntar antes.
     *
     * @return array{resultado: string, status_http: int, id: string|null, monto: float|null,
     *               estado: string|null, error_codigo: string|null, error_mensaje: string|null, respuesta: array|null}
     */
    public function devolver($pagoId, $monto, $claveIdempotencia)
    {
        $cuerpo = $monto === null ? new stdClass() : ['amount' => round((float) $monto, 2)];

        $r = $this->http->postJson(
            self::BASE . '/v1/payments/' . urlencode((string) $pagoId) . '/refunds',
            $cuerpo,
            array_merge($this->cabeceras(), ['X-Idempotency-Key: ' . $claveIdempotencia])
        );

        $status = (int) $r['status'];
        $datos = json_decode((string) $r['body'], true);
        $datos = is_array($datos) ? $datos : null;

        $resultado = [
            'resultado'     => 'incierta',
            'status_http'   => $status,
            'id'            => null,
            'monto'         => null,
            'estado'        => null,
            'error_codigo'  => null,
            'error_mensaje' => null,
            'respuesta'     => $datos === null ? null : self::resumenDeDevolucion($datos),
        ];

        if ($status === 200 || $status === 201) {
            if ($datos === null || !isset($datos['id'])) {
                return $resultado;
            }

            $resultado['id'] = (string) $datos['id'];
            $resultado['monto'] = isset($datos['amount']) && is_numeric($datos['amount']) ? (float) $datos['amount'] : null;
            $resultado['estado'] = isset($datos['status']) ? (string) $datos['status'] : null;

            // Sin estado se toma como hecho: el alta respondió con un id.
            if ($resultado['estado'] === null || $resultado['estado'] === 'approved') {
                $resultado['resultado'] = 'aprobada';
            } elseif (in_array($resultado['estado'], ['rejected', 'cancelled'], true)) {
                $resultado['resultado'] = 'rechazada';
                $resultado['error_codigo'] = $resultado['estado'];
                $resultado['error_mensaje'] = 'refund ' . $resultado['estado'];
            }

            return $resultado;
        }

        // 4xx: Mercado Pago lo leyó y dijo que no. Lo demás (0, 5xx) no dice
        // nada sobre si se hizo.
        if ($status >= 400 && $status < 500) {
            list($codigo, $mensaje) = self::errorDe($datos, $status);
            $resultado['resultado'] = 'rechazada';
            $resultado['error_codigo'] = $codigo;
            $resultado['error_mensaje'] = $mensaje;
        }

        return $resultado;
    }

    /**
     * Los reembolsos que tiene un pago, según Mercado Pago.
     *
     * Es lo que permite resolver un reembolso "incierto" sin volver a pedirlo.
     *
     * @return array{ok: bool, devoluciones: array<array{id: string, monto: float|null, estado: string|null}>}
     */
    public function devoluciones($pagoId)
    {
        $r = $this->http->get(self::BASE . '/v1/payments/' . urlencode((string) $pagoId) . '/refunds', $this->cabeceras());

        if ($r['status'] !== 200) {
            return ['ok' => false, 'devoluciones' => []];
        }

        $datos = json_decode($r['body'], true);

        if (!is_array($datos)) {
            return ['ok' => false, 'devoluciones' => []];
        }

        // La API devuelve una lista; se acepta también {results: [...]}.
        $lista = isset($datos['results']) && is_array($datos['results']) ? $datos['results'] : $datos;
        $devoluciones = [];

        foreach ($lista as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            $devoluciones[] = [
                'id'     => (string) $item['id'],
                'monto'  => isset($item['amount']) && is_numeric($item['amount']) ? (float) $item['amount'] : null,
                'estado' => isset($item['status']) ? (string) $item['status'] : null,
            ];
        }

        return ['ok' => true, 'devoluciones' => $devoluciones];
    }

    /**
     * El pago como lo devuelve Mercado Pago, reducido a lo que explica cómo
     * se repartió la plata. Sin datos de quien pagó.
     *
     * Se guarda después de cada reembolso: es la forma de comprobar con datos
     * de Mercado Pago, y no con una suposición, si el reembolso le descontó
     * algo a la comisión de la plataforma (application_fee).
     *
     * @return array|null null si no se pudo leer
     */
    public function repartoDelPago($pagoId)
    {
        $r = $this->http->get(self::BASE . '/v1/payments/' . urlencode((string) $pagoId), $this->cabeceras());

        if ($r['status'] !== 200) {
            return null;
        }

        $datos = json_decode($r['body'], true);

        if (!is_array($datos)) {
            return null;
        }

        $tarifas = [];

        foreach (isset($datos['fee_details']) && is_array($datos['fee_details']) ? $datos['fee_details'] : [] as $detalle) {
            if (!is_array($detalle)) {
                continue;
            }

            $tarifas[] = [
                'type'     => isset($detalle['type']) ? (string) $detalle['type'] : null,
                'amount'   => isset($detalle['amount']) && is_numeric($detalle['amount']) ? (float) $detalle['amount'] : null,
                'fee_payer' => isset($detalle['fee_payer']) ? (string) $detalle['fee_payer'] : null,
            ];
        }

        $reembolsos = [];

        foreach (isset($datos['refunds']) && is_array($datos['refunds']) ? $datos['refunds'] : [] as $reembolso) {
            if (is_array($reembolso)) {
                $reembolsos[] = self::resumenDeDevolucion($reembolso);
            }
        }

        return [
            'status'                      => isset($datos['status']) ? $datos['status'] : null,
            'status_detail'               => isset($datos['status_detail']) ? $datos['status_detail'] : null,
            'transaction_amount'          => isset($datos['transaction_amount']) ? $datos['transaction_amount'] : null,
            'transaction_amount_refunded' => isset($datos['transaction_amount_refunded']) ? $datos['transaction_amount_refunded'] : null,
            'net_received_amount'         => isset($datos['transaction_details']['net_received_amount'])
                ? $datos['transaction_details']['net_received_amount']
                : null,
            'marketplace_fee'             => isset($datos['marketplace_fee']) ? $datos['marketplace_fee'] : null,
            'application_fee'             => self::comisionDePlataforma($datos),
            'fee_details'                 => $tarifas,
            'refunds'                     => $reembolsos,
        ];
    }

    /** Los campos de un reembolso que sirven para auditar, sin nada personal. */
    private static function resumenDeDevolucion(array $datos)
    {
        $resumen = [];

        foreach (['id', 'payment_id', 'amount', 'status', 'date_created', 'source', 'refund_mode',
                  'adjustment_amount', 'reason', 'partition_details', 'unique_sequence_number'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $resumen[$campo] = $campo === 'source' && is_array($datos[$campo])
                    // source trae quién la pidió; se deja sólo el tipo.
                    ? (isset($datos[$campo]['type']) ? ['type' => $datos[$campo]['type']] : null)
                    : $datos[$campo];
            }
        }

        // Respuestas de error: código y mensaje, para el registro.
        foreach (['message', 'error', 'status', 'cause'] as $campo) {
            if (array_key_exists($campo, $datos) && !array_key_exists($campo, $resumen)) {
                $resumen[$campo] = $datos[$campo];
            }
        }

        return $resumen;
    }

    /**
     * Código y mensaje de un error de Mercado Pago, tal cual.
     *
     * El código útil viene en cause[0].code (3024, 15016, ...); si no hay,
     * el campo error ("bad_request") o el status HTTP.
     *
     * @return array{0: string, 1: string}
     */
    public static function errorDe($datos, $status)
    {
        $codigo = null;
        $mensaje = null;

        if (is_array($datos)) {
            if (isset($datos['cause']) && is_array($datos['cause'])) {
                $causa = isset($datos['cause'][0]) && is_array($datos['cause'][0]) ? $datos['cause'][0] : $datos['cause'];

                if (isset($causa['code']) && $causa['code'] !== '') {
                    $codigo = (string) $causa['code'];
                }

                if (isset($causa['description']) && is_string($causa['description']) && $causa['description'] !== '') {
                    $mensaje = $causa['description'];
                }
            }

            if ($codigo === null && isset($datos['error']) && is_scalar($datos['error']) && $datos['error'] !== '') {
                $codigo = (string) $datos['error'];
            }

            if ($mensaje === null && isset($datos['message']) && is_string($datos['message'])) {
                $mensaje = $datos['message'];
            }
        }

        return [
            mb_substr($codigo === null ? (string) $status : $codigo, 0, 50),
            mb_substr($mensaje === null ? 'Mercado Pago respondió ' . $status : $mensaje, 0, 255),
        ];
    }

    /**
     * Lo que Mercado Pago efectivamente cobró como comisión de plataforma.
     *
     * Es la línea `application_fee` del desglose. Mandar marketplace_fee es
     * pedir; esto es lo que pasó. Cuando la cuenta no está conectada por OAuth
     * desde nuestra aplicación, Mercado Pago ignora el pedido sin avisar y esta
     * línea no aparece: ahí devuelve 0.0, que es una respuesta, no una duda.
     *
     * null sólo si el pago no trajo desglose, que es cuando no sabemos nada.
     */
    public static function comisionDePlataforma(array $pago)
    {
        if (!isset($pago['fee_details']) || !is_array($pago['fee_details'])) {
            return null;
        }

        $total = 0.0;

        foreach ($pago['fee_details'] as $detalle) {
            $tipo = isset($detalle['type']) ? $detalle['type'] : null;

            if ($tipo === 'application_fee' && isset($detalle['amount']) && is_numeric($detalle['amount'])) {
                $total += (float) $detalle['amount'];
            }
        }

        return round($total, 2);
    }

    /**
     * Todo lo que se descontó del total.
     *
     * Mercado Pago lo devuelve desglosado por tipo —la suya, la de la
     * plataforma, la de financiación—; para mostrar alcanza con el total, y
     * sumar evita tener que seguirle el nombre a cada concepto.
     */
    public static function comisiones(array $pago)
    {
        if (!isset($pago['fee_details']) || !is_array($pago['fee_details'])) {
            return null;
        }

        $total = 0.0;

        foreach ($pago['fee_details'] as $detalle) {
            if (isset($detalle['amount']) && is_numeric($detalle['amount'])) {
                $total += (float) $detalle['amount'];
            }
        }

        return round($total, 2);
    }

    private function cabeceras()
    {
        return [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ];
    }

    private function motivoDelError(array $respuesta)
    {
        $datos = json_decode($respuesta['body'], true);

        if (is_array($datos) && isset($datos['message'])) {
            return substr((string) $datos['message'], 0, 200);
        }

        return 'Mercado Pago respondió ' . $respuesta['status'];
    }
}
