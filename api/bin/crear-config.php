<?php
/**
 * Crea api/config.php a partir de config.example.php, generando un JWT_SECRET
 * aleatorio en el acto.
 *
 * Lo ejecuta composer después de instalar. Existe porque el secreto de
 * ejemplo está publicado en el repositorio: si una instalación lo dejaba tal
 * cual, cualquiera podía firmar un token válido para cualquier usuario. Ya
 * pasó una vez en producción; que la copia sea segura por defecto evita que
 * vuelva a pasar.
 */

$dir = dirname(__DIR__);
$config = $dir . '/config.php';
$ejemplo = $dir . '/config.example.php';

if (file_exists($config)) {
    exit(0); // Ya configurado: no se toca.
}

if (!file_exists($ejemplo)) {
    fwrite(STDERR, "No se encontró config.example.php\n");
    exit(1);
}

$contenido = file_get_contents($ejemplo);

$contenido = preg_replace(
    "/define\('JWT_SECRET',\s*'[^']*'\);/",
    "define('JWT_SECRET', '" . bin2hex(random_bytes(32)) . "');",
    $contenido,
    1,
    $cantidad
);

// La clave que cifra las contraseñas SMTP de las páginas: la del ejemplo
// también es pública.
$contenido = preg_replace(
    "/define\('CLAVE_CIFRADO',\s*'[^']*'\);/",
    "define('CLAVE_CIFRADO', '" . bin2hex(random_bytes(32)) . "');",
    $contenido,
    1,
    $cantidadCifrado
);

file_put_contents($config, $contenido);

echo "config.php creado";
echo $cantidad === 1
    ? " con un JWT_SECRET aleatorio.\n"
    : ". ATENCIÓN: no se pudo generar el JWT_SECRET, cargalo a mano.\n";
if ($cantidadCifrado !== 1) {
    echo "ATENCIÓN: no se pudo generar CLAVE_CIFRADO, cargala a mano.\n";
}
echo "Faltan las credenciales de base de datos y las claves de OAuth.\n";
