<?php

/**
 * Server MCP de Rezonar.
 *
 * Deja que un asistente administre eventos de las páginas de una persona:
 * crearlos, editarlos, ponerles venta de entradas y ver cómo va.
 *
 * Está en PHP y no en Node a propósito. El transporte HTTP de MCP es
 * JSON-RPC 2.0 sobre POST, así que no hace falta otro runtime: vive junto a la
 * API, con el mismo deploy, las mismas credenciales y los mismos handlers, que
 * es lo que garantiza que el asistente no pueda hacer nada que una persona no
 * pudiera hacer desde el editor.
 *
 * La autenticación es una clave de API en la cabecera Authorization. El
 * protocolo prevé OAuth para servers remotos, pero eso obliga a montar un
 * autorizador entero; una clave que no vence y se puede revocar resuelve el
 * mismo problema con las piezas que ya hay.
 */
class McpHandler
{
    /** Versión del protocolo con la que se implementó. */
    const PROTOCOLO = '2025-06-18';

    /** Versiones que se saben hablar, de la más nueva a la más vieja. */
    const PROTOCOLOS_CONOCIDOS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    // Códigos de error de JSON-RPC 2.0.
    const ERROR_PARSEO = -32700;
    const ERROR_PEDIDO_INVALIDO = -32600;
    const ERROR_METODO_DESCONOCIDO = -32601;
    const ERROR_PARAMETROS = -32602;
    const ERROR_INTERNO = -32603;

    public static function rpc($db, Request $req)
    {
        if ($req->method === 'GET') {
            // El transporte prevé un canal SSE para que el server empuje
            // mensajes por su cuenta. Acá no hay nada que empujar: las
            // herramientas contestan en el mismo pedido.
            return Response::raw(405, '', ['Allow' => 'POST']);
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $usuario = ClavesApi::usuario($db, $req->bearerToken());

        if ($usuario === null) {
            // El WWW-Authenticate es lo que le dice al cliente que el problema
            // es la credencial y no el pedido.
            return Response::raw(
                401,
                json_encode(['error' => 'Clave de API inválida o revocada']),
                ['WWW-Authenticate' => 'Bearer realm="rezonar"', 'Content-Type' => 'application/json']
            );
        }

        $mensaje = $req->body;

        // El batch de JSON-RPC se sacó del protocolo en 2025-06-18.
        if (isset($mensaje[0])) {
            return self::rpcError(null, self::ERROR_PEDIDO_INVALIDO, 'No se aceptan lotes de mensajes');
        }

        if (!isset($mensaje['method']) || !is_string($mensaje['method'])) {
            return self::rpcError(self::idDe($mensaje), self::ERROR_PEDIDO_INVALIDO, 'Falta el método');
        }

        return self::despachar($db, $usuario, $mensaje);
    }

    private static function despachar($db, array $usuario, array $mensaje)
    {
        $metodo = $mensaje['method'];
        $id = self::idDe($mensaje);
        $params = isset($mensaje['params']) && is_array($mensaje['params']) ? $mensaje['params'] : [];

        // Una notificación no lleva id y no espera respuesta.
        if ($id === null) {
            return Response::raw(202, '');
        }

        switch ($metodo) {
            case 'initialize':
                return self::rpcOk($id, self::saludo($params));

            case 'ping':
                return self::rpcOk($id, new stdClass());

            case 'tools/list':
                return self::rpcOk($id, ['tools' => HerramientasMcp::catalogo()]);

            case 'tools/call':
                return self::llamar($db, $usuario, $id, $params);
        }

        return self::rpcError($id, self::ERROR_METODO_DESCONOCIDO, "No conozco el método '$metodo'");
    }

    /**
     * Respuesta al saludo inicial.
     *
     * Se contesta con la versión que pidió el cliente si se la sabe hablar; si
     * no, con la propia, y el cliente decide si sigue o corta.
     */
    private static function saludo(array $params)
    {
        $pedida = isset($params['protocolVersion']) ? $params['protocolVersion'] : null;

        return [
            'protocolVersion' => in_array($pedida, self::PROTOCOLOS_CONOCIDOS, true) ? $pedida : self::PROTOCOLO,
            'capabilities' => ['tools' => new stdClass()],
            'serverInfo' => ['name' => 'rezonar', 'version' => '1.0.0'],
            'instructions' => 'Administra los eventos de las páginas de Rezonar de quien te dio la clave. '
                . 'Antes de crear o editar, usá listar_paginas para saber sobre qué página trabajás.',
        ];
    }

    private static function llamar($db, array $usuario, $id, array $params)
    {
        $nombre = isset($params['name']) ? $params['name'] : null;

        if (!is_string($nombre) || $nombre === '') {
            return self::rpcError($id, self::ERROR_PARAMETROS, 'Falta el nombre de la herramienta');
        }

        $argumentos = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

        try {
            $resultado = HerramientasMcp::ejecutar($db, $usuario, $nombre, $argumentos);
        } catch (InvalidArgumentException $e) {
            // Herramienta inexistente: es un error del protocolo, no del
            // trabajo pedido, así que va como error de JSON-RPC.
            return self::rpcError($id, self::ERROR_PARAMETROS, $e->getMessage());
        } catch (Throwable $e) {
            return self::rpcError($id, self::ERROR_INTERNO, 'Algo falló al ejecutar la herramienta');
        }

        // Un trabajo que sale mal se devuelve como resultado con isError, no
        // como error de protocolo: así el modelo lee el motivo y puede
        // corregir, en vez de recibir un fallo opaco.
        return self::rpcOk($id, [
            'content' => [['type' => 'text', 'text' => self::comoTexto($resultado['datos'])]],
            'isError' => !$resultado['ok'],
        ]);
    }

    /** El id puede ser número o texto; ausente significa notificación. */
    private static function idDe($mensaje)
    {
        return is_array($mensaje) && isset($mensaje['id']) ? $mensaje['id'] : null;
    }

    private static function comoTexto($datos)
    {
        return is_string($datos)
            ? $datos
            : json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function rpcOk($id, $resultado)
    {
        return Response::ok(['jsonrpc' => '2.0', 'id' => $id, 'result' => $resultado]);
    }

    public static function rpcError($id, $codigo, $mensaje)
    {
        // Siempre 200: el error viaja adentro del sobre de JSON-RPC. Un estado
        // HTTP de error haría que el cliente lo trate como caída del
        // transporte en vez de como respuesta del server.
        return Response::ok([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $codigo, 'message' => $mensaje],
        ]);
    }
}
