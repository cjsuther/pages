<?php

/**
 * Los envíos de mails de una página a sus clientes (#37).
 *
 * Un envío congela el mensaje y la lista de destinatarios al crearse; después
 * lo va mandando ProcesadorDeEnvios, por ráfagas.
 *
 * Los destinatarios salen siempre de Clientes::dePagina, la misma consulta de
 * la pantalla de Clientes: nunca se acepta un email que no sea cliente de
 * esta página, ni uno que se dio de baja.
 */
class EnviosAClientes
{
    /** Destinatarios por envío, como mucho. */
    const TOPE = 5000;

    /** Filas por INSERT al guardar la lista. */
    const POR_INSERT = 500;

    const ESTADOS = ['en_curso', 'pausado', 'terminado', 'cancelado'];

    // ------------------------------------------------------- destinatarios

    /**
     * A quiénes les llega, según lo que se eligió en Clientes.
     *
     * @param array $seleccion
     *   ['modo' => 'emails', 'emails' => [...]] los elegidos uno por uno, o
     *   ['modo' => 'filtros', 'filtros' => [...], 'excluidos' => [...]] todos
     *   los que cumplen los filtros de la pantalla, menos los destildados.
     * @return array ['error' => string] o
     *   ['destinatarios' => [['email', 'nombre', 'telefono']], 'de_baja' => int, 'ignorados' => int]
     */
    public static function destinatarios($db, $pageId, $seleccion)
    {
        $modo = is_array($seleccion) && isset($seleccion['modo']) ? $seleccion['modo'] : null;

        if ($modo === 'filtros') {
            $filtros = isset($seleccion['filtros']) && is_array($seleccion['filtros']) ? $seleccion['filtros'] : [];
            $excluidos = self::conjunto(isset($seleccion['excluidos']) ? $seleccion['excluidos'] : []);
            $pedidos = null;
        } elseif ($modo === 'emails') {
            $filtros = [];
            $excluidos = [];
            $pedidos = self::conjunto(isset($seleccion['emails']) ? $seleccion['emails'] : []);

            if (count($pedidos) === 0) {
                return ['error' => 'No elegiste a nadie'];
            }
        } else {
            return ['error' => 'Falta elegir a quién mandarle'];
        }

        $clientes = Clientes::dePagina($db, $pageId, $filtros)['clientes'];
        $bajas = BajasDeCorreo::dePagina($db, $pageId);
        $destinatarios = [];
        $deBaja = 0;
        $encontrados = 0;

        foreach ($clientes as $c) {
            $clave = BajasDeCorreo::clave($c['email']);

            if ($pedidos !== null) {
                if (!isset($pedidos[$clave])) {
                    continue;
                }
                $encontrados++;
            }

            if (isset($excluidos[$clave]) || isset($destinatarios[$clave]) || !self::esEmail($c['email'])) {
                continue;
            }

            if (isset($bajas[$clave])) {
                $deBaja++;
                continue;
            }

            $destinatarios[$clave] = [
                'email' => trim($c['email']),
                'nombre' => mb_substr((string) $c['nombre'], 0, 255),
                'telefono' => mb_substr((string) $c['telefono'], 0, 50),
            ];
        }

        return [
            'destinatarios' => array_values($destinatarios),
            'de_baja' => $deBaja,
            // Los que mandó el navegador y no son clientes de la página.
            'ignorados' => $pedidos === null ? 0 : count($pedidos) - $encontrados,
        ];
    }

    /**
     * Marca en el listado de Clientes a quienes se dieron de baja, y cuenta a
     * cuántos se les puede escribir: es el N de "Seleccionar los N".
     */
    public static function marcarBajas($db, $pageId, array $listado)
    {
        $bajas = BajasDeCorreo::dePagina($db, $pageId);
        $contactables = 0;

        foreach ($listado['clientes'] as $i => $c) {
            $baja = isset($bajas[BajasDeCorreo::clave($c['email'])]);
            $listado['clientes'][$i]['baja'] = $baja;
            $listado['clientes'][$i]['puede_recibir'] = !$baja && self::esEmail($c['email']);
            $contactables += $listado['clientes'][$i]['puede_recibir'] ? 1 : 0;
        }

        $listado['resumen']['contactables'] = $contactables;

        return $listado;
    }

    // -------------------------------------------------------------- crear

    /**
     * Guarda el envío con su mensaje y su lista, todo junto o nada.
     *
     * @param array      $mensaje       asunto, cuerpo, reemplazo_nombre (validados)
     * @param array|null $plantilla     id y nombre del mensaje guardado del que salió
     * @param array      $destinatarios Lo que devolvió destinatarios()
     * @return int El id del envío
     */
    public static function crear($db, $pageId, $userId, array $mensaje, $plantilla, array $destinatarios)
    {
        $db->beginTransaction();

        try {
            $stmt = $db->prepare('
                INSERT INTO envios (page_id, mensaje_id, mensaje_nombre, asunto, cuerpo, reemplazo_nombre, creado_por, total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                (int) $pageId,
                $plantilla ? (int) $plantilla['id'] : null,
                $plantilla ? $plantilla['nombre'] : null,
                $mensaje['asunto'],
                $mensaje['cuerpo'],
                $mensaje['reemplazo_nombre'],
                $userId === null ? null : (int) $userId,
                count($destinatarios),
            ]);
            $envioId = (int) $db->lastInsertId();

            foreach (array_chunk($destinatarios, self::POR_INSERT) as $tanda) {
                $valores = [];

                foreach ($tanda as $d) {
                    array_push($valores, $envioId, $d['email'], $d['nombre'] === '' ? null : $d['nombre'],
                        $d['telefono'] === '' ? null : $d['telefono']);
                }

                $stmt = $db->prepare('
                    INSERT INTO envio_destinatarios (envio_id, email, nombre, telefono) VALUES '
                    . implode(', ', array_fill(0, count($tanda), '(?, ?, ?, ?)'))
                );
                $stmt->execute($valores);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $envioId;
    }

    // ------------------------------------------------------------- listar

    public static function dePagina($db, $pageId)
    {
        $stmt = $db->prepare('
            SELECT e.id, e.mensaje_nombre, e.asunto, e.estado, e.motivo_pausa, e.total, e.creado_en, e.terminado_en,
                   COALESCE(SUM(d.estado = \'enviado\'), 0) AS enviados,
                   COALESCE(SUM(d.estado = \'fallido\'), 0) AS fallidos,
                   COALESCE(SUM(d.estado = \'pendiente\'), 0) AS pendientes,
                   COALESCE(SUM(d.estado = \'omitido\'), 0) AS omitidos
            FROM envios e
            LEFT JOIN envio_destinatarios d ON d.envio_id = e.id
            WHERE e.page_id = ?
            GROUP BY e.id
            ORDER BY e.id DESC
            LIMIT 100
        ');
        $stmt->execute([(int) $pageId]);

        return array_map([self::class, 'paraMostrar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Un envío de la página con sus números, o null si no es de esta página. */
    public static function uno($db, $pageId, $envioId)
    {
        $stmt = $db->prepare('
            SELECT e.id, e.mensaje_nombre, e.asunto, e.cuerpo, e.reemplazo_nombre, e.estado, e.motivo_pausa,
                   e.total, e.creado_en, e.terminado_en,
                   COALESCE(SUM(d.estado = \'enviado\'), 0) AS enviados,
                   COALESCE(SUM(d.estado = \'fallido\'), 0) AS fallidos,
                   COALESCE(SUM(d.estado = \'pendiente\'), 0) AS pendientes,
                   COALESCE(SUM(d.estado = \'omitido\'), 0) AS omitidos
            FROM envios e
            LEFT JOIN envio_destinatarios d ON d.envio_id = e.id
            WHERE e.id = ? AND e.page_id = ?
            GROUP BY e.id
        ');
        $stmt->execute([(int) $envioId, (int) $pageId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila) {
            return null;
        }

        $envio = self::paraMostrar($fila);
        $envio['cuerpo'] = $fila['cuerpo'];
        $envio['reemplazo_nombre'] = $fila['reemplazo_nombre'];

        return $envio;
    }

    /** Los que fallaron o se omitieron, con el motivo. */
    public static function problemas($db, $envioId)
    {
        $stmt = $db->prepare("
            SELECT email, nombre, estado, error, enviado_en
            FROM envio_destinatarios
            WHERE envio_id = ? AND estado IN ('fallido', 'omitido')
            ORDER BY id
            LIMIT 1000
        ");
        $stmt->execute([(int) $envioId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------- estado

    /**
     * Pausar, reanudar o cancelar.
     *
     * @return array ['error' => string] o ['estado' => string]
     */
    public static function cambiarEstado($db, array $envio, $accion)
    {
        $id = (int) $envio['id'];
        $estado = $envio['estado'];

        if ($accion === 'pausar') {
            if ($estado !== 'en_curso') {
                return ['error' => 'Sólo se puede pausar un envío en curso'];
            }
            $db->prepare("UPDATE envios SET estado = 'pausado', motivo_pausa = NULL WHERE id = ? AND estado = 'en_curso'")
                ->execute([$id]);

            return ['estado' => 'pausado'];
        }

        if ($accion === 'reanudar') {
            if ($estado !== 'pausado') {
                return ['error' => 'Sólo se puede reanudar un envío pausado'];
            }
            $db->prepare("UPDATE envios SET estado = 'en_curso', motivo_pausa = NULL WHERE id = ? AND estado = 'pausado'")
                ->execute([$id]);

            return ['estado' => 'en_curso'];
        }

        if ($accion === 'cancelar') {
            if (!in_array($estado, ['en_curso', 'pausado'], true)) {
                return ['error' => 'Este envío ya terminó'];
            }
            $db->prepare("UPDATE envios SET estado = 'cancelado', terminado_en = NOW() WHERE id = ? AND estado IN ('en_curso', 'pausado')")
                ->execute([$id]);
            $db->prepare("UPDATE envio_destinatarios SET estado = 'omitido', error = 'Se canceló el envío' WHERE envio_id = ? AND estado = 'pendiente'")
                ->execute([$id]);

            return ['estado' => 'cancelado'];
        }

        return ['error' => 'La acción tiene que ser pausar, reanudar o cancelar'];
    }

    // ------------------------------------------------------------ interno

    private static function paraMostrar(array $fila)
    {
        return [
            'id' => (int) $fila['id'],
            'mensaje_nombre' => $fila['mensaje_nombre'],
            'asunto' => $fila['asunto'],
            'estado' => $fila['estado'],
            'motivo_pausa' => $fila['motivo_pausa'],
            'total' => (int) $fila['total'],
            'enviados' => (int) $fila['enviados'],
            'fallidos' => (int) $fila['fallidos'],
            'pendientes' => (int) $fila['pendientes'],
            'omitidos' => (int) $fila['omitidos'],
            'creado_en' => $fila['creado_en'],
            'terminado_en' => $fila['terminado_en'],
        ];
    }

    /** @return array<string, true> */
    private static function conjunto($emails)
    {
        $conjunto = [];

        if (!is_array($emails)) {
            return $conjunto;
        }

        foreach ($emails as $email) {
            if (is_string($email) && trim($email) !== '') {
                $conjunto[BajasDeCorreo::clave($email)] = true;
            }
        }

        return $conjunto;
    }

    private static function esEmail($email)
    {
        $email = trim((string) $email);

        return $email !== '' && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
