<?php

/**
 * Punto de entrada común de los endpoints.
 *
 * Cada archivo .php público queda como un wrapper de dos líneas que delega en
 * un handler testeable. Aquí viven las únicas partes que no se pueden testear
 * en aislamiento: superglobales, conexión real a la base y escritura de salida.
 */
class Api
{
    /**
     * Ejecuta un handler con la firma handler($db, Request $req): Response.
     *
     * @param callable $handler
     * @param bool     $needsDb  false para handlers que no tocan la base
     */
    public static function run($handler, $needsDb = true)
    {
        $db = null;

        if ($needsDb) {
            $database = new Database();
            $db = $database->connect();
        }

        $request = Request::fromGlobals(JWT::getUserFromToken());

        try {
            $response = call_user_func($handler, $db, $request);
        } catch (Exception $e) {
            $response = Response::serverError($e->getMessage());
        } catch (Throwable $e) {
            $response = Response::serverError($e->getMessage());
        }

        if (!$response instanceof Response) {
            $response = Response::serverError('Handler did not return a Response');
        }

        $response->send();
    }
}
