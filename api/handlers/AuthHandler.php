<?php

/**
 * Autenticación: alta y login con email/contraseña, y OAuth con Google y Apple.
 *
 * Extraído de api/auth/*.php. Las llamadas salientes a los proveedores OAuth
 * se reciben por parámetro ($http) para poder testear los callbacks sin red.
 */
class AuthHandler
{
    const MIN_LONGITUD_PASSWORD = 6;

    const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GOOGLE_USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    const APPLE_AUTH_URL = 'https://appleid.apple.com/auth/authorize';
    const APPLE_TOKEN_URL = 'https://appleid.apple.com/auth/token';

    // ------------------------------------------------------------------ login

    public static function login($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if ($req->missing(['email', 'password'])) {
            return Response::error(400, 'Email and password are required');
        }

        try {
            $stmt = $db->prepare('SELECT id, email, name, password FROM users WHERE email = ?');
            $stmt->execute([$req->input('email')]);
            $user = $stmt->fetch();

            // Mismo mensaje para usuario inexistente y contraseña incorrecta:
            // no se filtra qué emails están registrados.
            if (!$user || !password_verify($req->input('password'), $user['password'])) {
                return Response::unauthorized('Invalid credentials');
            }

            return Response::ok([
                'token' => JWT::encode(['user_id' => $user['id'], 'email' => $user['email']], JWT_SECRET),
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                ],
            ]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // --------------------------------------------------------------- register

    public static function register($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if ($req->missing(['email', 'password'])) {
            return Response::error(400, 'Email and password are required');
        }

        $email = filter_var($req->input('email'), FILTER_VALIDATE_EMAIL);

        if (!$email) {
            return Response::error(400, 'Invalid email format');
        }

        if (strlen($req->input('password')) < self::MIN_LONGITUD_PASSWORD) {
            return Response::error(400, 'Password must be at least ' . self::MIN_LONGITUD_PASSWORD . ' characters');
        }

        try {
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                return Response::error(400, 'Email already exists');
            }

            $stmt = $db->prepare('INSERT INTO users (email, password) VALUES (?, ?)');
            $stmt->execute([$email, password_hash($req->input('password'), PASSWORD_BCRYPT)]);

            $userId = $db->lastInsertId();

            return Response::created([
                'token' => JWT::encode(['user_id' => $userId, 'email' => $email], JWT_SECRET),
                'user' => [
                    'id' => $userId,
                    'email' => $email,
                    'name' => null,
                ],
            ]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ------------------------------------------------------------ OAuth: inicio

    public static function googleLogin($db, Request $req)
    {
        return Response::redirect(self::GOOGLE_AUTH_URL . '?' . http_build_query([
            'client_id' => GOOGLE_CLIENT_ID,
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]));
    }

    public static function appleLogin($db, Request $req)
    {
        return Response::redirect(self::APPLE_AUTH_URL . '?' . http_build_query([
            'client_id' => APPLE_CLIENT_ID,
            'redirect_uri' => APPLE_REDIRECT_URI,
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => 'name email',
        ]));
    }

    // --------------------------------------------------------- OAuth: callbacks

    public static function googleCallback($db, Request $req, HttpClient $http = null)
    {
        $http = $http === null ? new HttpClient() : $http;

        if (!$req->param('code')) {
            return self::errorDeLogin($req->param('error', 'No authorization code received'));
        }

        $respuesta = $http->post(self::GOOGLE_TOKEN_URL, [
            'code' => $req->param('code'),
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'grant_type' => 'authorization_code',
        ]);

        if ($respuesta['status'] !== 200) {
            return self::errorDeLogin('token_exchange_failed');
        }

        $tokenData = json_decode($respuesta['body'], true);

        if (!isset($tokenData['access_token'])) {
            return self::errorDeLogin('no_access_token');
        }

        $perfil = $http->get(self::GOOGLE_USERINFO_URL, [
            'Authorization: Bearer ' . $tokenData['access_token'],
        ]);
        $userInfo = json_decode($perfil['body'], true);

        if (!isset($userInfo['email'])) {
            return self::errorDeLogin('no_email');
        }

        $user = self::buscarOCrearUsuarioOAuth($db, 'google', [
            'oauth_id' => isset($userInfo['id']) ? $userInfo['id'] : null,
            'email' => $userInfo['email'],
            'name' => isset($userInfo['name']) ? $userInfo['name'] : null,
            'avatar_url' => isset($userInfo['picture']) ? $userInfo['picture'] : null,
        ]);

        return self::redirigirConSesion($user);
    }

    public static function appleCallback($db, Request $req, HttpClient $http = null)
    {
        $http = $http === null ? new HttpClient() : $http;

        // Apple responde con response_mode=form_post: los datos llegan en $_POST.
        if (!$req->formInput('code')) {
            return self::errorDeLogin($req->formInput('error', 'No authorization code received'));
        }

        $clientSecret = AppleJWT::generateClientSecret(
            APPLE_TEAM_ID,
            APPLE_CLIENT_ID,
            APPLE_KEY_ID,
            APPLE_PRIVATE_KEY
        );

        $respuesta = $http->post(self::APPLE_TOKEN_URL, [
            'client_id' => APPLE_CLIENT_ID,
            'client_secret' => $clientSecret,
            'code' => $req->formInput('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => APPLE_REDIRECT_URI,
        ]);

        if ($respuesta['status'] !== 200) {
            return self::errorDeLogin('token_exchange_failed');
        }

        $tokenData = json_decode($respuesta['body'], true);

        if (!isset($tokenData['id_token'])) {
            return self::errorDeLogin('no_id_token');
        }

        $payload = self::payloadDeIdToken($tokenData['id_token']);

        if ($payload === null) {
            return self::errorDeLogin('invalid_token');
        }

        if (!isset($payload['email'])) {
            return self::errorDeLogin('no_email');
        }

        $user = self::buscarOCrearUsuarioOAuth($db, 'apple', [
            'oauth_id' => isset($payload['sub']) ? $payload['sub'] : null,
            'email' => $payload['email'],
            'name' => self::nombreDeApple($req),
            'avatar_url' => null,
        ]);

        return self::redirigirConSesion($user);
    }

    /**
     * Apple sólo manda el nombre en el primer login, y como JSON dentro de un
     * campo del formulario.
     */
    public static function nombreDeApple(Request $req)
    {
        $crudo = $req->formInput('user');

        if (!$crudo) {
            return null;
        }

        $userObject = json_decode($crudo, true);

        if (!isset($userObject['name'])) {
            return null;
        }

        $nombre = $userObject['name'];
        $completo = trim(
            (isset($nombre['firstName']) ? $nombre['firstName'] : '') . ' ' .
            (isset($nombre['lastName']) ? $nombre['lastName'] : '')
        );

        return $completo === '' ? null : $completo;
    }

    /** Payload del id_token, o null si no tiene forma de JWT. */
    public static function payloadDeIdToken($idToken)
    {
        $partes = explode('.', $idToken);

        if (count($partes) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);

        return is_array($payload) ? $payload : null;
    }

    // ------------------------------------------------------------- compartido

    /**
     * Vincula la identidad OAuth con un usuario: por (provider, oauth_id), si
     * no por email, y si tampoco existe lo crea.
     */
    private static function buscarOCrearUsuarioOAuth($db, $provider, array $datos)
    {
        $stmt = $db->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ?');
        $stmt->execute([$provider, $datos['oauth_id']]);
        $user = $stmt->fetch();

        if ($user) {
            return $user;
        }

        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$datos['email']]);
        $existente = $stmt->fetch();

        if ($existente) {
            // Cuenta creada antes con email/contraseña: se le asocia el proveedor.
            if ($provider === 'apple') {
                $stmt = $db->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ?, name = ? WHERE id = ?');
                $stmt->execute([
                    $provider,
                    $datos['oauth_id'],
                    $datos['name'] !== null ? $datos['name'] : $existente['name'],
                    $existente['id'],
                ]);
            } else {
                $stmt = $db->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ?, name = ?, avatar_url = ? WHERE id = ?');
                $stmt->execute([
                    $provider,
                    $datos['oauth_id'],
                    $datos['name'],
                    $datos['avatar_url'],
                    $existente['id'],
                ]);
            }

            return $existente;
        }

        if ($provider === 'apple') {
            $stmt = $db->prepare('INSERT INTO users (email, oauth_provider, oauth_id, name) VALUES (?, ?, ?, ?)');
            $stmt->execute([$datos['email'], $provider, $datos['oauth_id'], $datos['name']]);
        } else {
            $stmt = $db->prepare('INSERT INTO users (email, oauth_provider, oauth_id, name, avatar_url) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$datos['email'], $provider, $datos['oauth_id'], $datos['name'], $datos['avatar_url']]);
        }

        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$db->lastInsertId()]);

        return $stmt->fetch();
    }

    /** Vuelve al frontend con el token en la URL para que la SPA lo guarde. */
    private static function redirigirConSesion($user)
    {
        // Si el alta falló, $user viene en false. El código original accedía
        // igual a $user['id'] y redirigía con un token roto; acá se corta.
        if (!is_array($user) || !isset($user['id'])) {
            return self::errorDeLogin('user_creation_failed');
        }

        $token = JWT::encode(['user_id' => $user['id'], 'email' => $user['email']], JWT_SECRET);

        $userData = [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => isset($user['name']) ? $user['name'] : null,
            'avatar_url' => isset($user['avatar_url']) ? $user['avatar_url'] : null,
        ];

        return Response::redirect(
            FRONTEND_URL . '/login?token=' . $token . '&user=' . urlencode(json_encode($userData))
        );
    }

    private static function errorDeLogin($error)
    {
        return Response::redirect(FRONTEND_URL . '/login?error=' . urlencode($error));
    }
}
