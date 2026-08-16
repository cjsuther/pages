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

    private function __construct($status, $body = null, $redirectUrl = null, array $headers = [], $raw = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->redirectUrl = $redirectUrl;
        $this->headers = $headers;
        $this->raw = $raw;
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

        echo json_encode($this->body);
    }
}
