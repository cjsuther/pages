<?php

/**
 * Los endpoints de autorización que el protocolo MCP espera de un server
 * remoto.
 *
 * El circuito, visto desde afuera: el cliente lee la metadata para saber a
 * dónde ir, se registra solo, manda a la persona al navegador, ésta autoriza
 * con la sesión que ya tiene, y el cliente canjea el código por un token.
 *
 * La pantalla donde la persona dice que sí no está acá: vive en el frontend,
 * porque la sesión de Rezonar es un token en el navegador y sólo la aplicación
 * sabe leerlo. Este handler la asiste con `revisar` y `aprobar`.
 */
class OAuthHandler
{
    /** Dirección pública del server MCP, que es el recurso protegido. */
    public static function urlDelRecurso()
    {
        return self::base() . '/mcp';
    }

    /**
     * Metadata del servidor de autorización (RFC 8414).
     *
     * Es lo primero que busca un cliente: acá se entera de a qué direcciones
     * tiene que hablarle y qué sabe hacer este servidor.
     */
    public static function metadataServidor($db, Request $req)
    {
        $base = self::base();

        return Response::ok([
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/registrar',
            'scopes_supported' => [OAuth::SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            // Sin PKCE no se emite nada, y sólo S256: "plain" no protege de
            // nadie que pueda leer el pedido.
            'code_challenge_methods_supported' => ['S256'],
        ]);
    }

    /**
     * Metadata del recurso protegido (RFC 9728).
     *
     * Le dice al cliente quién autoriza el acceso a este server MCP. Es lo que
     * permite que descubra el circuito entero a partir de un 401.
     */
    public static function metadataRecurso($db, Request $req)
    {
        return Response::ok([
            'resource' => self::urlDelRecurso(),
            'authorization_servers' => [self::base()],
            'scopes_supported' => [OAuth::SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /** Registro dinámico de clientes (RFC 7591). */
    public static function registrar($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $uris = $req->input('redirect_uris', []);

        $resultado = OAuth::registrarCliente(
            $db,
            $req->input('client_name', 'Cliente MCP'),
            is_array($uris) ? $uris : []
        );

        if (!$resultado['ok']) {
            return Response::json(400, ['error' => 'invalid_redirect_uri', 'error_description' => $resultado['error']]);
        }

        return Response::json(201, [
            'client_id' => $resultado['cliente']['client_id'],
            'redirect_uris' => $resultado['cliente']['redirect_uris'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ]);
    }

    /**
     * Datos para la pantalla de autorización.
     *
     * El frontend pregunta si el pedido es válido y quién lo hace, para poder
     * mostrar "tal aplicación quiere administrar tus eventos" en vez de un
     * cartel genérico. No hace falta sesión: todavía no se autorizó nada.
     */
    public static function revisar($db, Request $req)
    {
        $revision = OAuth::revisarPedido($db, $req->query);

        if (!$revision['ok']) {
            return Response::error(400, $revision['error']);
        }

        return Response::ok([
            'aplicacion' => $revision['cliente']['nombre'],
            'permiso' => 'Crear y administrar los eventos de tus páginas, y ver las ventas de entradas.',
        ]);
    }

    /**
     * La persona autorizó: se emite el código.
     *
     * Acá sí hace falta la sesión, porque el código se emite a nombre de quien
     * está autorizando. Se vuelve a revisar el pedido entero: entre que se
     * mostró la pantalla y se apretó el botón, lo único confiable es lo que
     * llega ahora.
     */
    public static function aprobar($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        if (!$req->user) {
            return Response::unauthorized();
        }

        // Una sesión abierta por el propio MCP no puede autorizar más accesos:
        // sería un permiso concediéndose a sí mismo.
        if (!empty($req->user['por_clave_api'])) {
            return Response::error(403, 'Esta autorización tiene que hacerla una persona desde el sitio');
        }

        $revision = OAuth::revisarPedido($db, $req->body);

        if (!$revision['ok']) {
            return Response::error(400, $revision['error']);
        }

        $codigo = OAuth::emitirCodigo($db, $req->userId(), $req->body);

        // El destino lo arma el servidor y no el navegador: es la única forma
        // de garantizar que el código vaya a una dirección registrada.
        $destino = $req->body['redirect_uri']
            . (strpos($req->body['redirect_uri'], '?') === false ? '?' : '&')
            . http_build_query(array_filter([
                'code' => $codigo,
                'state' => isset($req->body['state']) ? $req->body['state'] : null,
            ], function ($v) { return $v !== null; }));

        return Response::ok(['redirect_to' => $destino]);
    }

    /**
     * Canje del código por un token, y renovación.
     *
     * Contesta en el formato de OAuth —incluidos los errores, que van con su
     * código y no con un mensaje nuestro— porque lo lee un cliente que
     * implementa el estándar, no una pantalla nuestra.
     */
    public static function token($db, Request $req)
    {
        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        // El endpoint de token se llama con formulario, no con JSON.
        $params = empty($req->body) ? $req->form : $req->body;
        $tipo = isset($params['grant_type']) ? $params['grant_type'] : '';

        if ($tipo === 'authorization_code') {
            $resultado = OAuth::canjear($db, $params);
        } elseif ($tipo === 'refresh_token') {
            $resultado = OAuth::refrescar($db, $params);
        } else {
            return Response::json(400, ['error' => 'unsupported_grant_type']);
        }

        if (!$resultado['ok']) {
            return Response::json(400, ['error' => $resultado['error']]);
        }

        return Response::ok($resultado['token']);
    }

    /** Aplicaciones conectadas de la persona, y desconexión. */
    public static function conexiones($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'GET') {
            return Response::ok(['conexiones' => OAuth::conexiones($db, $req->userId())]);
        }

        if ($req->method === 'DELETE') {
            $clientId = (string) $req->param('client_id');

            if ($clientId === '') {
                return Response::error(400, 'client_id requerido');
            }

            if (!OAuth::desconectar($db, $req->userId(), $clientId)) {
                return Response::notFound('No encontramos esa conexión');
            }

            return Response::ok(['desconectada' => true]);
        }

        return Response::methodNotAllowed();
    }

    /** La dirección pública del sitio, que es el emisor de todo esto. */
    private static function base()
    {
        return defined('FRONTEND_URL') ? rtrim(FRONTEND_URL, '/') : 'https://rezon.ar';
    }
}
