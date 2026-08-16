<?php

/**
 * Representa una petición HTTP entrante de forma que los handlers puedan
 * construirse en tests sin tocar superglobales.
 */
class Request
{
    /** @var string */
    public $method;

    /** @var array Cuerpo JSON ya decodificado (array vacío si no hay o es inválido) */
    public $body;

    /** @var array Equivalente a $_GET */
    public $query;

    /** @var array|null Payload del JWT, o null si no hay sesión válida */
    public $user;

    /** @var array Equivalente a $_FILES */
    public $files;

    /** @var array Cabeceras HTTP tal como las devuelve getallheaders() */
    public $headers;

    /**
     * @var array Equivalente a $_POST. Sólo lo usa el callback de Apple, que
     *            responde con response_mode=form_post en lugar de JSON.
     */
    public $form;

    public function __construct(
        $method = 'GET',
        array $body = [],
        array $query = [],
        $user = null,
        array $files = [],
        array $headers = [],
        array $form = []
    ) {
        $this->method = strtoupper($method);
        $this->body = $body;
        $this->query = $query;
        $this->user = $user;
        $this->files = $files;
        $this->headers = $headers;
        $this->form = $form;
    }

    /**
     * Construye la petición desde las superglobales. Sólo se usa en producción.
     */
    public static function fromGlobals($user = null)
    {
        $raw = file_get_contents('php://input');
        $body = $raw === '' || $raw === false ? [] : json_decode($raw, true);

        if (!is_array($body)) {
            $body = [];
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];

        return new self(
            isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            $body,
            $_GET,
            $user,
            $_FILES,
            is_array($headers) ? $headers : [],
            $_POST
        );
    }

    /** Valor de un campo de formulario ($_POST). */
    public function formInput($key, $default = null)
    {
        return isset($this->form[$key]) ? $this->form[$key] : $default;
    }

    /** Cabecera por nombre, sin distinguir mayúsculas. */
    public function header($name, $default = null)
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Token crudo de la cabecera Authorization, sin el prefijo "Bearer ".
     * Devuelve null (o cadena vacía) si no vino nada utilizable.
     *
     * Algunos endpoints distinguen "no mandaste token" de "tu token no vale"
     * y responden con mensajes distintos, así que la presencia del token se
     * consulta aparte de $this->user.
     */
    public function bearerToken()
    {
        $valor = $this->header('Authorization');

        if ($valor === null) {
            return null;
        }

        return str_replace('Bearer ', '', $valor);
    }

    /** true si llegó un token no vacío, sea válido o no. */
    public function hasBearerToken()
    {
        $token = $this->bearerToken();

        return $token !== null && $token !== '';
    }

    /** Valor del cuerpo JSON. */
    public function input($key, $default = null)
    {
        return isset($this->body[$key]) ? $this->body[$key] : $default;
    }

    /** Valor del query string. */
    public function param($key, $default = null)
    {
        return isset($this->query[$key]) ? $this->query[$key] : $default;
    }

    /** true si la clave existe en el cuerpo (aunque sea null, igual que isset()). */
    public function has($key)
    {
        return isset($this->body[$key]);
    }

    /** ID del usuario autenticado, o null. */
    public function userId()
    {
        return isset($this->user['user_id']) ? $this->user['user_id'] : null;
    }

    /**
     * Devuelve las claves de $keys que faltan en el cuerpo.
     * Útil para replicar las validaciones "X, Y and Z are required".
     */
    public function missing(array $keys)
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!isset($this->body[$key])) {
                $missing[] = $key;
            }
        }
        return $missing;
    }
}
