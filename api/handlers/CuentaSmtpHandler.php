<?php

/**
 * Configuración → Email de una página (#36): su propia casilla SMTP.
 *
 * La administran el dueño y los administradores, como el resto de la
 * configuración. Ver CuentaSmtp para los cuidados con la contraseña y con el
 * servidor.
 */
class CuentaSmtpHandler
{
    const NO_DISPONIBLE = 'El envío por SMTP propio no está disponible en este momento. Escribinos y lo revisamos.';

    /** GET la muestra, POST/PUT la guarda, DELETE la quita. */
    public static function index($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if (!in_array($req->method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            return Response::methodNotAllowed();
        }

        $pageId = self::pageId($req);

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'No podés configurar el email de esta página');
        }

        // Sin clave no hay dónde guardar la contraseña sin dejarla en claro.
        if (!CuentaSmtp::disponible()) {
            return Response::json(503, ['error' => self::NO_DISPONIBLE, 'disponible' => false]);
        }

        if ($req->method === 'GET') {
            return Response::ok([
                'disponible' => true,
                'cuenta' => CuentaSmtp::paraMostrar(CuentaSmtp::deLaPagina($db, $pageId)),
                'predeterminados' => self::predeterminados($db, $pageId),
            ]);
        }

        if ($req->method === 'DELETE') {
            CuentaSmtp::quitar($db, $pageId);

            return Response::ok(['quitada' => true]);
        }

        $anterior = CuentaSmtp::deLaPagina($db, $pageId);
        $validado = CuentaSmtp::validar($req->body, $anterior);

        if (isset($validado['error'])) {
            return Response::error(400, $validado['error']);
        }

        $datos = $validado['datos'];
        $host = CuentaSmtp::revisarHost($datos['host']);

        if (!$host['ok']) {
            return Response::error(400, $host['error']);
        }

        $cifrada = $datos['contrasena'] === null
            ? $anterior['contrasena_cifrada']
            : CuentaSmtp::cifrar($datos['contrasena']);

        CuentaSmtp::guardar($db, $pageId, $datos, $cifrada);

        return Response::ok([
            'disponible' => true,
            'cuenta' => CuentaSmtp::paraMostrar(CuentaSmtp::deLaPagina($db, $pageId)),
            'predeterminados' => self::predeterminados($db, $pageId),
        ]);
    }

    /**
     * Manda un mail de prueba, con la cuenta guardada, al email de quien lo
     * pide. A esa persona y a nadie más: si no, el botón serviría para
     * mandarle mails a cualquiera.
     */
    public static function prueba($db, Request $req, $mailer = null)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $pageId = self::pageId($req);

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'No podés configurar el email de esta página');
        }

        if (!CuentaSmtp::disponible()) {
            return Response::json(503, ['error' => self::NO_DISPONIBLE, 'disponible' => false]);
        }

        $cuenta = CuentaSmtp::deLaPagina($db, $pageId);

        if ($cuenta === null) {
            return Response::notFound('Primero guardá la cuenta');
        }

        if (CuentaSmtp::pruebasDeLaUltimaHora($db, $pageId) >= CuentaSmtp::PRUEBAS_POR_HORA) {
            return Response::error(429, 'Ya se mandaron ' . CuentaSmtp::PRUEBAS_POR_HORA
                . ' pruebas en la última hora. Probá de nuevo más tarde.');
        }

        $destinatario = self::emailDe($db, $req);

        if ($destinatario === null) {
            return Response::error(400, 'Tu cuenta no tiene un email válido al que mandar la prueba');
        }

        $contrasena = CuentaSmtp::descifrar($cuenta['contrasena_cifrada']);

        if ($contrasena === null) {
            return Response::error(409, 'No pudimos leer la contraseña guardada. Volvé a cargarla y guardá.');
        }

        // Otra vez, y no sólo al guardar: lo que resuelve un nombre puede
        // cambiar después.
        $host = CuentaSmtp::revisarHost($cuenta['host']);

        if (!$host['ok']) {
            return Response::error(400, $host['error']);
        }

        $pagina = self::predeterminados($db, $pageId);
        $titulo = $pagina['remitente_nombre'] !== '' ? $pagina['remitente_nombre'] : 'tu página';
        $pruebaId = CuentaSmtp::anotarPrueba($db, $pageId, $req->userId());

        $mailer = $mailer ?: new Mailer();
        $resultado = $mailer->enviar([
            'para' => $destinatario,
            'asunto' => 'Prueba de envío de ' . $titulo,
            'html' => '<p>¡Funciona! Este es un mail de prueba de <strong>'
                . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8')
                . '</strong>, enviado desde tu propia casilla a través de Rezonar.</p>'
                . '<p>Así les van a llegar los mails a tus clientes.</p>',
            'texto' => "¡Funciona! Este es un mail de prueba de $titulo, enviado desde tu propia casilla a través de Rezonar.\n\n"
                . 'Así les van a llegar los mails a tus clientes.',
            'responder' => $cuenta['responder_a'] ?: ($pagina['responder_a'] ?: null),
        ], [
            'host' => $cuenta['host'],
            'ip' => $host['ip'],
            'port' => (int) $cuenta['port'],
            'seguridad' => $cuenta['seguridad'],
            'usuario' => $cuenta['usuario'],
            'contrasena' => $contrasena,
            'remitente_email' => $cuenta['remitente_email'],
            'remitente_nombre' => $cuenta['remitente_nombre'] ?: $pagina['remitente_nombre'],
        ]);

        if (!$resultado['ok']) {
            $motivo = isset($resultado['motivo']) ? $resultado['motivo'] : 'otro';

            return Response::json(502, [
                'error' => CuentaSmtp::explicar($motivo, $resultado['error'], $cuenta['host'], $cuenta['port']),
                'motivo' => $motivo,
            ]);
        }

        CuentaSmtp::pruebaExitosa($db, $pageId, $pruebaId);

        return Response::ok(['enviado' => true, 'mensaje' => 'Te mandamos un mail de prueba a ' . $destinatario]);
    }

    /**
     * PUT: cómo se reparten los envíos a clientes en ráfagas (#37). Va con la
     * cuenta: sin cuenta no hay envíos que repartir.
     */
    public static function rafagas($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method !== 'PUT') {
            return Response::methodNotAllowed();
        }

        $pageId = self::pageId($req);

        if (!$pageId) {
            return Response::error(400, 'page_id requerido');
        }

        if (!PageAccess::canManage($db, $pageId, $req->userId())) {
            return Response::error(403, 'No podés configurar el email de esta página');
        }

        if (CuentaSmtp::deLaPagina($db, $pageId) === null) {
            return Response::notFound('Primero guardá la cuenta');
        }

        $validado = CuentaSmtp::validarRafagas($req->body);

        if (isset($validado['error'])) {
            return Response::error(400, $validado['error']);
        }

        CuentaSmtp::guardarRafagas($db, $pageId, $validado['datos']);

        return Response::ok(['rafagas' => $validado['datos']]);
    }

    private static function pageId(Request $req)
    {
        $pageId = $req->param('page_id');

        if ($pageId === null) {
            $pageId = $req->input('page_id');
        }

        return is_scalar($pageId) ? (int) $pageId : 0;
    }

    /** Lo que se usa cuando el nombre o "responder a" quedan vacíos. */
    private static function predeterminados($db, $pageId)
    {
        $stmt = $db->prepare('SELECT title, email_contacto FROM pages WHERE id = ?');
        $stmt->execute([(int) $pageId]);
        $pagina = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'remitente_nombre' => isset($pagina['title']) ? (string) $pagina['title'] : '',
            'responder_a' => isset($pagina['email_contacto']) ? (string) $pagina['email_contacto'] : '',
        ];
    }

    /** El email de la cuenta, de la base: el del token puede haber quedado viejo. */
    private static function emailDe($db, Request $req)
    {
        $stmt = $db->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([(int) $req->userId()]);
        $email = $stmt->fetchColumn();

        if (!$email && isset($req->user['email'])) {
            $email = $req->user['email'];
        }

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
