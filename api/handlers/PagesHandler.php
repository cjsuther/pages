<?php

/**
 * Páginas: listado y alta (index), lectura/edición/borrado (detail).
 *
 * Extraído de api/pages/index.php y api/pages/detail.php sin cambios de
 * comportamiento.
 */
class PagesHandler
{
    /**
     * Slugs que no pueden usarse porque chocan con rutas del frontend o de la API.
     * Público para que el frontend y los tests compartan una única fuente.
     */
    public static $reservedSlugs = [
        'login', 'register', 'dashboard', 'page', 'api', 'admin', 'auth',
        'public', 'pages', 'groups', 'links', 'user', 'users', 'config',
        'settings', 'logout', 'profile', 'account',
    ];

    private static $defaults = [
        'description' => '',
        'primary_color' => '#3B82F6',
        'secondary_color' => '#1E40AF',
        'background_color' => '#FFFFFF',
        'text_color' => '#000000',
    ];

    /** 'nullable' replica array_key_exists(): permite vaciar el campo. */
    private static $updatableFields = [
        'title' => ['nullable' => false],
        'description' => ['nullable' => false],
        'primary_color' => ['nullable' => false],
        // Nullable los tres: vaciarlos es volver al valor derivado —botones con
        // el acento, tarjeta calculada del fondo, títulos con el color del
        // texto—, que es lo que hacían antes de ser configurables.
        'secondary_color' => ['nullable' => true],
        'card_color' => ['nullable' => true],
        'title_color' => ['nullable' => true],
        'background_color' => ['nullable' => false],
        'text_color' => ['nullable' => false],
        'profile_image' => ['nullable' => true],
        'background_image' => ['nullable' => true],
        'template' => ['nullable' => false],
        // Nullable: vaciarlo es la forma de dejar de publicar un contacto.
        'email_contacto' => ['nullable' => true],
    ];

    // ------------------------------------------------------------------ index

    public static function index($db, Request $req)
    {
        if ($req->method === 'GET') {
            return self::listar($db, $req);
        }

        if ($req->method === 'POST') {
            return self::crear($db, $req);
        }

        return Response::methodNotAllowed();
    }

    /** Páginas propias más aquellas donde el usuario es administrador aceptado. */
    /** Páginas por tanda. Con muchas importadas, traerlas todas no escala. */
    const POR_PAGINA = 12;
    const MAX_POR_PAGINA = 50;

    private static function listar($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        try {
            $usuario = $req->userId();
            $busqueda = trim((string) $req->param('q', ''));
            $porPagina = self::acotar((int) $req->param('por_pagina', self::POR_PAGINA), 1, self::MAX_POR_PAGINA);
            $pagina = max(1, (int) $req->param('pagina', 1));

            // Quién ve qué: el dueño y los administradores aceptados.
            $acceso = 'p.user_id = ?
                       OR EXISTS (
                           SELECT 1 FROM page_admins pa
                           WHERE pa.page_id = p.id AND pa.user_id = ? AND pa.status = "accepted"
                       )';
            $params = [$usuario, $usuario];

            $filtro = '';

            if ($busqueda !== '') {
                // Por título y por slug: uno busca por lo que ve o por la URL
                // que recuerda.
                $filtro = ' AND (p.title LIKE ? OR p.url_slug LIKE ?)';
                $comodin = '%' . $busqueda . '%';
                $params[] = $comodin;
                $params[] = $comodin;
            }

            $conteo = $db->prepare("SELECT COUNT(*) FROM pages p WHERE ($acceso)$filtro");
            $conteo->execute($params);
            $total = (int) $conteo->fetchColumn();

            $paginas = (int) ceil($total / $porPagina);

            // Pedir la página 9 de 3 devolvería vacío y parecería que no hay
            // nada; se acota a la última con resultados.
            if ($paginas > 0 && $pagina > $paginas) {
                $pagina = $paginas;
            }

            $offset = ($pagina - 1) * $porPagina;

            $stmt = $db->prepare("
                SELECT p.*, (p.user_id = ?) AS is_owner
                FROM pages p
                WHERE ($acceso)$filtro
                ORDER BY p.created_at DESC
                LIMIT $porPagina OFFSET $offset
            ");
            $stmt->execute(array_merge([$usuario], $params));

            return Response::ok([
                'pages' => $stmt->fetchAll(),
                'paginacion' => [
                    'pagina'      => $pagina,
                    'por_pagina'  => $porPagina,
                    'total'       => $total,
                    'paginas'     => $paginas,
                    'busqueda'    => $busqueda,
                ],
            ]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function acotar($valor, $minimo, $maximo)
    {
        return max($minimo, min($maximo, $valor));
    }

    private static function crear($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->missing(['title', 'url_slug'])) {
            return Response::error(400, 'Title and URL slug are required');
        }

        $urlSlug = self::normalizarSlug($req->input('url_slug'));

        if ($urlSlug === '') {
            return Response::error(400, 'Invalid URL slug');
        }

        if (self::esReservado($urlSlug)) {
            return Response::error(400, 'This URL is reserved and cannot be used');
        }

        try {
            $stmt = $db->prepare('SELECT id FROM pages WHERE url_slug = ?');
            $stmt->execute([$urlSlug]);

            if ($stmt->fetch()) {
                return Response::error(400, 'URL slug already exists');
            }

            $stmt = $db->prepare('INSERT INTO pages (user_id, title, description, url_slug, primary_color, secondary_color, background_color, text_color, profile_image, background_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $req->userId(),
                $req->input('title'),
                $req->input('description', self::$defaults['description']),
                $urlSlug,
                $req->input('primary_color', self::$defaults['primary_color']),
                $req->input('secondary_color', self::$defaults['secondary_color']),
                $req->input('background_color', self::$defaults['background_color']),
                $req->input('text_color', self::$defaults['text_color']),
                $req->input('profile_image'),
                $req->input('background_image'),
            ]);

            $pageId = $db->lastInsertId();

            $stmt = $db->prepare('SELECT * FROM pages WHERE id = ?');
            $stmt->execute([$pageId]);

            return Response::created(['page' => $stmt->fetch()]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ----------------------------------------------------------------- detail

    public static function detail($db, Request $req)
    {
        $pageId = (int) $req->param('id');

        if (!$pageId) {
            return Response::error(400, 'Page ID is required');
        }

        if ($req->method === 'GET') {
            return self::ver($db, $req, $pageId);
        }

        if ($req->method === 'PUT') {
            return self::update($db, $req, $pageId);
        }

        if ($req->method === 'DELETE') {
            return self::destroy($db, $req, $pageId);
        }

        return Response::methodNotAllowed();
    }

    /** Página completa con sus grupos, links y colaboraciones, para el editor. */
    private static function ver($db, Request $req, $pageId)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        try {
            if (!PageAccess::canManage($db, $pageId, $req->userId())) {
                return Response::notFound('Page not found');
            }

            $stmt = $db->prepare('SELECT * FROM pages WHERE id = ?');
            $stmt->execute([$pageId]);
            $page = $stmt->fetch();

            $stmt = $db->prepare('SELECT * FROM link_groups WHERE page_id = ? ORDER BY position, id');
            $stmt->execute([$pageId]);
            $groups = $stmt->fetchAll();

            foreach ($groups as &$group) {
                if ($group['type'] == 'eventos') {
                    $group['links'] = self::linksDeEvento($db, $group['id']);
                    $group['collaborated_events'] = self::eventosColaborados($db, $group['id']);
                } else {
                    $stmt = $db->prepare("SELECT *, (event_date <> '0000-00-00' AND event_date < CURDATE()) as event_due FROM links WHERE group_id = ? ORDER BY position, id");
                    $stmt->execute([$group['id']]);
                    $group['links'] = $stmt->fetchAll();
                }
            }
            unset($group);

            if (is_array($page)) {
                $page['groups'] = $groups;
                $page['socials'] = Redes::deLaPagina($db, $pageId);
            }

            return Response::ok(['page' => $page]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    private static function linksDeEvento($db, $groupId)
    {
        $stmt = $db->prepare("SELECT *, (event_date <> '0000-00-00' AND event_date < CURDATE()) as event_due FROM links WHERE group_id = ? ORDER BY event_date, id");
        $stmt->execute([$groupId]);
        $links = $stmt->fetchAll();

        foreach ($links as &$link) {
            $collabStmt = $db->prepare('
                SELECT ec.id, ec.status, ec.collaborator_page_id,
                    p.title as page_title, p.url_slug as page_slug, p.profile_image as page_image
                FROM event_collaborations ec
                JOIN pages p ON ec.collaborator_page_id = p.id
                WHERE ec.link_id = ?
                ORDER BY ec.created_at
            ');
            $collabStmt->execute([$link['id']]);
            $link['collaborations'] = $collabStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($link);

        return $links;
    }

    /** Eventos de otras páginas que este grupo aceptó colaborar. */
    private static function eventosColaborados($db, $groupId)
    {
        $stmt = $db->prepare("
            SELECT l.*, ec.id as collaboration_id, ec.requester_page_id,
                rp.title as source_page_title, rp.url_slug as source_page_slug, rp.profile_image as source_page_image,
                (l.event_date <> '0000-00-00' AND l.event_date < CURDATE()) as event_due
            FROM event_collaborations ec
            JOIN links l ON ec.link_id = l.id
            JOIN pages rp ON ec.requester_page_id = rp.id
            WHERE ec.collaborator_group_id = ? AND ec.status = 'accepted'
            ORDER BY l.event_date, l.id
        ");
        $stmt->execute([$groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function update($db, Request $req, $pageId)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        try {
            if (!PageAccess::canManage($db, $pageId, $req->userId())) {
                return Response::notFound('Page not found');
            }

            $fields = [];
            $values = [];

            // El dominio propio no entra en la lista genérica: se normaliza
            // —lo que se guarda tiene que ser idéntico al Host de la visita— y
            // no puede estar tomado por otra página.
            if (array_key_exists('dominio', $req->body)) {
                $error = self::asignarDominio($db, $req->body['dominio'], $pageId, $fields, $values);

                if ($error !== null) {
                    return $error;
                }
            }

            foreach (self::$updatableFields as $campo => $opciones) {
                if ($opciones['nullable']) {
                    if (!array_key_exists($campo, $req->body)) {
                        continue;
                    }
                    $valor = $req->body[$campo];
                    $fields[] = $campo . ' = ?';
                    $values[] = ($valor === '' || $valor === null) ? null : $valor;
                    continue;
                }

                if (!$req->has($campo)) {
                    continue;
                }
                $fields[] = $campo . ' = ?';
                $values[] = $req->body[$campo];
            }

            // Las redes se sincronizan aparte: no son columnas de `pages`.
            $tieneRedes = is_array($req->input('socials'));

            if (empty($fields) && !$tieneRedes) {
                return Response::error(400, 'No fields to update');
            }

            if (!empty($fields)) {
                $values[] = $pageId;
                $stmt = $db->prepare('UPDATE pages SET ' . implode(', ', $fields) . ' WHERE id = ?');
                $stmt->execute($values);
            }

            if ($tieneRedes) {
                Redes::reemplazar($db, $pageId, $req->input('socials'));
            }

            $stmt = $db->prepare('SELECT * FROM pages WHERE id = ?');
            $stmt->execute([$pageId]);
            $page = $stmt->fetch();

            if (is_array($page)) {
                $page['socials'] = Redes::deLaPagina($db, $pageId);
            }

            return Response::ok(['page' => $page]);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    /** Borrar es exclusivo del dueño: un administrador aceptado no puede. */
    private static function destroy($db, Request $req, $pageId)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        try {
            $stmt = $db->prepare('DELETE FROM pages WHERE id = ? AND user_id = ?');
            $stmt->execute([$pageId, $req->userId()]);

            if ($stmt->rowCount() === 0) {
                return Response::notFound('Page not found');
            }

            return Response::ok(['message' => 'Page deleted successfully']);

        } catch (Exception $e) {
            return Response::serverError($e->getMessage());
        }
    }

    // ------------------------------------------------------------- utilidades

    /** Deja sólo minúsculas, dígitos y guiones. */
    /**
     * Valida el dominio propio y lo suma a la actualización.
     *
     * @return Response|null Un error para devolver, o null si está todo bien.
     */
    private static function asignarDominio($db, $valor, $pageId, array &$fields, array &$values)
    {
        // Vaciarlo es la forma de dejar de usar un dominio propio.
        if ($valor === null || trim((string) $valor) === '') {
            $fields[] = 'dominio = ?';
            $values[] = null;

            return null;
        }

        $dominio = Dominio::normalizar($valor);

        if ($dominio === null) {
            return Response::error(400, 'Ese dominio no se entiende. Escribilo como maxipeque.com');
        }

        if (Dominio::esPropio($dominio)) {
            return Response::error(400, 'Ese dominio es de Rezonar y no se puede usar como dominio propio');
        }

        // El índice único de la base ya lo impediría, pero ahí el error sale
        // como una falla de base de datos y no dice qué pasó.
        $stmt = $db->prepare('SELECT id FROM pages WHERE dominio = ? AND id <> ?');
        $stmt->execute([$dominio, $pageId]);

        if ($stmt->fetch()) {
            return Response::error(400, 'Ese dominio ya está asignado a otra página');
        }

        $fields[] = 'dominio = ?';
        $values[] = $dominio;

        return null;
    }

    public static function normalizarSlug($slug)
    {
        return preg_replace('/[^a-z0-9-]/', '', strtolower((string) $slug));
    }

    public static function esReservado($slug)
    {
        return in_array($slug, self::$reservedSlugs, true);
    }
}
