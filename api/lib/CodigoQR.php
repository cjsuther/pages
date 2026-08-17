<?php

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Genera el QR de una entrada.
 *
 * Apunta a la página pública de la orden en lugar de contener sólo el código:
 * así quien controla la puerta ve el estado real —pagada, vencida— al
 * escanearlo, en vez de un texto suelto que no dice si sigue siendo válido.
 */
class CodigoQR
{
    const TAMANO = 300;

    /** true si el servidor puede generar imágenes. */
    public static function disponible()
    {
        return class_exists(Builder::class) && extension_loaded('gd');
    }

    /**
     * PNG del QR, en binario.
     *
     * @return string|null null si el servidor no puede generarlo
     */
    public static function png($contenido)
    {
        if (!self::disponible()) {
            return null;
        }

        // La librería genera un QR igual con el contenido vacío. Uno que no
        // lleva a ningún lado confunde más que no poner ninguno.
        if (trim((string) $contenido) === '') {
            return null;
        }

        try {
            // Corrección alta: el QR se lee de una pantalla, muchas veces con
            // brillo bajo, reflejos o el vidrio rayado.
            $resultado = Builder::create()
                ->writer(new PngWriter())
                ->data($contenido)
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(self::TAMANO)
                ->margin(10)
                ->build();

            return $resultado->getString();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** URL pública de una orden, que es lo que lleva el QR. */
    public static function urlDeLaOrden($codigo)
    {
        return rtrim(FRONTEND_URL, '/') . '/entrada/' . $codigo;
    }
}
