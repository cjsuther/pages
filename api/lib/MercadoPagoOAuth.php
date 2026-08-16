<?php

/**
 * Conexión de una cuenta de Mercado Pago por OAuth.
 *
 * Es lo que habilita el split: Mercado Pago sólo descuenta la comisión de la
 * plataforma si la preferencia se crea con un token que el vendedor autorizó a
 * través de la aplicación de marketplace. Con un access token pegado a mano la
 * comisión se ignora, sin ningún error visible.
 */
class MercadoPagoOAuth
{
    const AUTORIZACION = 'https://auth.mercadopago.com.ar/authorization';
    const TOKEN = 'https://api.mercadopago.com/oauth/token';

    /** Minutos antes del vencimiento a partir de los cuales conviene renovar. */
    const MARGEN_DE_RENOVACION = 60;

    /** @var HttpClient */
    private $http;

    public function __construct($http = null)
    {
        $this->http = $http === null ? new HttpClient() : $http;
    }

    /** true si la plataforma tiene la aplicación de marketplace configurada. */
    public static function configurado()
    {
        foreach (['MP_APP_ID', 'MP_APP_SECRET', 'MP_OAUTH_REDIRECT_URI'] as $constante) {
            if (!defined($constante) || strpos((string) constant($constante), 'PENDIENTE') === 0
                || strpos((string) constant($constante), 'TU_') === 0 || constant($constante) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A dónde mandar al dueño para que autorice.
     *
     * El `state` va firmado y con vencimiento: es lo que impide que alguien
     * arme el link con el page_id de otro y le conecte la cuenta a una página
     * que no le pertenece.
     */
    public static function urlDeAutorizacion($estadoFirmado)
    {
        return self::AUTORIZACION . '?' . http_build_query([
            'client_id'     => MP_APP_ID,
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $estadoFirmado,
            'redirect_uri'  => MP_OAUTH_REDIRECT_URI,
        ]);
    }

    /** Firma el estado que viaja hasta Mercado Pago y vuelve. */
    public static function firmarEstado($pageId, $userId)
    {
        return JWT::encode([
            'page_id' => (int) $pageId,
            'user_id' => (int) $userId,
            'uso'     => 'mp_oauth',
            'exp'     => time() + 900,
        ], JWT_SECRET);
    }

    /**
     * @return array|null ['page_id', 'user_id'] o null si el estado no sirve
     */
    public static function leerEstado($estadoFirmado)
    {
        $datos = JWT::decode((string) $estadoFirmado, JWT_SECRET);

        if (!is_array($datos) || !isset($datos['uso']) || $datos['uso'] !== 'mp_oauth') {
            return null;
        }

        if (!isset($datos['page_id'], $datos['user_id'])) {
            return null;
        }

        return ['page_id' => (int) $datos['page_id'], 'user_id' => (int) $datos['user_id']];
    }

    /**
     * Canjea el código que devuelve Mercado Pago por las credenciales.
     *
     * @return array{ok: bool, error: string|null, credenciales: array|null}
     */
    public function canjearCodigo($codigo)
    {
        return $this->pedirToken([
            'grant_type'   => 'authorization_code',
            'code'         => $codigo,
            'redirect_uri' => MP_OAUTH_REDIRECT_URI,
        ]);
    }

    /** Renueva un access token vencido sin molestar al dueño. */
    public function refrescar($refreshToken)
    {
        return $this->pedirToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function pedirToken(array $extra)
    {
        $r = $this->http->postJson(self::TOKEN, array_merge([
            'client_id'     => MP_APP_ID,
            'client_secret' => MP_APP_SECRET,
        ], $extra));

        $datos = json_decode($r['body'], true);

        if ($r['status'] !== 200 && $r['status'] !== 201) {
            $motivo = is_array($datos) && isset($datos['message'])
                ? substr((string) $datos['message'], 0, 200)
                : 'Mercado Pago respondió ' . $r['status'];

            return ['ok' => false, 'error' => $motivo, 'credenciales' => null];
        }

        if (!is_array($datos) || !isset($datos['access_token'])) {
            return ['ok' => false, 'error' => 'Respuesta ilegible de Mercado Pago', 'credenciales' => null];
        }

        return ['ok' => true, 'error' => null, 'credenciales' => [
            'access_token'  => $datos['access_token'],
            'refresh_token' => isset($datos['refresh_token']) ? $datos['refresh_token'] : null,
            'public_key'    => isset($datos['public_key']) ? $datos['public_key'] : '',
            'user_id'       => isset($datos['user_id']) ? (string) $datos['user_id'] : null,
            // live_mode dice si la cuenta cobra de verdad. Es más confiable que
            // mirar el prefijo del token, que en OAuth no siempre viene.
            'modo'          => !empty($datos['live_mode']) ? 'produccion' : 'prueba',
            'expira_en'     => isset($datos['expires_in'])
                ? date('Y-m-d H:i:s', time() + (int) $datos['expires_in'])
                : null,
        ]];
    }

    /** true si conviene renovar el token antes de usarlo. */
    public static function estaPorVencer($expiraEn)
    {
        if ($expiraEn === null || $expiraEn === '') {
            return false;
        }

        return strtotime($expiraEn) - time() < self::MARGEN_DE_RENOVACION * 60;
    }
}
