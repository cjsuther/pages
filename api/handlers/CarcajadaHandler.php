<?php

/**
 * Carcajada: el alta de comediantes, el armado de shows y la pantalla del QR.
 *
 * Tres públicos distintos y por eso tres niveles de permiso:
 *   - comediante: entra con su cuenta de Rezonar y toca sólo lo suyo
 *   - productor:  arma los shows y evalúa
 *   - cualquiera: la pantalla que abre el QR durante el show
 */
class CarcajadaHandler
{
    // ------------------------------------------------------------ comediante

    /**
     * Los datos del comediante que está entrando.
     *
     * En el GET viaja además lo que se sabe de su página de Rezonar, para no
     * pedirle de nuevo el nombre, la foto ni el Instagram que ya cargó ahí.
     */
    public static function comediante($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if ($req->method === 'GET') {
            return Response::ok([
                'comediante' => Carcajada::comedianteDelUsuario($db, $req->userId()),
                'sugerencia' => Carcajada::sugerenciaDesdeRezonar($db, $req->userId()),
                'productor'  => Carcajada::esProductor($db, $req->userId()),
            ]);
        }

        if ($req->method === 'POST' || $req->method === 'PUT') {
            $resultado = Carcajada::guardarComediante($db, $req->userId(), [
                'page_id'   => $req->input('page_id'),
                'nombre'    => $req->input('nombre'),
                'foto_url'  => $req->input('foto_url'),
                'instagram' => $req->input('instagram'),
                'personas_comprometidas' => $req->input('personas_comprometidas'),
                'estudio_con' => $req->input('estudio_con'),
                'egreso_anio' => $req->input('egreso_anio'),
                'material'    => $req->input('material'),
            ]);

            if (!$resultado['ok']) {
                return Response::error(400, $resultado['error']);
            }

            return Response::ok([
                'success'    => true,
                'comediante' => Carcajada::comedianteDelUsuario($db, $req->userId()),
            ]);
        }

        return Response::methodNotAllowed();
    }

    // ------------------------------------------------------------- productor

    /**
     * El listado de comediantes con su ficha, y el alta o la corrección de
     * los que no tienen cuenta (POST crea, PUT con ?id corrige).
     */
    public static function comediantes($db, Request $req)
    {
        $error = self::exigirProductor($db, $req);

        if ($error !== null) {
            return $error;
        }

        if ($req->method === 'GET') {
            return Response::ok(['comediantes' => Carcajada::fichas($db)]);
        }

        if ($req->method === 'POST' || $req->method === 'PUT') {
            $id = $req->method === 'PUT' ? (int) $req->param('id') : null;

            if ($req->method === 'PUT' && !$id) {
                return Response::error(400, 'id requerido');
            }

            $resultado = Carcajada::guardarInvitado($db, $id, self::datosDeInvitado($req));

            if (!$resultado['ok']) {
                return Response::error(400, $resultado['error']);
            }

            return Response::ok(['success' => true, 'id' => $resultado['id'], 'comediantes' => Carcajada::fichas($db)]);
        }

        return Response::methodNotAllowed();
    }

    /** Los shows y los ciclos: listar, crear y editar. */
    public static function shows($db, Request $req)
    {
        $error = self::exigirProductor($db, $req);

        if ($error !== null) {
            return $error;
        }

        if ($req->method === 'GET') {
            return Response::ok([
                'shows'  => Carcajada::shows($db),
                'ciclos' => Carcajada::ciclos($db),
            ]);
        }

        if ($req->method === 'POST' || $req->method === 'PUT') {
            $resultado = Carcajada::guardarShow($db, (int) $req->param('id'), [
                'ciclo_id' => $req->input('ciclo_id'),
                'fecha'    => $req->input('fecha'),
                'hora'     => $req->input('hora'),
                'lugar'    => $req->input('lugar'),
                'notas'    => $req->input('notas'),
            ]);

            if (!$resultado['ok']) {
                return Response::error(400, $resultado['error']);
            }

            return Response::ok(['success' => true, 'show' => Carcajada::show($db, $resultado['id'])]);
        }

        if ($req->method === 'DELETE') {
            $showId = (int) $req->param('id');

            if (!$showId) {
                return Response::error(400, 'id requerido');
            }

            Carcajada::borrarShow($db, $showId);

            return Response::ok(['success' => true]);
        }

        return Response::methodNotAllowed();
    }

    /**
     * Un show: su lineup, a quién se suma o se saca, y cómo le fue a cada uno.
     *
     * Todo lo que se le hace a un show entra por acá con una acción, en vez de
     * abrir un endpoint por cada cosa: son operaciones chicas sobre la misma
     * pantalla.
     */
    public static function show($db, Request $req)
    {
        $error = self::exigirProductor($db, $req);

        if ($error !== null) {
            return $error;
        }

        $showId = (int) $req->param('id');

        if (!$showId) {
            return Response::error(400, 'id requerido');
        }

        if ($req->method === 'GET') {
            $show = Carcajada::show($db, $showId);

            if ($show === null) {
                return Response::notFound('Ese show no existe');
            }

            return Response::ok(['show' => $show, 'comediantes' => Carcajada::fichas($db)]);
        }

        if ($req->method !== 'POST') {
            return Response::methodNotAllowed();
        }

        $accion = (string) $req->input('accion');
        $comedianteId = (int) $req->input('comediante_id');

        switch ($accion) {
            case 'sumar':
                $resultado = Carcajada::sumarAlLineup($db, $showId, $comedianteId);

                if (!$resultado['ok']) {
                    return Response::error(400, $resultado['error']);
                }
                break;

            case 'invitar':
                // Alguien sin cuenta: se da de alta y queda sumado a la fecha.
                $alta = Carcajada::guardarInvitado($db, null, self::datosDeInvitado($req));

                if (!$alta['ok']) {
                    return Response::error(400, $alta['error']);
                }

                Carcajada::sumarAlLineup($db, $showId, $alta['id']);
                break;

            case 'descripcion':
                Carcajada::guardarDescripcion($db, $showId, $req->input('descripcion'));
                break;

            case 'sacar':
                Carcajada::sacarDelLineup($db, $showId, $comedianteId);
                break;

            case 'ordenar':
                $orden = $req->input('orden');

                if (!is_array($orden)) {
                    return Response::error(400, 'orden tiene que ser una lista de comediantes');
                }

                Carcajada::ordenar($db, $showId, $orden);
                break;

            case 'evaluar':
                $resultado = Carcajada::evaluar($db, $showId, $comedianteId, [
                    'puntaje'          => $req->input('puntaje'),
                    'personas_traidas' => $req->input('personas_traidas'),
                    'comentario'       => $req->input('comentario'),
                ]);

                if (!$resultado['ok']) {
                    return Response::error(400, $resultado['error']);
                }
                break;

            default:
                return Response::error(400, 'Acción desconocida');
        }

        return Response::ok([
            'success'     => true,
            'show'        => Carcajada::show($db, $showId),
            'comediantes' => Carcajada::fichas($db),
        ]);
    }

    // --------------------------------------------------------------- público

    /**
     * Lo que ve quien escanea el QR durante el show, o quien abre una fecha.
     *
     * Sin sesión y sin clave: es un cartel en la pared de un bar. Por eso sale
     * sólo lo que hace falta para encontrar a alguien después —cómo se llama,
     * la cara, a dónde seguirlo— y nada de lo que se anota en su ficha.
     *
     * Sin ?id es el show del día (o el próximo); con ?id, esa fecha.
     */
    public static function hoy($db, Request $req)
    {
        if ($req->method !== 'GET') {
            return Response::methodNotAllowed();
        }

        $showId = (int) $req->param('id');

        if ($showId) {
            $publico = Carcajada::showPublico($db, $showId);

            return $publico === null ? Response::notFound('Esa fecha no existe') : Response::ok($publico);
        }

        $enCurso = Carcajada::showEnCurso($db);

        if ($enCurso === null) {
            return Response::ok(['show' => null, 'comediantes' => [], 'otras' => []]);
        }

        return Response::ok($enCurso);
    }

    // -------------------------------------------------------------- internos

    private static function datosDeInvitado(Request $req)
    {
        return [
            'nombre'    => $req->input('nombre'),
            'foto_url'  => $req->input('foto_url'),
            'instagram' => $req->input('instagram'),
        ];
    }

    private static function exigirProductor($db, Request $req)
    {
        if (!$req->user) {
            return Response::unauthorized();
        }

        if (!Carcajada::esProductor($db, $req->userId())) {
            return Response::error(403, 'Esto lo maneja quien produce Carcajada');
        }

        return null;
    }
}
