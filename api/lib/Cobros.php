<?php

/**
 * Credenciales de cobro de cada página.
 *
 * Se conectan por OAuth y no pegando el access token: es lo único que habilita
 * el split, porque Mercado Pago sólo descuenta la comisión de la plataforma si
 * la preferencia se crea con un token autorizado a través de la aplicación de
 * marketplace.
 *
 * Los secretos (access token y refresh token) se guardan cifrados y no vuelven
 * nunca al frontend: al editor sólo se le dice qué cuenta quedó conectada.
 */
class Cobros
{
    /**
     * Lo que se le puede mostrar al dueño en el editor, sin devolver secretos.
     */
    public static function estado($db, $pageId)
    {
        $fila = self::fila($db, $pageId);

        if ($fila === null) {
            return [
                'configurado'   => false,
                'modo'          => null,
                'cuenta'        => null,
                'conectado_por' => null,
                'admite_split'  => false,
                'verificado_en' => null,
            ];
        }

        return [
            'configurado'   => true,
            'modo'          => $fila['modo'],
            // El id de vendedor de Mercado Pago, para que reconozca qué cuenta
            // quedó conectada sin que le devolvamos ninguna credencial.
            'cuenta'        => $fila['mp_user_id'],
            'conectado_por' => $fila['conectado_por'],
            'admite_split'  => $fila['conectado_por'] === 'oauth',
            'verificado_en' => $fila['verificado_en'],
        ];
    }

    public static function estaConfigurado($db, $pageId)
    {
        return self::fila($db, $pageId) !== null;
    }

    /**
     * true si esta página puede repartir la comisión.
     *
     * Una credencial cargada a mano cobra igual, pero la comisión se ignora en
     * silencio, así que hay que poder distinguirlas.
     */
    public static function admiteSplit($db, $pageId)
    {
        $fila = self::fila($db, $pageId);

        return $fila !== null && $fila['conectado_por'] === 'oauth';
    }

    /**
     * Guarda lo que devolvió el OAuth de Mercado Pago.
     *
     * @return array{ok: bool, error: string|null}
     */
    public static function guardarDesdeOAuth($db, $pageId, array $credenciales)
    {
        if (!Cripto::disponible()) {
            return ['ok' => false, 'error' => 'El servidor no está configurado para guardar credenciales de cobro'];
        }

        if (empty($credenciales['access_token'])) {
            return ['ok' => false, 'error' => 'Mercado Pago no devolvió las credenciales'];
        }

        $stmt = $db->prepare('
            INSERT INTO page_payment_settings
                (page_id, mp_user_id, access_token_cifrado, refresh_token_cifrado,
                 token_ultimos4, public_key, modo, conectado_por, token_expira_en, verificado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, "oauth", ?, NOW())
            ON DUPLICATE KEY UPDATE
                mp_user_id = VALUES(mp_user_id),
                access_token_cifrado = VALUES(access_token_cifrado),
                refresh_token_cifrado = VALUES(refresh_token_cifrado),
                token_ultimos4 = VALUES(token_ultimos4),
                public_key = VALUES(public_key),
                modo = VALUES(modo),
                conectado_por = "oauth",
                token_expira_en = VALUES(token_expira_en),
                verificado_en = NOW()
        ');
        $stmt->execute([
            (int) $pageId,
            $credenciales['user_id'],
            Cripto::cifrar($credenciales['access_token']),
            empty($credenciales['refresh_token']) ? null : Cripto::cifrar($credenciales['refresh_token']),
            Cripto::ultimos4($credenciales['access_token']),
            (string) $credenciales['public_key'],
            $credenciales['modo'],
            $credenciales['expira_en'],
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Access token descifrado de una página, renovándolo si hace falta.
     *
     * El token de OAuth vence a los seis meses. Renovarlo acá y no con un
     * proceso aparte evita que un cobro falle porque el renovador no corrió.
     *
     * @return string|null null si no hay credenciales o no se pudieron usar
     */
    public static function tokenDe($db, $pageId, $http = null)
    {
        $fila = self::fila($db, $pageId);

        if ($fila === null) {
            return null;
        }

        $token = Cripto::descifrar($fila['access_token_cifrado']);

        if (!MercadoPagoOAuth::estaPorVencer($fila['token_expira_en'])) {
            return $token;
        }

        $refresh = empty($fila['refresh_token_cifrado'])
            ? null
            : Cripto::descifrar($fila['refresh_token_cifrado']);

        if ($refresh === null) {
            // Sin refresh token no se puede renovar; se devuelve el que hay y
            // que falle Mercado Pago con un error explícito, en vez de dejar
            // al comprador sin checkout por una decisión nuestra.
            return $token;
        }

        $renovado = (new MercadoPagoOAuth($http))->refrescar($refresh);

        if (!$renovado['ok']) {
            return $token;
        }

        self::guardarDesdeOAuth($db, $pageId, $renovado['credenciales']);

        return $renovado['credenciales']['access_token'];
    }

    /** Access token de la página dueña de un evento. */
    public static function tokenDelEvento($db, $linkId, $http = null)
    {
        $pageId = self::pageIdDelEvento($db, $linkId);

        return $pageId === null ? null : self::tokenDe($db, $pageId, $http);
    }

    /** true si el evento pertenece a una página que puede repartir comisión. */
    public static function eventoAdmiteSplit($db, $linkId)
    {
        $pageId = self::pageIdDelEvento($db, $linkId);

        return $pageId !== null && self::admiteSplit($db, $pageId);
    }

    public static function borrar($db, $pageId)
    {
        $stmt = $db->prepare('DELETE FROM page_payment_settings WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);
    }

    // --------------------------------------------------------------- internos

    private static function fila($db, $pageId)
    {
        $stmt = $db->prepare('SELECT * FROM page_payment_settings WHERE page_id = ?');
        $stmt->execute([(int) $pageId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    private static function pageIdDelEvento($db, $linkId)
    {
        $stmt = $db->prepare('
            SELECT lg.page_id
            FROM links l
            INNER JOIN link_groups lg ON lg.id = l.group_id
            WHERE l.id = ?
        ');
        $stmt->execute([(int) $linkId]);
        $pageId = $stmt->fetchColumn();

        return $pageId === false ? null : (int) $pageId;
    }
}
