<?php

/**
 * Manda los envíos a clientes por ráfagas (#37). Lo corre un cron cada cinco
 * minutos (api/correos/procesar.php).
 *
 * En cada corrida, cada página con envíos en curso manda como mucho una
 * ráfaga, si ya pasó la pausa desde la anterior y no llegó al tope del día.
 * Entre un mail y el siguiente se esperan uno o dos segundos.
 *
 * Los cuidados para no mandarle dos veces lo mismo a nadie:
 *
 * - Un lock de la base (GET_LOCK): si una corrida se demora y arranca la
 *   siguiente, la segunda no hace nada.
 * - Cada destinatario se marca apenas se le manda, no al final de la ráfaga.
 * - Antes de mandarle se le suma un intento. Si el proceso se corta justo
 *   ahí, queda pendiente con un intento: la próxima corrida no sabe si le
 *   llegó, así que lo da por fallido en vez de mandárselo otra vez.
 *
 * Si la casilla rechaza el usuario o la contraseña, no tiene sentido seguir
 * probando con el resto de la lista: se pausan los envíos de la página y se
 * explica por qué en la pantalla de envíos.
 */
class ProcesadorDeEnvios
{
    const LOCK = 'rezonar_envios_a_clientes';

    /**
     * Segundos de tolerancia en la pausa. El cron corre cada cinco minutos y
     * nunca arranca en el mismo segundo: sin margen, una pausa de diez
     * minutos se estiraría a quince.
     */
    const MARGEN = 30;

    const INTERRUMPIDO = 'Se cortó el proceso mientras se mandaba: puede que haya llegado. No lo reintentamos para no mandarlo dos veces.';

    const SIN_CUENTA = 'La página no tiene una cuenta de email configurada. Cargala en Configuración → Email y reanudá el envío.';

    const SIN_CONTRASENA = 'No pudimos leer la contraseña de la casilla. Volvé a cargarla en Configuración → Email y reanudá el envío.';

    /**
     * Errores con los que no vale la pena seguir ahora, pero que no son culpa
     * del destinatario: el destinatario vuelve a quedar pendiente y la ráfaga
     * se corta. Por ejemplo, Gmail cuando se llega al límite del día.
     */
    const ERROR_PASAJERO = '/quota|limit exceeded|rate limit|too many|try again later|temporarily/i';

    /**
     * @param PDO|object    $db
     * @param Mailer        $mailer
     * @param callable|null $dormir segundos => void; los tests no esperan
     * @return array resumen de la corrida
     */
    public static function correr($db, Mailer $mailer, ?callable $dormir = null)
    {
        $dormir = $dormir ?: function ($segundos) {
            usleep((int) round($segundos * 1000000));
        };

        $resumen = ['bloqueado' => false, 'paginas' => 0, 'enviados' => 0, 'fallidos' => 0, 'omitidos' => 0, 'detalle' => []];

        if ((string) $db->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() !== '1') {
            $resumen['bloqueado'] = true;
            $resumen['detalle'][] = 'Otra corrida sigue en marcha';

            return $resumen;
        }

        try {
            self::pausarSinCuenta($db);

            foreach (self::paginasConEnvios($db) as $fila) {
                if (!self::paso($fila)) {
                    continue;
                }

                $resumen['paginas']++;
                self::rafaga($db, $mailer, $dormir, (int) $fila['page_id'], $resumen);
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }

        return $resumen;
    }

    // ------------------------------------------------------------ páginas

    /** Un envío en curso sin cuenta no puede salir: queda pausado, con el motivo. */
    private static function pausarSinCuenta($db)
    {
        $stmt = $db->prepare("
            UPDATE envios e
            LEFT JOIN page_smtp s ON s.page_id = e.page_id
            SET e.estado = 'pausado', e.motivo_pausa = ?
            WHERE e.estado = 'en_curso' AND s.page_id IS NULL
        ");
        $stmt->execute([self::SIN_CUENTA]);
    }

    private static function paginasConEnvios($db)
    {
        // La hora la da la base, igual que ultima_rafaga_en: así no importa
        // en qué zona horaria corre PHP.
        $stmt = $db->prepare("
            SELECT DISTINCT e.page_id, s.rafaga_pausa_minutos, s.ultima_rafaga_en, NOW() AS ahora
            FROM envios e
            INNER JOIN page_smtp s ON s.page_id = e.page_id
            WHERE e.estado = 'en_curso'
            ORDER BY e.page_id
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** true si ya pasó la pausa desde la última ráfaga de la página. */
    public static function paso(array $fila)
    {
        if (empty($fila['ultima_rafaga_en'])) {
            return true;
        }

        $pausa = max(CuentaSmtp::PAUSA_MIN, (int) $fila['rafaga_pausa_minutos']) * 60;

        return strtotime($fila['ahora']) - strtotime($fila['ultima_rafaga_en']) >= $pausa - self::MARGEN;
    }

    // ------------------------------------------------------------- ráfaga

    private static function rafaga($db, Mailer $mailer, callable $dormir, $pageId, array &$resumen)
    {
        $cuenta = CuentaSmtp::deLaPagina($db, $pageId);

        if ($cuenta === null) {
            self::pausar($db, $pageId, self::SIN_CUENTA);
            return;
        }

        $contrasena = CuentaSmtp::descifrar($cuenta['contrasena_cifrada']);

        if ($contrasena === null) {
            self::pausar($db, $pageId, self::SIN_CONTRASENA);
            $resumen['detalle'][] = "Página $pageId: no se pudo leer la contraseña";
            return;
        }

        // Como en la prueba: lo que resuelve el nombre puede haber cambiado.
        $host = CuentaSmtp::revisarHost($cuenta['host']);

        if (!$host['ok']) {
            self::pausar($db, $pageId, $host['error']);
            $resumen['detalle'][] = "Página $pageId: " . $host['error'];
            return;
        }

        $config = CuentaSmtp::rafagas($cuenta);
        $cupo = $config['tamano'];

        if ($config['tope_diario'] !== null) {
            $cupo = min($cupo, $config['tope_diario'] - self::enviadosEnElDia($db, $pageId));

            if ($cupo <= 0) {
                $resumen['detalle'][] = "Página $pageId: llegó al tope de {$config['tope_diario']} mails por día";
                return;
            }
        }

        $pendientes = self::pendientes($db, $pageId, $cupo);

        if (count($pendientes) === 0) {
            self::cerrarTerminados($db, $pageId);
            return;
        }

        // Desde que empieza: la pausa se cuenta de arranque a arranque.
        $db->prepare('UPDATE page_smtp SET ultima_rafaga_en = NOW() WHERE page_id = ?')->execute([$pageId]);

        $pagina = self::pagina($db, $pageId);
        $bajas = BajasDeCorreo::dePagina($db, $pageId);
        $paraMailer = [
            'host' => $cuenta['host'],
            'ip' => $host['ip'],
            'port' => (int) $cuenta['port'],
            'seguridad' => $cuenta['seguridad'],
            'usuario' => $cuenta['usuario'],
            'contrasena' => $contrasena,
            'remitente_email' => $cuenta['remitente_email'],
            'remitente_nombre' => $cuenta['remitente_nombre'] ?: $pagina['title'],
        ];
        $responder = $cuenta['responder_a'] ?: ($pagina['email_contacto'] ?: null);
        $intentados = 0;

        foreach ($pendientes as $d) {
            $id = (int) $d['id'];

            if ((int) $d['intentos'] > 0) {
                self::marcar($db, $id, 'fallido', self::INTERRUMPIDO);
                $resumen['fallidos']++;
                continue;
            }

            if (isset($bajas[BajasDeCorreo::clave($d['email'])])) {
                self::marcar($db, $id, 'omitido', 'Se dio de baja');
                $resumen['omitidos']++;
                continue;
            }

            // Lo pueden haber pausado o cancelado mientras corría la ráfaga.
            if (!self::sigueEnCurso($db, (int) $d['envio_id'])) {
                continue;
            }

            if ($intentados > 0) {
                call_user_func($dormir, 1 + mt_rand(0, 1000) / 1000);
            }

            $intentados++;
            $db->prepare('UPDATE envio_destinatarios SET intentos = intentos + 1 WHERE id = ?')->execute([$id]);

            $token = BajasDeCorreo::token($pageId, $d['email']);
            $mail = MensajeACliente::armar($d, $d, $pagina['title'], BajasDeCorreo::urlPagina($token));
            $resultado = $mailer->enviar([
                'para' => $d['email'],
                'paraNombre' => (string) $d['nombre'],
                'asunto' => $mail['asunto'],
                'html' => $mail['html'],
                'texto' => $mail['texto'],
                'responder' => $responder,
                'cabeceras' => BajasDeCorreo::cabeceras($token),
            ], $paraMailer);

            if ($resultado['ok']) {
                $db->prepare("UPDATE envio_destinatarios SET estado = 'enviado', enviado_en = NOW(), error = NULL WHERE id = ?")
                    ->execute([$id]);
                $resumen['enviados']++;
                continue;
            }

            $motivo = isset($resultado['motivo']) ? $resultado['motivo'] : 'otro';
            $error = (string) $resultado['error'];

            if ($motivo === 'autenticacion' || $motivo === 'certificado') {
                self::reponer($db, $id);
                self::pausar($db, $pageId, CuentaSmtp::explicar($motivo, $error, $cuenta['host'], $cuenta['port'])
                    . ' Corregilo en Configuración → Email y reanudá el envío.');
                $resumen['detalle'][] = "Página $pageId: pausado por $motivo";
                break;
            }

            if ($motivo === 'conexion' || preg_match(self::ERROR_PASAJERO, $error)) {
                self::reponer($db, $id);
                $resumen['detalle'][] = "Página $pageId: se corta la ráfaga ($error)";
                break;
            }

            self::marcar($db, $id, 'fallido', mb_substr($error, 0, 500));
            $resumen['fallidos']++;
        }

        self::cerrarTerminados($db, $pageId);
    }

    private static function pendientes($db, $pageId, $cupo)
    {
        // Contando también a los que se van a omitir: es un tope, y así una
        // ráfaga nunca recorre más de la cuenta.
        $stmt = $db->prepare("
            SELECT d.id, d.envio_id, d.email, d.nombre, d.intentos, e.asunto, e.cuerpo, e.reemplazo_nombre
            FROM envio_destinatarios d
            INNER JOIN envios e ON e.id = d.envio_id
            WHERE e.page_id = ? AND e.estado = 'en_curso' AND d.estado = 'pendiente'
            ORDER BY e.id, d.id
            LIMIT " . (int) $cupo
        );
        $stmt->execute([$pageId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Los mandados en las últimas 24 horas, de todos los envíos de la página. */
    private static function enviadosEnElDia($db, $pageId)
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM envio_destinatarios d
            INNER JOIN envios e ON e.id = d.envio_id
            WHERE e.page_id = ? AND d.estado = 'enviado' AND d.enviado_en >= NOW() - INTERVAL 1 DAY
        ");
        $stmt->execute([$pageId]);

        return (int) $stmt->fetchColumn();
    }

    private static function pagina($db, $pageId)
    {
        $stmt = $db->prepare('SELECT title, email_contacto FROM pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'title' => isset($fila['title']) ? (string) $fila['title'] : '',
            'email_contacto' => isset($fila['email_contacto']) ? (string) $fila['email_contacto'] : '',
        ];
    }

    private static function sigueEnCurso($db, $envioId)
    {
        $stmt = $db->prepare('SELECT estado FROM envios WHERE id = ?');
        $stmt->execute([$envioId]);
        $estado = $stmt->fetchColumn();

        return $estado === false || $estado === 'en_curso';
    }

    private static function marcar($db, $id, $estado, $error)
    {
        $db->prepare('UPDATE envio_destinatarios SET estado = ?, error = ? WHERE id = ?')->execute([$estado, $error, $id]);
    }

    /** Vuelve a dejarlo pendiente y sin intentos: no le llegó por algo que no es suyo. */
    private static function reponer($db, $id)
    {
        $db->prepare("UPDATE envio_destinatarios SET estado = 'pendiente', intentos = 0 WHERE id = ?")->execute([$id]);
    }

    private static function pausar($db, $pageId, $motivo)
    {
        $stmt = $db->prepare("UPDATE envios SET estado = 'pausado', motivo_pausa = ? WHERE page_id = ? AND estado = 'en_curso'");
        $stmt->execute([mb_substr((string) $motivo, 0, 500), $pageId]);
    }

    private static function cerrarTerminados($db, $pageId)
    {
        $stmt = $db->prepare("
            UPDATE envios SET estado = 'terminado', terminado_en = NOW()
            WHERE page_id = ? AND estado = 'en_curso'
              AND NOT EXISTS (
                  SELECT 1 FROM envio_destinatarios d WHERE d.envio_id = envios.id AND d.estado = 'pendiente'
              )
        ");
        $stmt->execute([$pageId]);
    }
}
