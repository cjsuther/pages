<?php

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Envío de notificaciones push por Web Push + VAPID.
 *
 * Se recibe por parámetro en los handlers para poder sustituirlo en los tests:
 * es la frontera con la red, igual que HttpClient.
 *
 * Ver GUIA-PUSH-PWA.md §6.
 */
class PushSender
{
    /** @var WebPush|null Se crea perezosamente: instanciarlo firma claves. */
    private $webPush = null;

    /**
     * El `sub` del JWT de VAPID tiene que ser una URL https real o un mailto
     * con dominio existente. FCM acepta cualquier cosa; APNs responde 403 sin
     * explicar por qué, así que el síntoma es "funciona en Android y ningún
     * iPhone recibe nada" (guía §2.1).
     */
    public static function subjectValido($subject)
    {
        $subject = (string) $subject;

        if (preg_match('#^https://[^\s/]+\.[^\s/]+#i', $subject)) {
            return true;
        }

        if (!preg_match('/^mailto:[^@\s]+@[^@\s]+\.[^@\s]+$/i', $subject)) {
            return false;
        }

        // Dominios de ejemplo o locales: APNs los rechaza.
        return !preg_match('/(ejemplo|example|localhost|\.local|\.test|\.invalid)/i', $subject);
    }

    /** true si el servidor puede firmar claves VAPID (requiere gmp, guía §3). */
    public static function disponible()
    {
        return class_exists(WebPush::class) && extension_loaded('gmp');
    }

    /**
     * true si la clave pública de la suscripción se puede cifrar.
     *
     * La librería valida esto recién al cifrar, dentro de flush(), y lo hace
     * tirando una excepción que no distingue de qué dispositivo vino. Como
     * flush() cifra toda la tanda junta, un solo registro corrupto impide que
     * se envíe cualquier otra notificación. Se descarta antes de encolar.
     *
     * P-256 sin comprimir: 65 bytes que arrancan con 0x04 (un byte de
     * prefijo más las coordenadas X e Y de 32 bytes cada una).
     */
    public static function claveUtilizable($p256dh)
    {
        $bytes = self::decodificarBase64Url((string) $p256dh);

        return $bytes !== false && strlen($bytes) === 65 && $bytes[0] === "\x04";
    }

    private static function decodificarBase64Url($texto)
    {
        if ($texto === '') {
            return false;
        }

        $normalizado = strtr($texto, '-_', '+/');
        $relleno = strlen($normalizado) % 4;

        if ($relleno) {
            $normalizado .= str_repeat('=', 4 - $relleno);
        }

        return base64_decode($normalizado, true);
    }

    private function cliente()
    {
        if ($this->webPush === null) {
            $this->webPush = new WebPush([
                'VAPID' => [
                    'subject'    => VAPID_SUBJECT,
                    'publicKey'  => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ]);
            // Menos firmas cuando se envía a muchos dispositivos.
            $this->webPush->setReuseVAPIDHeaders(true);
        }

        return $this->webPush;
    }

    /**
     * Encola un envío. No sale a la red hasta llamar a flush().
     *
     * @param array $suscripcion ['endpoint','p256dh_key','auth_key']
     * @param array $payload     Datos que recibe el service worker
     */
    public function encolar(array $suscripcion, array $payload)
    {
        $this->cliente()->queueNotification(
            Subscription::create([
                'endpoint'  => $suscripcion['endpoint'],
                'publicKey' => $suscripcion['p256dh_key'],
                'authToken' => $suscripcion['auth_key'],
            ]),
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Envía todo lo encolado.
     *
     * isSuccess() sólo dice que el servicio de push aceptó el mensaje, no que
     * el teléfono lo recibió: la entrega real la confirma el service worker
     * con un ack (guía §7).
     *
     * @return array Lista de ['endpoint','exito','expirada','motivo']
     */
    public function enviar()
    {
        $resultados = [];

        foreach ($this->cliente()->flush() as $reporte) {
            $resultados[] = [
                'endpoint' => (string) $reporte->getRequest()->getUri(),
                'exito'    => $reporte->isSuccess(),
                // 404 o 410: el dispositivo ya no existe, hay que borrarlo.
                'expirada' => $reporte->isSubscriptionExpired(),
                'motivo'   => $reporte->isSuccess() ? null : substr((string) $reporte->getReason(), 0, 255),
            ];
        }

        return $resultados;
    }
}
