<?php

/**
 * Respuesta que devuelve un handler. Es un objeto de datos: no escribe nada
 * hasta que se llama a send(), de modo que los tests puedan inspeccionarla.
 */
class Response
{
    /** @var int */
    public $status;

    /** @var mixed Cuerpo que se serializa a JSON. null si es una redirección. */
    public $body;

    /** @var string|null URL de redirección (Location) */
    public $redirectUrl;

    /** @var array Cabeceras extra, como ['Content-Type' => 'text/html'] */
    public $headers;

    /** @var string|null Cuerpo crudo, para respuestas que no son JSON */
    public $raw;

    /** @var string|null Ruta de un archivo que se manda como cuerpo */
    public $archivo;

    /** Lo que se lee y se manda por vez al servir un archivo. */
    const BLOQUE = 262144; // 256 KB

    private function __construct($status, $body = null, $redirectUrl = null, array $headers = [], $raw = null, $archivo = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->redirectUrl = $redirectUrl;
        $this->headers = $headers;
        $this->raw = $raw;
        $this->archivo = $archivo;
    }

    public static function json($status, $body)
    {
        return new self($status, $body);
    }

    public static function ok($body)
    {
        return new self(200, $body);
    }

    public static function created($body)
    {
        return new self(201, $body);
    }

    /** Atajo para los errores, que en esta API siempre tienen la forma {"error": "..."} */
    public static function error($status, $message)
    {
        return new self($status, ['error' => $message]);
    }

    public static function unauthorized($message = 'Unauthorized')
    {
        return self::error(401, $message);
    }

    public static function notFound($message = 'Not found')
    {
        return self::error(404, $message);
    }

    public static function methodNotAllowed()
    {
        return self::error(405, 'Method not allowed');
    }

    /** Error 500 con el formato heredado: "Server error: <mensaje>" */
    public static function serverError($message)
    {
        return self::error(500, 'Server error: ' . $message);
    }

    public static function redirect($url)
    {
        return new self(302, null, $url);
    }

    public static function raw($status, $content, array $headers = [])
    {
        return new self($status, null, null, $headers, $content);
    }

    /**
     * Un archivo del disco como cuerpo. Se lee recién en send(), por partes.
     */
    public static function archivo($status, $ruta, array $headers = [])
    {
        return new self($status, null, null, $headers, null, $ruta);
    }

    /**
     * Copia un archivo a un destino de a bloques.
     *
     * Un archivo de 50 MB no puede pasar entero por la memoria de PHP: con
     * file_get_contents el límite de memoria lo corta, y aunque no lo corte,
     * el comprador no recibe nada hasta que se terminó de leer.
     *
     * @param resource $destino
     * @param bool     $vaciar  Empujar cada bloque al cliente (flush) al escribirlo
     * @return int|false Bytes copiados, o false si no se pudo abrir el archivo
     */
    public static function copiarPorPartes($ruta, $destino, $vaciar = false)
    {
        $origen = @fopen($ruta, 'rb');

        if ($origen === false) {
            return false;
        }

        $copiados = 0;

        while (!feof($origen)) {
            $bloque = fread($origen, self::BLOQUE);

            if ($bloque === false || $bloque === '') {
                break;
            }

            fwrite($destino, $bloque);
            $copiados += strlen($bloque);

            if ($vaciar) {
                flush();
            }

            if (connection_aborted()) {
                break;
            }
        }

        fclose($origen);

        return $copiados;
    }

    public function isRedirect()
    {
        return $this->redirectUrl !== null;
    }

    /** Escribe la respuesta. Sólo se llama en producción, nunca en tests. */
    public function send()
    {
        if ($this->isRedirect()) {
            header('Location: ' . $this->redirectUrl);
            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->raw !== null) {
            echo $this->raw;
            return;
        }

        if ($this->archivo !== null) {
            // Sin buffers de por medio: con uno activo el archivo entero se
            // juntaría en memoria antes de salir. Y con tiempo de sobra: 50 MB
            // a un celular con mala señal tardan minutos.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            @set_time_limit(3600);

            $salida = fopen('php://output', 'wb');
            self::copiarPorPartes($this->archivo, $salida, true);
            fclose($salida);
            return;
        }

        echo json_encode($this->body);
    }
}
