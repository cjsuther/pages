<?php

namespace Tests\Unit\Handlers;

use AuthHandler;
use JWT;
use Request;
use Tests\Support\FakeHttpClient;
use Tests\Support\HandlerTestCase;

class AuthHandlerTest extends HandlerTestCase
{
    // ================================================================== login

    public function testLoginRechazaMetodosDistintosDePost()
    {
        $this->assertError(405, AuthHandler::login($this->db, $this->get()), 'Method not allowed');
    }

    /**
     * @dataProvider credencialesIncompletas
     */
    public function testLoginExigeEmailYPassword($cuerpo)
    {
        $res = AuthHandler::login($this->db, $this->post($cuerpo));

        $this->assertError(400, $res, 'Email and password are required');
    }

    public function credencialesIncompletas()
    {
        return [
            'vacío' => [[]],
            'sin email' => [['password' => 'secreto']],
            'sin password' => [['email' => 'a@b.com']],
        ];
    }

    public function testLoginRechazaUsuarioInexistente()
    {
        $res = AuthHandler::login($this->db, $this->post([
            'email' => 'nadie@b.com',
            'password' => 'secreto',
        ]));

        $this->assertError(401, $res, 'Invalid credentials');
    }

    public function testLoginRechazaPasswordIncorrecta()
    {
        $this->usuarioConPassword('correcta');

        $res = AuthHandler::login($this->db, $this->post([
            'email' => 'a@b.com',
            'password' => 'incorrecta',
        ]));

        $this->assertError(401, $res, 'Invalid credentials');
    }

    public function testLoginNoRevelaSiElEmailExiste()
    {
        // Los dos casos deben ser indistinguibles desde afuera.
        $sinUsuario = AuthHandler::login($this->db, $this->post([
            'email' => 'nadie@b.com', 'password' => 'x',
        ]));

        $this->setUp();
        $this->usuarioConPassword('correcta');
        $conPasswordMala = AuthHandler::login($this->db, $this->post([
            'email' => 'a@b.com', 'password' => 'incorrecta',
        ]));

        $this->assertSame($sinUsuario->status, $conPasswordMala->status);
        $this->assertSame($sinUsuario->body, $conPasswordMala->body);
    }

    public function testLoginDevuelveTokenYUsuario()
    {
        $this->usuarioConPassword('secreto');

        $res = AuthHandler::login($this->db, $this->post([
            'email' => 'a@b.com',
            'password' => 'secreto',
        ]));

        $this->assertStatus(200, $res);
        $this->assertArrayHasKey('token', $res->body);
        $this->assertSame(['id' => 7, 'email' => 'a@b.com', 'name' => 'Ana'], $res->body['user']);
    }

    public function testLoginEmiteUnTokenValidoParaEseUsuario()
    {
        $this->usuarioConPassword('secreto');

        $res = AuthHandler::login($this->db, $this->post([
            'email' => 'a@b.com',
            'password' => 'secreto',
        ]));

        $payload = JWT::decode($res->body['token'], JWT_SECRET);

        $this->assertSame(7, $payload['user_id']);
        $this->assertSame('a@b.com', $payload['email']);
    }

    public function testLoginNoDevuelveElHashDeLaPassword()
    {
        $this->usuarioConPassword('secreto');

        $res = AuthHandler::login($this->db, $this->post([
            'email' => 'a@b.com',
            'password' => 'secreto',
        ]));

        $this->assertArrayNotHasKey('password', $res->body['user']);
        $this->assertStringNotContainsString('$2y$', json_encode($res->body));
    }

    public function testLoginDevuelve500SiLaBaseFalla()
    {
        $this->db->failOn('FROM users WHERE email = ?', 'sin conexión');

        $res = AuthHandler::login($this->db, $this->post([
            'email' => 'a@b.com', 'password' => 'x',
        ]));

        $this->assertError(500, $res, 'Server error: sin conexión');
    }

    // =============================================================== register

    public function testRegisterRechazaMetodosDistintosDePost()
    {
        $this->assertError(405, AuthHandler::register($this->db, $this->get()), 'Method not allowed');
    }

    public function testRegisterExigeEmailYPassword()
    {
        $res = AuthHandler::register($this->db, $this->post([]));

        $this->assertError(400, $res, 'Email and password are required');
    }

    /**
     * @dataProvider emailsInvalidos
     */
    public function testRegisterRechazaEmailMalFormado($email)
    {
        $res = AuthHandler::register($this->db, $this->post([
            'email' => $email,
            'password' => 'secreto123',
        ]));

        $this->assertError(400, $res, 'Invalid email format');
        $this->assertNoWrites();
    }

    public function emailsInvalidos()
    {
        return [
            'sin arroba' => ['abc'],
            'sin dominio' => ['a@'],
            'sin usuario' => ['@b.com'],
            'con espacios' => ['a b@c.com'],
            'vacío' => [''],
        ];
    }

    /**
     * @dataProvider passwordsCortas
     */
    public function testRegisterExigePasswordDeSeisCaracteres($password)
    {
        $res = AuthHandler::register($this->db, $this->post([
            'email' => 'a@b.com',
            'password' => $password,
        ]));

        $this->assertError(400, $res, 'Password must be at least 6 characters');
        $this->assertNoWrites();
    }

    public function passwordsCortas()
    {
        return [['a'], ['12345'], ['']];
    }

    public function testRegisterAceptaPasswordDeExactamenteSeis()
    {
        $this->db->onInsert('INSERT INTO users', 15);

        $res = AuthHandler::register($this->db, $this->post([
            'email' => 'a@b.com',
            'password' => '123456',
        ]));

        $this->assertStatus(201, $res);
    }

    public function testRegisterRechazaEmailYaRegistrado()
    {
        $this->db->onSelect('SELECT id FROM users WHERE email = ?', [['id' => 3]]);

        $res = AuthHandler::register($this->db, $this->post([
            'email' => 'a@b.com',
            'password' => 'secreto123',
        ]));

        $this->assertError(400, $res, 'Email already exists');
        $this->assertNoWrites();
    }

    public function testRegisterCreaElUsuarioYDevuelve201()
    {
        $this->db->onInsert('INSERT INTO users', 15);

        $res = AuthHandler::register($this->db, $this->post([
            'email' => 'nueva@b.com',
            'password' => 'secreto123',
        ]));

        $this->assertStatus(201, $res);
        $this->assertSame(['id' => '15', 'email' => 'nueva@b.com', 'name' => null], $res->body['user']);
    }

    public function testRegisterGuardaLaPasswordHasheada()
    {
        $this->db->onInsert('INSERT INTO users', 15);

        AuthHandler::register($this->db, $this->post([
            'email' => 'nueva@b.com',
            'password' => 'secreto123',
        ]));

        $params = $this->db->paramsFor('INSERT INTO users');

        $this->assertNotSame('secreto123', $params[1], 'jamás debe guardarse en claro');
        $this->assertStringStartsWith('$2y$', $params[1], 'debe ser un hash bcrypt');
        $this->assertTrue(password_verify('secreto123', $params[1]));
    }

    public function testRegisterNormalizaElEmailConFilterVar()
    {
        $this->db->onInsert('INSERT INTO users', 15);

        AuthHandler::register($this->db, $this->post([
            'email' => 'Nueva@B.com',
            'password' => 'secreto123',
        ]));

        $this->assertSame('Nueva@B.com', $this->db->paramsFor('INSERT INTO users')[0]);
    }

    public function testRegisterEmiteUnTokenUtilizable()
    {
        $this->db->onInsert('INSERT INTO users', 15);

        $res = AuthHandler::register($this->db, $this->post([
            'email' => 'nueva@b.com',
            'password' => 'secreto123',
        ]));

        $payload = JWT::decode($res->body['token'], JWT_SECRET);

        $this->assertSame('15', $payload['user_id']);
        $this->assertSame('nueva@b.com', $payload['email']);
    }

    // ========================================================== OAuth: inicio

    public function testGoogleLoginRedirigeAGoogle()
    {
        $res = AuthHandler::googleLogin(null, $this->get());

        $this->assertTrue($res->isRedirect());
        $this->assertStringStartsWith(AuthHandler::GOOGLE_AUTH_URL, $res->redirectUrl);
    }

    public function testGoogleLoginPideLosScopesNecesarios()
    {
        $res = AuthHandler::googleLogin(null, $this->get());

        parse_str(parse_url($res->redirectUrl, PHP_URL_QUERY), $params);

        $this->assertSame(GOOGLE_CLIENT_ID, $params['client_id']);
        $this->assertSame(GOOGLE_REDIRECT_URI, $params['redirect_uri']);
        $this->assertSame('code', $params['response_type']);
        $this->assertSame('openid email profile', $params['scope']);
    }

    public function testGoogleLoginNoFiltraElClientSecret()
    {
        $res = AuthHandler::googleLogin(null, $this->get());

        $this->assertStringNotContainsString(GOOGLE_CLIENT_SECRET, $res->redirectUrl);
    }

    public function testAppleLoginRedirigeAApple()
    {
        $res = AuthHandler::appleLogin(null, $this->get());

        $this->assertTrue($res->isRedirect());
        $this->assertStringStartsWith(AuthHandler::APPLE_AUTH_URL, $res->redirectUrl);
    }

    public function testAppleLoginUsaFormPost()
    {
        $res = AuthHandler::appleLogin(null, $this->get());

        parse_str(parse_url($res->redirectUrl, PHP_URL_QUERY), $params);

        $this->assertSame('form_post', $params['response_mode']);
        $this->assertSame('name email', $params['scope']);
    }

    // ================================================== OAuth: callback Google

    public function testGoogleCallbackSinCodeVuelveAlLoginConError()
    {
        $res = AuthHandler::googleCallback($this->db, $this->get(), new FakeHttpClient());

        $this->assertTrue($res->isRedirect());
        $this->assertStringContainsString('/login?error=', $res->redirectUrl);
        $this->assertStringContainsString(urlencode('No authorization code received'), $res->redirectUrl);
    }

    public function testGoogleCallbackPropagaElErrorDelProveedor()
    {
        $res = AuthHandler::googleCallback(
            $this->db,
            $this->get(['error' => 'access_denied']),
            new FakeHttpClient()
        );

        $this->assertStringContainsString('error=access_denied', $res->redirectUrl);
    }

    public function testGoogleCallbackManejaFalloAlCanjearElCodigo()
    {
        $http = (new FakeHttpClient())->responde('oauth2.googleapis.com/token', 400, '{"error":"bad"}');

        $res = AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $http);

        $this->assertStringContainsString('error=token_exchange_failed', $res->redirectUrl);
    }

    public function testGoogleCallbackManejaRespuestaSinAccessToken()
    {
        $http = (new FakeHttpClient())->responde('oauth2.googleapis.com/token', 200, '{}');

        $res = AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $http);

        $this->assertStringContainsString('error=no_access_token', $res->redirectUrl);
    }

    public function testGoogleCallbackManejaPerfilSinEmail()
    {
        $http = (new FakeHttpClient())
            ->responde('oauth2.googleapis.com/token', 200, ['access_token' => 'at'])
            ->responde('googleapis.com/oauth2/v2/userinfo', 200, ['id' => '123']);

        $res = AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $http);

        $this->assertStringContainsString('error=no_email', $res->redirectUrl);
    }

    public function testGoogleCallbackEnviaElCodigoYElSecretoAlCanjear()
    {
        $http = $this->googleFeliz();
        $this->usuarioGoogleYaVinculado();

        AuthHandler::googleCallback($this->db, $this->get(['code' => 'el-codigo']), $http);

        $campos = $http->camposDe('oauth2.googleapis.com/token');

        $this->assertSame('el-codigo', $campos['code']);
        $this->assertSame(GOOGLE_CLIENT_ID, $campos['client_id']);
        $this->assertSame(GOOGLE_CLIENT_SECRET, $campos['client_secret']);
        $this->assertSame('authorization_code', $campos['grant_type']);
    }

    public function testGoogleCallbackUsaElAccessTokenParaPedirElPerfil()
    {
        $http = $this->googleFeliz();
        $this->usuarioGoogleYaVinculado();

        AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $http);

        $this->assertSame(
            ['Authorization: Bearer el-access-token'],
            $http->cabecerasDe('googleapis.com/oauth2/v2/userinfo')
        );
    }

    public function testGoogleCallbackIniciaSesionConUsuarioYaVinculado()
    {
        $this->db->onSelect('WHERE oauth_provider = ? AND oauth_id = ?', [[
            'id' => 7, 'email' => 'a@b.com', 'name' => 'Ana', 'avatar_url' => null,
        ]]);

        $res = AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $this->googleFeliz());

        $this->assertTrue($res->isRedirect());
        $this->assertStringContainsString('token=', $res->redirectUrl);
        $this->assertSame(0, $this->db->countCalls('INSERT INTO users'), 'no debe crear un usuario nuevo');
    }

    public function testGoogleCallbackVinculaCuentaExistentePorEmail()
    {
        // Sin fila por (provider, oauth_id), pero sí por email.
        $this->db->onSelect('WHERE oauth_provider = ? AND oauth_id = ?', []);
        $this->db->onSelect('SELECT * FROM users WHERE email = ?', [[
            'id' => 7, 'email' => 'a@b.com', 'name' => 'Ana', 'avatar_url' => null,
        ]]);

        AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $this->googleFeliz());

        $this->assertSame(0, $this->db->countCalls('INSERT INTO users'));
        $this->assertSame(
            ['google', '123', 'Ana Google', 'https://foto', 7],
            $this->db->paramsFor('UPDATE users SET oauth_provider')
        );
    }

    public function testGoogleCallbackCreaUsuarioNuevo()
    {
        $this->db->onInsert('INSERT INTO users', 15);
        $this->db->onSelect('SELECT * FROM users WHERE id = ?', [[
            'id' => 15, 'email' => 'nueva@b.com', 'name' => 'Ana Google', 'avatar_url' => 'https://foto',
        ]]);

        $res = AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $this->googleFeliz());

        $this->assertSame(
            ['nueva@b.com', 'google', '123', 'Ana Google', 'https://foto'],
            $this->db->paramsFor('INSERT INTO users')
        );
        $this->assertStringContainsString('token=', $res->redirectUrl);
    }

    public function testGoogleCallbackRedirigeAlFrontendConElUsuario()
    {
        $this->db->onSelect('WHERE oauth_provider = ? AND oauth_id = ?', [[
            'id' => 7, 'email' => 'a@b.com', 'name' => 'Ana', 'avatar_url' => 'https://foto',
        ]]);

        $res = AuthHandler::googleCallback($this->db, $this->get(['code' => 'abc']), $this->googleFeliz());

        $this->assertStringStartsWith(FRONTEND_URL . '/login?', $res->redirectUrl);

        parse_str(parse_url($res->redirectUrl, PHP_URL_QUERY), $params);
        $usuario = json_decode($params['user'], true);

        $this->assertSame(7, $usuario['id']);
        $this->assertSame('a@b.com', $usuario['email']);
        $this->assertSame(7, JWT::decode($params['token'], JWT_SECRET)['user_id']);
    }

    // =================================================== OAuth: callback Apple

    public function testAppleCallbackSinCodeVuelveAlLoginConError()
    {
        $res = AuthHandler::appleCallback($this->db, new Request('POST'), new FakeHttpClient());

        $this->assertStringContainsString(urlencode('No authorization code received'), $res->redirectUrl);
    }

    public function testAppleCallbackLeeElCodeDelFormularioNoDelQueryString()
    {
        // Apple usa response_mode=form_post: un code en el query string no vale.
        $res = AuthHandler::appleCallback(
            $this->db,
            new Request('POST', [], ['code' => 'en-query'], null, [], [], []),
            new FakeHttpClient()
        );

        $this->assertStringContainsString('error=', $res->redirectUrl);
    }

    public function testAppleCallbackManejaFalloAlCanjearElCodigo()
    {
        $http = (new FakeHttpClient())->responde('appleid.apple.com/auth/token', 400, '{}');

        $res = AuthHandler::appleCallback($this->db, $this->formPost(['code' => 'abc']), $http);

        $this->assertStringContainsString('error=token_exchange_failed', $res->redirectUrl);
    }

    public function testAppleCallbackManejaRespuestaSinIdToken()
    {
        $http = (new FakeHttpClient())->responde('appleid.apple.com/auth/token', 200, '{}');

        $res = AuthHandler::appleCallback($this->db, $this->formPost(['code' => 'abc']), $http);

        $this->assertStringContainsString('error=no_id_token', $res->redirectUrl);
    }

    public function testAppleCallbackRechazaIdTokenMalFormado()
    {
        $http = (new FakeHttpClient())->responde('appleid.apple.com/auth/token', 200, [
            'id_token' => 'esto-no-es-un-jwt',
        ]);

        $res = AuthHandler::appleCallback($this->db, $this->formPost(['code' => 'abc']), $http);

        $this->assertStringContainsString('error=invalid_token', $res->redirectUrl);
    }

    public function testAppleCallbackManejaIdTokenSinEmail()
    {
        $http = (new FakeHttpClient())->responde('appleid.apple.com/auth/token', 200, [
            'id_token' => $this->idTokenApple(['sub' => 'apple-123']),
        ]);

        $res = AuthHandler::appleCallback($this->db, $this->formPost(['code' => 'abc']), $http);

        $this->assertStringContainsString('error=no_email', $res->redirectUrl);
    }

    public function testAppleCallbackCreaUsuarioNuevoConNombre()
    {
        $this->db->onInsert('INSERT INTO users', 20);
        $this->db->onSelect('SELECT * FROM users WHERE id = ?', [[
            'id' => 20, 'email' => 'a@privaterelay.appleid.com', 'name' => 'Ana Gómez', 'avatar_url' => null,
        ]]);

        AuthHandler::appleCallback($this->db, $this->formPost([
            'code' => 'abc',
            'user' => json_encode(['name' => ['firstName' => 'Ana', 'lastName' => 'Gómez']]),
        ]), $this->appleFeliz());

        $this->assertSame(
            ['a@privaterelay.appleid.com', 'apple', 'apple-123', 'Ana Gómez'],
            $this->db->paramsFor('INSERT INTO users')
        );
    }

    public function testAppleCallbackConservaElNombreExistenteSiAppleNoLoManda()
    {
        // Apple sólo envía el nombre en el primer login.
        $this->db->onSelect('WHERE oauth_provider = ? AND oauth_id = ?', []);
        $this->db->onSelect('SELECT * FROM users WHERE email = ?', [[
            'id' => 7, 'email' => 'a@privaterelay.appleid.com', 'name' => 'Nombre Previo', 'avatar_url' => null,
        ]]);

        AuthHandler::appleCallback($this->db, $this->formPost(['code' => 'abc']), $this->appleFeliz());

        $params = $this->db->paramsFor('UPDATE users SET oauth_provider');

        $this->assertSame('Nombre Previo', $params[2]);
    }

    // ---------------------------------------------------- nombre y id_token

    /**
     * @dataProvider nombresDeApple
     */
    public function testNombreDeApple($form, $esperado)
    {
        $this->assertSame($esperado, AuthHandler::nombreDeApple($this->formPost($form)));
    }

    public function nombresDeApple()
    {
        return [
            'sin campo user' => [[], null],
            'json inválido' => [['user' => 'no-json'], null],
            'sin nombre' => [['user' => '{"otro":1}'], null],
            'completo' => [['user' => '{"name":{"firstName":"Ana","lastName":"Gómez"}}'], 'Ana Gómez'],
            'sólo nombre' => [['user' => '{"name":{"firstName":"Ana"}}'], 'Ana'],
            'sólo apellido' => [['user' => '{"name":{"lastName":"Gómez"}}'], 'Gómez'],
            'ambos vacíos' => [['user' => '{"name":{"firstName":"","lastName":""}}'], null],
        ];
    }

    public function testPayloadDeIdTokenDecodificaElPayload()
    {
        $payload = AuthHandler::payloadDeIdToken($this->idTokenApple(['sub' => 'x', 'email' => 'a@b.com']));

        $this->assertSame('a@b.com', $payload['email']);
    }

    /**
     * @dataProvider idTokensInvalidos
     */
    public function testPayloadDeIdTokenDevuelveNullSiNoEsJwt($token)
    {
        $this->assertNull(AuthHandler::payloadDeIdToken($token));
    }

    public function idTokensInvalidos()
    {
        return [
            'vacío' => [''],
            'sin puntos' => ['abc'],
            'dos partes' => ['a.b'],
            'payload no json' => ['a.bm8t.c'],
        ];
    }

    // ------------------------------------------------------------- ayudantes

    private function usuarioConPassword($password)
    {
        $this->db->onSelect('FROM users WHERE email = ?', [[
            'id' => 7,
            'email' => 'a@b.com',
            'name' => 'Ana',
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ]]);
    }

    private function usuarioGoogleYaVinculado()
    {
        $this->db->onSelect('WHERE oauth_provider = ? AND oauth_id = ?', [[
            'id' => 7, 'email' => 'a@b.com', 'name' => 'Ana', 'avatar_url' => null,
        ]]);
    }

    private function googleFeliz()
    {
        return (new FakeHttpClient())
            ->responde('oauth2.googleapis.com/token', 200, ['access_token' => 'el-access-token'])
            ->responde('googleapis.com/oauth2/v2/userinfo', 200, [
                'id' => '123',
                'email' => 'nueva@b.com',
                'name' => 'Ana Google',
                'picture' => 'https://foto',
            ]);
    }

    private function appleFeliz()
    {
        return (new FakeHttpClient())->responde('appleid.apple.com/auth/token', 200, [
            'id_token' => $this->idTokenApple([
                'sub' => 'apple-123',
                'email' => 'a@privaterelay.appleid.com',
            ]),
        ]);
    }

    /** id_token de Apple con el payload pedido (la firma no se verifica). */
    private function idTokenApple(array $payload)
    {
        $b64 = function ($data) {
            return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        };

        return $b64(['alg' => 'RS256']) . '.' . $b64($payload) . '.firma';
    }

    private function formPost(array $form)
    {
        return new Request('POST', [], [], null, [], [], $form);
    }
}
