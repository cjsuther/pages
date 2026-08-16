<?php

/**
 * Autoload de las clases propias (lib/ y handlers/). Compartido por el
 * bootstrap de producción y el de los tests, para que ambos carguen
 * exactamente los mismos archivos.
 */

spl_autoload_register(function ($class) {
    $candidates = [
        __DIR__ . '/' . $class . '.php',
        __DIR__ . '/../handlers/' . $class . '.php',
        // AppleJWT vive junto a los endpoints de Apple desde antes del refactor.
        __DIR__ . '/../auth/' . $class . '.php',
    ];

    foreach ($candidates as $path) {
        // realpath() canonicaliza el "lib/../handlers/": sin eso el mismo
        // archivo puede quedar registrado con dos rutas distintas y las
        // herramientas que filtran por ruta (como el reporte de cobertura)
        // no lo reconocen.
        $real = realpath($path);

        if ($real !== false) {
            require_once $real;
            return;
        }
    }
});
