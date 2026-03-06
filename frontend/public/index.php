<?php
header('Content-Type: text/html; charset=UTF-8');

$apiUrl = getenv('API_URL') ?: ($_SERVER['HTTP_HOST'] === 'localhost' ? 'http://localhost:8000' : 'https://' . $_SERVER['HTTP_HOST']);
$slug = null;
$pageData = null;

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$pathSegments = array_filter(explode('/', $path));

if (count($pathSegments) > 0) {
    $potentialSlug = end($pathSegments);
    if ($potentialSlug && !strpos($potentialSlug, '.') && $potentialSlug !== 'index.php') {
        $slug = $potentialSlug;
    }
}

if ($slug) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '/api/public/page.php?slug=' . urlencode($slug));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['page'])) {
            $pageData = $data['page'];
        }
    }
}

$title = $pageData ? htmlspecialchars($pageData['title']) . ' | Rezonar' : 'Rezonar - Crea tu Espacio, Comparte Eventos, Conecta';
$description = $pageData && $pageData['description']
    ? htmlspecialchars($pageData['description'])
    : 'Rezonar te permite centralizar tus enlaces, compartir eventos con ubicación en mapa y conectar con tu comunidad. Crea tu página personalizada gratis.';
$ogImage = $pageData && ($pageData['profile_image'] || $pageData['background_image'])
    ? htmlspecialchars($pageData['profile_image'] ?: $pageData['background_image'])
    : '';
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

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
</body>
</html>
