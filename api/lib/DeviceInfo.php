<?php

/**
 * Identifica plataforma y marca a partir del User-Agent.
 *
 * Se usa sólo para segmentar métricas y para elegir la guía de batería que
 * corresponde (GUIA-PUSH-PWA.md §7 y §8). Nunca para decidir si hay soporte:
 * desde iOS 26 Apple congela la versión en el User-Agent a propósito, así que
 * cualquier condicional basado en versión es incorrecto (§2.5).
 */
class DeviceInfo
{
    /** Marcas de Android con ahorro de batería agresivo (guía §8). */
    private static $marcas = [
        'Xiaomi'   => '/XiaoMi|MI \d|Redmi|POCO/i',
        'Samsung'  => '/SM-[A-Z]|SAMSUNG|GT-/i',
        'Motorola' => '/Moto |motorola|XT\d{4}/i',
        'Huawei'   => '/HUAWEI|Honor/i',
        'Oppo'     => '/OPPO|CPH\d/i',
        'Realme'   => '/RMX\d|realme/i',
    ];

    /** @return string iOS | Android | Desktop */
    public static function plataforma($userAgent)
    {
        $ua = (string) $userAgent;

        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'iOS';
        }

        if (preg_match('/Android/i', $ua)) {
            return 'Android';
        }

        return 'Desktop';
    }

    /** @return string|null Marca del dispositivo, o null si no se reconoce. */
    public static function marca($userAgent)
    {
        $ua = (string) $userAgent;

        foreach (self::$marcas as $marca => $patron) {
            if (preg_match($patron, $ua)) {
                return $marca;
            }
        }

        return null;
    }

    /**
     * En iOS sólo Safari puede instalar la PWA: Chrome, Firefox y Edge usan el
     * motor de Safari pero no instalan de forma confiable (guía §2.3).
     */
    public static function esSafariEnIOS($userAgent)
    {
        $ua = (string) $userAgent;

        if (!preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return true; // Fuera de iOS la pregunta no aplica.
        }

        return !preg_match('/CriOS|FxiOS|EdgiOS|OPiOS|Chrome/i', $ua)
            && preg_match('/Safari/i', $ua) === 1;
    }
}
