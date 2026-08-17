<?php

/**
 * Autorización OAuth 2.1 para el server MCP.
 *
 * El circuito completo: un cliente MCP se registra solo, manda a la persona a
 * autorizar en el navegador, y con el código que vuelve canjea un token. Nadie
 * copia una credencial a mano, que es lo que hace que esto sirva para
 * cualquier dueño de páginas y no sólo para quien administra el servidor.
 *
 * Dos reglas ordenan todo lo demás:
 *
 * PKCE es obligatorio. Los clientes MCP son aplicaciones que corren en la
 * máquina de la persona, así que no pueden guardar un secreto: cualquiera que
 * abra el programa lo lee. El desafío de PKCE reemplaza al secreto, probando
 * al canjear que quien canjea es el mismo que pidió.
 *
 * De códigos y tokens se guarda sólo el hash, igual que con las contraseñas.
 * Quien lea la base no se lleva credenciales utilizables.
 */
class OAuth
{
    /** Segundos que vive un código de autorización. Se canjea en el acto. */
    const VIDA_CODIGO = 300;

    /** Segundos que vive un token de acceso. */
    const VIDA_TOKEN = 3600;

    /** Alcance único: administrar los eventos de las páginas propias. */
    const SCOPE = 'eventos';

    // ------------------------------------------------------------- clientes

    /**
     * Registro dinámico de un cliente (RFC 7591).
     *
     * Sin esto habría que dar de alta a mano cada programa que quiera
     * conectarse, y un cliente MCP recién instalado no tiene cómo pedirlo.
     *
     * @return array{ok: bool, cliente?: array, error?: string}
     */
    public static function registrarCliente($db, $nombre, array $redirectUris)
    {
        $uris = array_values(array_filter(array_map('trim', $redirectUris), [self::class, 'redirectAceptable']));

        if (empty($uris)) {
            return ['ok' => false, 'error' => 'Hace falta al menos una redirect_uri válida'];
        }

        $clientId = bin2hex(random_bytes(16));

        $stmt = $db->prepare('INSERT INTO oauth_clients (client_id, nombre, redirect_uris) VALUES (?, ?, ?)');
        $stmt->execute([$clientId, mb_substr(trim($nombre) === '' ? 'Cliente MCP' : $nombre, 0, 120), json_encode($uris)]);

        return ['ok' => true, 'cliente' => ['client_id' => $clientId, 'redirect_uris' => $uris]];
    }

    /**
     * Una redirección aceptable.
     *
     * Se permite http sólo en localhost: es donde corren los clientes de
     * escritorio, que no tienen certificado. Cualquier otro destino sin cifrar
     * expondría el código de autorización en la red.
     */
    public static function redirectAceptable($uri)
    {
        $partes = parse_url((string) $uri);

        if (!$partes || !isset($partes['scheme'])) {
            return false;
        }

        // Los clientes de escritorio usan esquemas propios (claude://…) para
        // que el sistema operativo les devuelva el control.
        if (!in_array($partes['scheme'], ['http', 'https'], true)) {
            return !isset($partes['host']) || $partes['host'] !== '';
        }

        if ($partes['scheme'] === 'https') {
            return true;
        }

        $host = isset($partes['host']) ? $partes['host'] : '';

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function cliente($db, $clientId)
    {
        $stmt = $db->prepare('SELECT * FROM oauth_clients WHERE client_id = ?');
        $stmt->execute([(string) $clientId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false) {
            return null;
        }

        $fila['redirect_uris'] = json_decode($fila['redirect_uris'], true) ?: [];

        return $fila;
    }

    // -------------------------------------------------------------- códigos

    /**
     * Revisa un pedido de autorización antes de mostrarle nada a la persona.
     *
     * Se valida acá y no al canjear porque un redirect_uri que no coincide es
     * el caso peligroso: si se aceptara, el código de una persona terminaría
     * en el servidor de otro.
     *
     * @return array{ok: bool, cliente?: array, error?: string}
     */
    public static function revisarPedido($db, array $params)
    {
        $cliente = self::cliente($db, isset($params['client_id']) ? $params['client_id'] : '');

        if ($cliente === null) {
            return ['ok' => false, 'error' => 'No conocemos esa aplicación'];
        }

        $redirect = isset($params['redirect_uri']) ? $params['redirect_uri'] : '';

        if (!in_array($redirect, $cliente['redirect_uris'], true)) {
            return ['ok' => false, 'error' => 'La dirección de retorno no es una de las registradas'];
        }

        if (isset($params['response_type']) && $params['response_type'] !== 'code') {
            return ['ok' => false, 'error' => 'Sólo se admite response_type=code'];
        }

        // OAuth 2.1: sin PKCE no se emite nada. S256 y no "plain", que no
        // protege de nada si alguien puede leer el pedido.
        if (empty($params['code_challenge'])) {
            return ['ok' => false, 'error' => 'Falta el desafío de PKCE'];
        }

        if (isset($params['code_challenge_method']) && $params['code_challenge_method'] !== 'S256') {
            return ['ok' => false, 'error' => 'El desafío de PKCE tiene que ser S256'];
        }

        return ['ok' => true, 'cliente' => $cliente];
    }

    /** Emite el código, ya con la persona habiendo dicho que sí. */
    public static function emitirCodigo($db, $userId, array $params)
    {
        $codigo = bin2hex(random_bytes(32));

        $stmt = $db->prepare('
            INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, resource, expira_en)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            self::hash($codigo),
            $params['client_id'],
            (int) $userId,
            $params['redirect_uri'],
            $params['code_challenge'],
            isset($params['resource']) ? $params['resource'] : null,
            date('Y-m-d H:i:s', time() + self::VIDA_CODIGO),
        ]);

        return $codigo;
    }

    // --------------------------------------------------------------- tokens

    /**
     * Canjea el código por un token.
     *
     * @return array{ok: bool, token?: array, error?: string}
     */
    public static function canjear($db, array $params)
    {
        $stmt = $db->prepare('SELECT * FROM oauth_codes WHERE code_hash = ?');
        $stmt->execute([self::hash(isset($params['code']) ? $params['code'] : '')]);
        $codigo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($codigo === false) {
            return ['ok' => false, 'error' => 'invalid_grant'];
        }

        // Un código que ya se usó y vuelve a aparecer significa que alguien lo
        // interceptó. Se anulan los tokens que salieron de él: es preferible
        // desconectar a la persona que dejar viva una sesión robada.
        if ($codigo['usado_en'] !== null) {
            self::revocarDelCliente($db, $codigo['client_id'], (int) $codigo['user_id']);

            return ['ok' => false, 'error' => 'invalid_grant'];
        }

        self::marcarUsado($db, (int) $codigo['id']);

        if (strtotime($codigo['expira_en']) < time()) {
            return ['ok' => false, 'error' => 'invalid_grant'];
        }

        if (!isset($params['client_id']) || $params['client_id'] !== $codigo['client_id']) {
            return ['ok' => false, 'error' => 'invalid_client'];
        }

        if (!isset($params['redirect_uri']) || $params['redirect_uri'] !== $codigo['redirect_uri']) {
            return ['ok' => false, 'error' => 'invalid_grant'];
        }

        if (!self::pkceCoincide(isset($params['code_verifier']) ? $params['code_verifier'] : '', $codigo['code_challenge'])) {
            return ['ok' => false, 'error' => 'invalid_grant'];
        }

        return ['ok' => true, 'token' => self::emitirToken($db, $codigo['client_id'], (int) $codigo['user_id'], $codigo['resource'])];
    }

    /**
     * Renueva un token vencido sin volver a molestar a la persona.
     *
     * El refresco rota: el viejo deja de valer apenas se usa. Si aparece dos
     * veces, es que alguien se lo llevó.
     */
    public static function refrescar($db, array $params)
    {
        $stmt = $db->prepare('SELECT * FROM oauth_tokens WHERE refresh_hash = ?');
        $stmt->execute([self::hash(isset($params['refresh_token']) ? $params['refresh_token'] : '')]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($token === false || $token['revocado_en'] !== null) {
            return ['ok' => false, 'error' => 'invalid_grant'];
        }

        if (!isset($params['client_id']) || $params['client_id'] !== $token['client_id']) {
            return ['ok' => false, 'error' => 'invalid_client'];
        }

        self::revocarPorId($db, (int) $token['id']);

        return ['ok' => true, 'token' => self::emitirToken($db, $token['client_id'], (int) $token['user_id'], $token['resource'])];
    }

    private static function emitirToken($db, $clientId, $userId, $resource)
    {
        $acceso = bin2hex(random_bytes(32));
        $refresco = bin2hex(random_bytes(32));

        $stmt = $db->prepare('
            INSERT INTO oauth_tokens (token_hash, refresh_hash, client_id, user_id, resource, expira_en)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            self::hash($acceso),
            self::hash($refresco),
            $clientId,
            $userId,
            $resource,
            date('Y-m-d H:i:s', time() + self::VIDA_TOKEN),
        ]);

        return [
            'access_token' => $acceso,
            'token_type' => 'Bearer',
            'expires_in' => self::VIDA_TOKEN,
            'refresh_token' => $refresco,
            'scope' => self::SCOPE,
        ];
    }

    /**
     * Quién es el dueño de un token de acceso.
     *
     * Misma forma que el payload del JWT y que la sesión por clave de API: lo
     * que ya sabe leer una sesión no tiene que aprender un formato nuevo.
     */
    public static function usuario($db, $token)
    {
        $stmt = $db->prepare('
            SELECT t.id, t.user_id, t.expira_en, u.email, u.name
            FROM oauth_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = ? AND t.revocado_en IS NULL
        ');
        $stmt->execute([self::hash($token)]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fila === false || strtotime($fila['expira_en']) < time()) {
            return null;
        }

        $stmt = $db->prepare('UPDATE oauth_tokens SET ultimo_uso_en = NOW() WHERE id = ?');
        $stmt->execute([(int) $fila['id']]);

        return [
            'user_id' => (int) $fila['user_id'],
            'email' => $fila['email'],
            'name' => $fila['name'],
            'por_clave_api' => true,
        ];
    }

    /** Las aplicaciones conectadas de una persona, para poder desconectarlas. */
    public static function conexiones($db, $userId)
    {
        $stmt = $db->prepare('
            SELECT MIN(t.id) AS id, c.nombre, t.client_id,
                   MAX(t.ultimo_uso_en) AS ultimo_uso_en, MIN(t.created_at) AS created_at
            FROM oauth_tokens t
            LEFT JOIN oauth_clients c ON c.client_id = t.client_id
            WHERE t.user_id = ? AND t.revocado_en IS NULL
            GROUP BY t.client_id, c.nombre
            ORDER BY MAX(t.ultimo_uso_en) DESC
        ');
        $stmt->execute([(int) $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Desconecta una aplicación: se van todos sus tokens de esta persona. */
    public static function desconectar($db, $userId, $clientId)
    {
        $stmt = $db->prepare('
            UPDATE oauth_tokens SET revocado_en = NOW()
            WHERE user_id = ? AND client_id = ? AND revocado_en IS NULL
        ');
        $stmt->execute([(int) $userId, (string) $clientId]);

        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------- internos

    /** PKCE S256: el verificador hasheado tiene que dar el desafío guardado. */
    public static function pkceCoincide($verificador, $desafio)
    {
        if (!is_string($verificador) || $verificador === '') {
            return false;
        }

        $calculado = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');

        return hash_equals((string) $desafio, $calculado);
    }

    public static function hash($valor)
    {
        return hash('sha256', (string) $valor);
    }

    private static function marcarUsado($db, $id)
    {
        $stmt = $db->prepare('UPDATE oauth_codes SET usado_en = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    private static function revocarPorId($db, $id)
    {
        $stmt = $db->prepare('UPDATE oauth_tokens SET revocado_en = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    private static function revocarDelCliente($db, $clientId, $userId)
    {
        $stmt = $db->prepare('
            UPDATE oauth_tokens SET revocado_en = NOW()
            WHERE client_id = ? AND user_id = ? AND revocado_en IS NULL
        ');
        $stmt->execute([$clientId, $userId]);
    }
}
