<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\SMTP;

/**
 * Envío de correo por SMTP autenticado.
 *
 * Se manda desde una casilla del propio dominio y no con mail(): el SPF de
 * rezon.ar autoriza a los servidores de Hostinger, así que un mail que sale
 * autenticado por ahí llega a la bandeja de entrada. Un remitente que el SPF
 * no respalda termina en spam, y una entrada en spam es una entrada perdida.
 *
 * Los handlers lo reciben por parámetro para que los tests puedan sustituirlo,
 * igual que HttpClient o PushSender.
 */
class Mailer
{
    /**
     * Segundos de espera. Corto a propósito: esto corre dentro del aviso de
     * pago de Mercado Pago, y un SMTP colgado haría que el aviso expire y
     * Mercado Pago lo reintente.
     */
    const TIMEOUT = 10;

    /** true si el servidor está en condiciones de mandar correo. */
    public static function disponible()
    {
        if (!class_exists(PHPMailer::class)) {
            return false;
        }

        foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'] as $constante) {
            if (!defined($constante)) {
                return false;
            }

            $valor = (string) constant($constante);

            if ($valor === '' || strpos($valor, 'PENDIENTE') === 0 || strpos($valor, 'TU_') === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Manda un mail.
     *
     * Sin $cuenta sale por la casilla de la plataforma, que es lo que usan las
     * entradas y los productos. Con $cuenta sale por la casilla SMTP que cargó
     * una página (ver CuentaSmtp), ya validada y con la contraseña descifrada.
     *
     * @param array $mensaje {
     *   @type string $para
     *   @type string $paraNombre
     *   @type string $asunto
     *   @type string $html
     *   @type string $texto     Alternativa en texto plano
     *   @type string $responder Reply-To, opcional
     *   @type array  $imagenes  [['cid' => 'qr', 'contenido' => binario, 'tipo' => 'image/png']]
     * }
     * @param array|null $cuenta {
     *   @type string $host
     *   @type string $ip        Opcional: a dónde conectar. El certificado se valida contra $host.
     *   @type int    $port
     *   @type string $seguridad ssl | tls | ninguna
     *   @type string $usuario
     *   @type string $contrasena
     *   @type string $remitente_email
     *   @type string $remitente_nombre
     * }
     * @return array{ok: bool, error: string|null, motivo?: string}
     */
    public function enviar(array $mensaje, ?array $cuenta = null)
    {
        if ($cuenta === null && !self::disponible()) {
            return ['ok' => false, 'error' => 'El servidor no tiene configurado el envío de correo'];
        }

        if ($cuenta !== null && !class_exists(PHPMailer::class)) {
            return ['ok' => false, 'error' => 'El servidor no tiene instalado el envío de correo', 'motivo' => 'otro'];
        }

        $mail = new PHPMailer(true);
        $diagnostico = [];

        try {
            $mail->isSMTP();
            $mail->Timeout = self::TIMEOUT;
            $mail->CharSet = 'UTF-8';

            if ($cuenta === null) {
                $mail->Host = SMTP_HOST;
                $mail->Port = (int) SMTP_PORT;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
                $mail->SMTPSecure = (int) SMTP_PORT === 465
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;

                // El From tiene que ser la casilla autenticada: si no coincide con
                // lo que el SPF autoriza, el mail se marca como falsificado.
                $mail->setFrom(SMTP_USER, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Rezonar');
            } else {
                $this->usarCuenta($mail, $cuenta, $diagnostico);
            }

            if (!empty($mensaje['responder'])) {
                $mail->addReplyTo($mensaje['responder']);
            }

            $mail->addAddress($mensaje['para'], isset($mensaje['paraNombre']) ? $mensaje['paraNombre'] : '');

            foreach (isset($mensaje['imagenes']) ? $mensaje['imagenes'] : [] as $imagen) {
                // Incrustadas y no adjuntas: Gmail bloquea las imágenes en
                // base64 dentro del HTML, pero muestra las que van por cid.
                $mail->addStringEmbeddedImage(
                    $imagen['contenido'],
                    $imagen['cid'],
                    $imagen['cid'] . '.png',
                    PHPMailer::ENCODING_BASE64,
                    $imagen['tipo']
                );
            }

            $mail->isHTML(true);
            $mail->Subject = $mensaje['asunto'];
            $mail->Body = $mensaje['html'];
            $mail->AltBody = isset($mensaje['texto']) ? $mensaje['texto'] : strip_tags($mensaje['html']);

            $mail->send();

            return ['ok' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            return $this->fallo($e, $cuenta, $diagnostico);
        } catch (Throwable $e) {
            return $this->fallo($e, $cuenta, $diagnostico);
        }
    }

    private function fallo(Throwable $e, $cuenta, array $diagnostico)
    {
        $resultado = ['ok' => false, 'error' => substr($e->getMessage(), 0, 255)];

        if ($cuenta !== null) {
            $resultado['motivo'] = self::motivo($e->getMessage(), $diagnostico);
        }

        return $resultado;
    }

    /** Configura el envío por la casilla de una página. */
    private function usarCuenta(PHPMailer $mail, array $cuenta, array &$diagnostico)
    {
        // Se conecta a la IP que ya se comprobó pública, no al nombre: si se
        // volviera a resolver, un DNS que cambia entre la comprobación y la
        // conexión podría mandarnos a la red interna. El certificado se sigue
        // validando contra el nombre.
        $mail->Host = !empty($cuenta['ip']) ? $cuenta['ip'] : $cuenta['host'];
        $mail->Port = (int) $cuenta['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $cuenta['usuario'];
        $mail->Password = $cuenta['contrasena'];

        if (!empty($cuenta['ip'])) {
            $mail->SMTPOptions = ['ssl' => ['peer_name' => $cuenta['host']]];
        }

        if ($cuenta['seguridad'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cuenta['seguridad'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            // "ninguna" es una elección explícita: sin esto PHPMailer intenta
            // STARTTLS igual y falla con servidores de certificado inválido.
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        // El detalle de la conexión sirve para explicar por qué falló. Lo que
        // manda el cliente queda afuera: ahí viajan usuario y contraseña.
        $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
        $mail->Debugoutput = function ($linea) use (&$diagnostico) {
            if (strpos($linea, 'CLIENT -> SERVER') === false) {
                $diagnostico[] = substr((string) $linea, 0, 500);
            }
        };

        $mail->setFrom(
            $cuenta['remitente_email'],
            isset($cuenta['remitente_nombre']) ? (string) $cuenta['remitente_nombre'] : ''
        );
    }

    /**
     * Por qué falló un envío, para explicárselo a quien cargó la casilla.
     *
     * @param  string   $error       El mensaje de la excepción de PHPMailer
     * @param  string[] $diagnostico Lo que contó la conexión
     * @return string autenticacion | certificado | conexion | otro
     */
    public static function motivo($error, array $diagnostico = [])
    {
        $todo = $error . "\n" . implode("\n", $diagnostico);

        // Primero el certificado: cuando falla, PHPMailer también dice que no
        // pudo conectar, y ése no es el problema a resolver.
        if (preg_match('/certificate|SSL operation failed|did not match expected CN|SSL routines/i', $todo)) {
            return 'certificado';
        }

        if (preg_match('/Could not authenticate|authentication failed|Username and Password not accepted|\b53[45]\b/i', $todo)) {
            return 'autenticacion';
        }

        if (preg_match('/Could not connect|Failed to connect|Connection (refused|timed out)|timed out|getaddrinfo|No route to host/i', $todo)) {
            return 'conexion';
        }

        return 'otro';
    }
}
