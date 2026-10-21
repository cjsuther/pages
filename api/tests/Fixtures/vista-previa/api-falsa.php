<?php

/**
 * Router de `php -S` que hace de API para los tests de frontend/public/index.php.
 * Contesta lo mínimo que index.php le pide para armar la vista previa.
 */

$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$id = isset($_GET['id']) ? $_GET['id'] : null;

header('Content-Type: application/json');

$productos = [
    '400' => [
        'id' => 400, 'text' => "Guía\nde viaje", 'description' => "Todo lo que\n  hay que saber",
        'image_url' => 'https://img.test/tapa.jpg', 'precio' => 2500.0, 'page_title' => 'La Sala',
    ],
    '401' => [
        'id' => 401, 'text' => 'Mapa', 'description' => '', 'image_url' => null,
        'precio' => 2500.5, 'page_title' => 'La Sala',
    ],
    '402' => [
        'id' => 402, 'text' => 'Pack <gratis>', 'description' => null, 'image_url' => 'https://img.test/pack.jpg',
        'precio' => 0, 'page_title' => 'La Sala',
    ],
];

if ($ruta === '/api/public/producto.php') {
    if (isset($productos[$id])) {
        echo json_encode(['producto' => $productos[$id]]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Este producto ya no está disponible.']);
    }
    return;
}

if ($ruta === '/api/public/event.php' && $id === '300') {
    echo json_encode(['event' => [
        'id' => 300, 'text' => 'El show', 'description' => 'Una noche', 'image_url' => 'https://img.test/afiche.jpg',
        'page_title' => 'La Sala',
    ]]);
    return;
}

// Cualquier página existe: si index.php buscara una página donde no debe, se
// notaría en el título.
if ($ruta === '/api/public/page.php') {
    echo json_encode(['page' => [
        'title' => 'Página ' . (isset($_GET['slug']) ? $_GET['slug'] : ''), 'description' => 'De una página',
        'profile_image' => null, 'background_image' => null, 'url_slug' => 'x',
    ]]);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'No encontrado']);
