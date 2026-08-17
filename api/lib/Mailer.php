<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

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
     * @param array $mensaje {
     *   @type string $para
     *   @type string $paraNombre
     *   @type string $asunto
     *   @type string $html
     *   @type string $texto     Alternativa en texto plano
     *   @type string $responder Reply-To, opcional
     *   @type array  $imagenes  [['cid' => 'qr', 'contenido' => binario, 'tipo' => 'image/png']]
     * }
     * @return array{ok: bool, error: string|null}
     */
    public function enviar(array $mensaje)
    {
        if (!self::disponible()) {
            return ['ok' => false, 'error' => 'El servidor no tiene configurado el envío de correo'];
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = (int) SMTP_PORT;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = (int) SMTP_PORT === 465
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = self::TIMEOUT;
            $mail->CharSet = 'UTF-8';

            // El From tiene que ser la casilla autenticada: si no coincide con
            // lo que el SPF autoriza, el mail se marca como falsificado.
            $mail->setFrom(SMTP_USER, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Rezonar');

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
            return ['ok' => false, 'error' => substr($e->getMessage(), 0, 255)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => substr($e->getMessage(), 0, 255)];
        }
    }
}
