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
     *   @type string $moneda
     *   @type string $referencia  Código de nuestra orden, vuelve en el aviso
     *   @type string $urlRetorno  A dónde vuelve el comprador
     *   @type string $urlAviso    A dónde Mercado Pago avisa el pago
     *   @type array  $comprador   ['nombre', 'email', 'telefono']
     * }
     * @return array{ok: bool, error: string|null, id: string|null, url: string|null}
     */
    public function crearPreferencia(array $datos)
    {
        $cuerpo = [
            'items' => [[
                'title'       => $datos['titulo'],
                'quantity'    => (int) $datos['cantidad'],
                'unit_price'  => (float) $datos['precio'],
                'currency_id' => $datos['moneda'],
            ]],
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

        return [
            'ok'         => true,
            'estado'     => $datos['status'],
            'referencia' => isset($datos['external_reference']) ? $datos['external_reference'] : null,
            'monto'      => isset($datos['transaction_amount']) ? (float) $datos['transaction_amount'] : null,
        ];
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
