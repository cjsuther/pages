# SEO y Mejoras Sugeridas

## Mejoras de SEO para Páginas Públicas

### Meta Tags (Implementar en PublicPage.jsx)

```javascript
// Agregar en el useEffect después de cargar la página
useEffect(() => {
  if (page) {
    document.title = `${page.title} - Página Personal`;

    // Meta description
    const metaDescription = document.querySelector('meta[name="description"]');
    if (metaDescription) {
      metaDescription.setAttribute('content', page.description);
    } else {
      const meta = document.createElement('meta');
      meta.name = 'description';
      meta.content = page.description;
      document.head.appendChild(meta);
    }

    // Open Graph tags para redes sociales
    const setOGTag = (property, content) => {
      let tag = document.querySelector(`meta[property="${property}"]`);
      if (!tag) {
        tag = document.createElement('meta');
        tag.setAttribute('property', property);
        document.head.appendChild(tag);
      }
      tag.setAttribute('content', content);
    };

    setOGTag('og:title', page.title);
    setOGTag('og:description', page.description);
    setOGTag('og:type', 'website');
    setOGTag('og:url', window.location.href);
  }
}, [page]);
```

### Schema.org (JSON-LD)

Agregar datos estructurados para mejor SEO:

```javascript
const schemaData = {
  "@context": "https://schema.org",
  "@type": "Person",
  "name": page.title,
  "description": page.description,
  "url": window.location.href,
  "sameAs": page.groups?.flatMap(g =>
    g.links?.map(l => l.url).filter(url =>
      url.includes('linkedin.com') ||
      url.includes('twitter.com') ||
      url.includes('facebook.com') ||
      url.includes('instagram.com')
    )
  )
};

// Insertar en el head
const script = document.createElement('script');
script.type = 'application/ld+json';
script.text = JSON.stringify(schemaData);
document.head.appendChild(script);
```

### URLs Amigables

El sistema ya usa URLs amigables: `/mi-pagina`

Para mejorar:
1. Usa slugs descriptivos: `juan-desarrollador-web` en lugar de `juan123`
2. Evita números y caracteres especiales
3. Usa guiones para separar palabras

### Velocidad de Carga

**Frontend:**
```bash
# Compilar con optimización
npm run build

# Los archivos en dist/ estarán minificados
```

**Backend:**
- Usa caché de MySQL
- Habilita compresión GZIP en Apache
- Usa CDN para imágenes

**Apache (.htaccess):**
```apache
# Habilitar compresión
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>

# Cache de archivos estáticos
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpg "access plus 1 year"
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

## Mejoras de Rendimiento

### 1. Implementar Caché en Backend

```php
// En api/public/page.php - agregar caché simple
$cacheFile = "/tmp/page_cache_{$urlSlug}.json";
$cacheTime = 300; // 5 minutos

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    echo file_get_contents($cacheFile);
    exit;
}

// ... resto del código para obtener la página

// Guardar en caché
file_put_contents($cacheFile, json_encode(['page' => $page]));
```

### 2. Lazy Loading de Imágenes

En PublicPage.jsx:

```javascript
<img
  src={link.image_url}
  alt={link.text}
  loading="lazy"
  className="w-16 h-16 object-cover rounded-lg shadow-md"
/>
```

### 3. Optimización de Consultas SQL

```sql
-- Crear índices adicionales para mejorar rendimiento
CREATE INDEX idx_page_slug ON pages(url_slug);
CREATE INDEX idx_group_page_position ON link_groups(page_id, position);
CREATE INDEX idx_link_group_position ON links(group_id, position);
```

### 4. Paginación de Dashboard

Si un usuario tiene muchas páginas:

```php
// En api/pages/index.php
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare('SELECT * FROM pages WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
$stmt->execute([$user['user_id'], $perPage, $offset]);
```

## Mejoras de Funcionalidad

### 1. Arrastrar y Soltar (Drag & Drop)

Usar librería como `react-beautiful-dnd`:

```bash
npm install react-beautiful-dnd
```

### 2. Analíticas Básicas

Agregar tabla en la base de datos:

```sql
CREATE TABLE page_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);
```

Endpoint para registrar visitas:

```php
// api/public/track.php
$pageId = $_POST['page_id'];
$ipAddress = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'];

$stmt = $db->prepare('INSERT INTO page_views (page_id, ip_address, user_agent) VALUES (?, ?, ?)');
$stmt->execute([$pageId, $ipAddress, $userAgent]);
```

### 3. Compartir en Redes Sociales

Agregar botones en PublicPage.jsx:

```javascript
const shareUrls = {
  facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(window.location.href)}`,
  twitter: `https://twitter.com/intent/tweet?url=${encodeURIComponent(window.location.href)}&text=${encodeURIComponent(page.title)}`,
  linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(window.location.href)}`,
  whatsapp: `https://wa.me/?text=${encodeURIComponent(page.title + ' ' + window.location.href)}`
};
```

### 4. Tema Claro/Oscuro

Agregar campo en tabla pages:

```sql
ALTER TABLE pages ADD COLUMN theme VARCHAR(10) DEFAULT 'light';
```

Implementar toggle en PageEditor y aplicar en PublicPage.

### 5. Códigos QR

Usar librería para generar QR:

```bash
npm install qrcode.react
```

```javascript
import QRCode from 'qrcode.react';

<QRCode value={`https://tudominio.com/${page.url_slug}`} />
```

### 6. Exportar a PDF

```bash
npm install jspdf html2canvas
```

```javascript
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';

const exportToPDF = async () => {
  const element = document.getElementById('page-content');
  const canvas = await html2canvas(element);
  const imgData = canvas.toDataURL('image/png');
  const pdf = new jsPDF();
  pdf.addImage(imgData, 'PNG', 0, 0);
  pdf.save(`${page.url_slug}.pdf`);
};
```

### 7. Previsualización antes de Publicar

Agregar campo en tabla:

```sql
ALTER TABLE pages ADD COLUMN published BOOLEAN DEFAULT TRUE;
```

Agregar toggle en PageEditor para publicar/despublicar.

### 8. Plantillas Predefinidas

Crear templates de colores:

```javascript
const colorTemplates = [
  {
    name: "Profesional",
    primary: "#2563EB",
    secondary: "#64748B",
    background: "#FFFFFF",
    text: "#1E293B"
  },
  {
    name: "Creativo",
    primary: "#EC4899",
    secondary: "#8B5CF6",
    background: "#FDF4FF",
    text: "#701A75"
  },
  // ... más templates
];
```

### 9. Búsqueda de Iconos

Integrar con Font Awesome o similar:

```bash
npm install @fortawesome/react-fontawesome
```

Permitir que cada link tenga un icono además de imagen.

### 10. Notificaciones

Agregar sistema de notificaciones para:
- Confirmación de acciones
- Errores
- Éxitos

```bash
npm install react-hot-toast
```

## Mejoras de Seguridad

### 1. Rate Limiting

Agregar límite de peticiones:

```php
// api/auth/login.php
$ip = $_SERVER['REMOTE_ADDR'];
$attempts = getLoginAttempts($ip); // Implementar función

if ($attempts > 5) {
    http_response_code(429);
    echo json_encode(['error' => 'Demasiados intentos. Intenta en 15 minutos.']);
    exit();
}
```

### 2. Validación de URLs

```php
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

### 3. Sanitización de Entrada

```php
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}
```

### 4. HTTPS

Forzar HTTPS en .htaccess:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 5. Headers de Seguridad

En config.php:

```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

## Mejoras de UX

### 1. Tooltips

Agregar tooltips explicativos en el editor.

### 2. Atajos de Teclado

Implementar shortcuts como:
- Ctrl+S para guardar
- Esc para cerrar modales

### 3. Autoguardado

Guardar cambios automáticamente cada X segundos.

### 4. Vista Previa en Vivo

Mostrar preview al lado del editor mientras se edita.

### 5. Historial de Cambios

Tabla para versiones:

```sql
CREATE TABLE page_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);
```

### 6. Búsqueda de Páginas

En Dashboard, agregar barra de búsqueda:

```javascript
const filteredPages = pages.filter(page =>
  page.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
  page.url_slug.toLowerCase().includes(searchTerm.toLowerCase())
);
```

### 7. Ordenar por Drag & Drop

Permitir reordenar grupos y links arrastrando.

### 8. Duplicar Páginas

Botón para clonar una página existente.

### 9. Importar/Exportar

Exportar configuración de página a JSON e importar.

### 10. Vista de Estadísticas

Dashboard con:
- Total de páginas
- Total de enlaces
- Páginas más vistas
- Últimas visitas

## Integraciones Sugeridas

1. **Google Analytics** - Tracking de visitas
2. **Facebook Pixel** - Remarketing
3. **Mailchimp** - Newsletter
4. **Stripe** - Monetización
5. **Cloudinary** - Gestión de imágenes
6. **Zapier** - Automatizaciones
7. **Slack** - Notificaciones
8. **Discord** - Comunidad
9. **Telegram** - Bot de notificaciones
10. **Email** - Notificaciones por correo

Estas mejoras pueden implementarse gradualmente según las necesidades del proyecto.
