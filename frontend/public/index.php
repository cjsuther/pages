<?php
header('Content-Type: text/html; charset=UTF-8');

$host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
$apiUrl = getenv('API_URL') ?: ($host === 'localhost' ? 'http://localhost:8000' : 'https://' . $host);
$slug = null;
$pageData = null;

// La página que este dominio propio muestra en su raíz. Se le pasa al SPA para
// que no tenga que volver a preguntarlo.
$slugDelDominio = null;

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$pathSegments = array_filter(explode('/', $path));

// Un dominio propio —maxipeque.com— muestra en su raíz la página que lo tenga
// asignado. Se resuelve sólo en la raíz: en el resto de las rutas la dirección
// ya dice qué mostrar, y consultar de nuevo sería una demora al pedo.
$hostPelado = preg_replace('/^www\./', '', explode(':', $host)[0]);
$esDominioPropio = $hostPelado !== '' && $hostPelado !== 'localhost'
    && $hostPelado !== 'rezon.ar' && substr($hostPelado, -strlen('.rezon.ar')) !== '.rezon.ar';

/** Trae un evento de la API. */
function buscarEvento($apiUrl, $id) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '/api/public/event.php?id=' . urlencode($id));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['event'])) {
            return $data['event'];
        }
    }

    return null;
}

/** Trae una página de la API. $clave es 'slug' o 'dominio'. */
function buscarPagina($apiUrl, $clave, $valor) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '/api/public/page.php?' . $clave . '=' . urlencode($valor));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['page'])) {
            return $data['page'];
        }
    }

    return null;
}

// Compartir un evento tiene que mostrar el evento, no la marca. Sin esto
// /evento/345 tomaba el "345" como si fuera el nombre de una página, no
// encontraba nada y quien lo pegaba en WhatsApp veía el logo de Rezonar.
//
// array_values porque array_filter conserva las claves originales: sin eso los
// índices no empiezan en cero y la comparación de abajo nunca da.
$segmentos = array_values($pathSegments);
$evento = null;

if (count($segmentos) === 2 && $segmentos[0] === 'evento' && ctype_digit($segmentos[1])) {
    $evento = buscarEvento($apiUrl, $segmentos[1]);
}

if ($evento) {
    // El evento ya trae todo: no hace falta buscar la página.
} elseif ($esDominioPropio && count($pathSegments) === 0) {
    $pageData = buscarPagina($apiUrl, 'dominio', $hostPelado);

    if ($pageData && isset($pageData['url_slug'])) {
        $slugDelDominio = $pageData['url_slug'];
    }
} else {
    if (count($pathSegments) > 0) {
        $potentialSlug = end($pathSegments);
        if ($potentialSlug && !strpos($potentialSlug, '.') && $potentialSlug !== 'index.php') {
            $slug = $potentialSlug;
        }
    }

    if ($slug) {
        $pageData = buscarPagina($apiUrl, 'slug', $slug);
    }
}

/** Una línea: en un meta tag los saltos y los espacios de más no aportan. */
function unaLinea($texto) {
    return trim(preg_replace('/\s+/', ' ', (string) $texto));
}

if ($evento) {
    $title = htmlspecialchars(unaLinea($evento['text']))
        . (!empty($evento['page_title']) ? ' | ' . htmlspecialchars(unaLinea($evento['page_title'])) : ' | Rezonar');
    $description = !empty($evento['description'])
        ? htmlspecialchars(unaLinea($evento['description']))
        : 'Mirá los detalles y reservá tu lugar.';
    $ogImage = !empty($evento['image_url']) ? htmlspecialchars($evento['image_url']) : '';
} else {
$title = $pageData ? htmlspecialchars($pageData['title']) . ' | Rezonar' : 'Rezonar - Crea tu Espacio, Comparte Eventos, Conecta';
$description = $pageData && $pageData['description']
    ? htmlspecialchars($pageData['description'])
    : 'Rezonar te permite centralizar tus enlaces, compartir eventos con ubicación en mapa y conectar con tu comunidad. Crea tu página personalizada gratis.';
$ogImage = $pageData && ($pageData['profile_image'] || $pageData['background_image'])
    ? htmlspecialchars($pageData['profile_image'] ?: $pageData['background_image'])
    : '';
}
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$manifestPath = __DIR__ . '/.vite/manifest.json';
$cssFile = '';
$jsFile = '';

if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (isset($manifest['index.html'])) {
        $jsFile = '/' . $manifest['index.html']['file'];
        if (isset($manifest['index.html']['css']) && count($manifest['index.html']['css']) > 0) {
            $cssFile = '/' . $manifest['index.html']['css'][0];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

  <title><?php echo $title; ?></title>
  <meta name="description" content="<?php echo $description; ?>">
  <meta name="keywords" content="páginas personales, enlaces, eventos, mapa, comunidad, linktree, agenda cultural">

  <meta property="og:type" content="<?php echo $pageData ? 'profile' : 'website'; ?>">
  <meta property="og:title" content="<?php echo $title; ?>">
  <meta property="og:description" content="<?php echo $description; ?>">
  <meta property="og:url" content="<?php echo htmlspecialchars($currentUrl); ?>">
  <meta property="og:site_name" content="Rezonar">
  <?php if ($ogImage): ?>
  <meta property="og:image" content="<?php echo $ogImage; ?>">
  <?php endif; ?>

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo $title; ?>">
  <meta name="twitter:description" content="<?php echo $description; ?>">
  <?php if ($ogImage): ?>
  <meta name="twitter:image" content="<?php echo $ogImage; ?>">
  <?php endif; ?>


  <!-- PWA: sin manifiesto con display standalone iOS no permite instalar,
       y sin instalar no existe la API de push (GUIA-PUSH-PWA.md §4.2). -->
  <link rel="manifest" href="/manifest.json">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Rezonar">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="theme-color" content="#000000">

  <meta name="robots" content="index, follow">
  <meta name="author" content="Rezonar">
  <link rel="canonical" href="<?php echo htmlspecialchars($currentUrl); ?>">

  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-3W7JFPFSBL"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
  
    gtag('config', 'G-3W7JFPFSBL');
  </script>

  <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBSRKfHBJK9KscPl89DGBYUMNlDXKJMXOg&libraries=places,marker" async defer></script>
  <script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js" defer></script>
  <?php if ($jsFile && $cssFile): ?>
  <script type="module" crossorigin src="<?php echo $jsFile; ?>"></script>
  <link rel="stylesheet" crossorigin href="<?php echo $cssFile; ?>">
  <?php else: ?>
  <script type="module" src="/src/main.jsx"></script>
  <?php endif; ?>
</head>
<body>
  <div id="root"></div>
  <?php if ($slugDelDominio): ?>
  <!-- Este dominio propio muestra esta página en su raíz. Va acá para que el
       SPA no repita la consulta que index.php ya hizo. -->
  <script>window.__PAGINA_DEL_DOMINIO__ = <?php echo json_encode($slugDelDominio); ?>;</script>
  <?php endif; ?>
</body>
</html>
