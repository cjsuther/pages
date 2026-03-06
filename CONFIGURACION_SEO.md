# Configuración de SEO y Meta Tags Dinámicas

## Problema Resuelto

Las meta tags de Open Graph (og:title, og:description, og:image) no se actualizaban dinámicamente porque los scrapers de redes sociales (Facebook, Twitter, LinkedIn, etc.) no ejecutan JavaScript. Solo leen el HTML inicial del servidor.

## Solución Implementada

Se creó un sistema de Server-Side Rendering (SSR) ligero usando PHP que:

1. **Detecta la página solicitada** - Analiza la URL para identificar el slug
2. **Obtiene los datos de la API** - Consulta la API para obtener la información de la página
3. **Genera HTML dinámico** - Sirve el index.html con meta tags específicas para cada página
4. **Mantiene la SPA funcional** - React se carga normalmente después del HTML inicial

## Archivos Involucrados

### 1. `/frontend/public/index.php`
Archivo PHP que reemplaza el index.html estático. Se ejecuta en el servidor y:
- Detecta el slug de la URL
- Consulta la API para obtener los datos de la página
- Genera las meta tags dinámicamente con los datos correctos
- Sirve el HTML inicial con las meta tags correctas

### 2. `/frontend/public/.htaccess`
Configuración de Apache que:
- Redirige todas las peticiones a index.php
- Establece index.php como archivo por defecto
- Mantiene CORS para assets estáticos

### 3. `/frontend/vite.config.js`
Configuración de build actualizada para:
- Copiar archivos de la carpeta `public/` al `dist/`
- Incluir index.php y .htaccess en el build

## Configuración del Servidor

### Requisitos
- PHP 7.0 o superior
- Apache con mod_rewrite habilitado
- Extensión cURL de PHP habilitada

### Configuración de la URL de la API

El sistema detecta automáticamente la URL de la API:

1. **Desarrollo**: Usa `http://localhost:8000` por defecto
2. **Producción**: Usa el mismo dominio del frontend con HTTPS
3. **Personalizada**: Define la variable de entorno `API_URL`

#### Opción 1: Variable de Entorno (Recomendado)

En tu configuración de Apache (VirtualHost o .htaccess):

```apache
SetEnv API_URL "https://api.tudominio.com"
```

#### Opción 2: Edición Manual

Editar `/dist/index.php` línea 4 y cambiar:

```php
$apiUrl = getenv('API_URL') ?: 'https://tudominio.com/api';
```

**IMPORTANTE**: Si tu API está en un dominio diferente al frontend, debes configurar la URL de la API.

### Estructura de Directorios en Producción

```
/tu-servidor/
├── api/                    # Backend PHP
│   ├── auth/
│   ├── groups/
│   ├── links/
│   ├── pages/
│   └── public/
└── dist/                   # Frontend compilado
    ├── .htaccess          # Redirige a index.php
    ├── index.php          # SSR para meta tags
    ├── index.html         # Template original (no usado)
    └── assets/            # CSS y JS compilados
```

### Pasos de Despliegue

1. **Compilar el frontend**:
   ```bash
   cd frontend
   npm run build
   ```

2. **Copiar archivos al servidor**:
   ```bash
   # Subir la carpeta dist/ al servidor
   # Subir la carpeta api/ al servidor
   ```

3. **Configurar la URL de la API**:
   - Editar `dist/index.php`
   - Cambiar `$apiUrl` a la URL de producción

4. **Verificar permisos**:
   ```bash
   chmod 755 dist/
   chmod 644 dist/.htaccess
   chmod 644 dist/index.php
   ```

5. **Probar la configuración**:
   - Acceder a una página pública: `https://tudominio.com/tu-slug`
   - Ver el código fuente (Ctrl+U) y verificar que las meta tags tengan el contenido correcto

## Verificación

### Probar Meta Tags Localmente

1. Ver el código fuente de una página pública
2. Buscar las meta tags `og:title`, `og:description`, `og:image`
3. Verificar que contengan los datos específicos de esa página

### Probar con Herramientas de Redes Sociales

- **Facebook**: https://developers.facebook.com/tools/debug/
- **Twitter**: https://cards-dev.twitter.com/validator
- **LinkedIn**: https://www.linkedin.com/post-inspector/

Introducir la URL de tu página y verificar que las meta tags se lean correctamente.

## Notas Importantes

1. **Cache de Redes Sociales**: Facebook y otras redes sociales cachean las meta tags. Usa las herramientas de depuración para limpiar el caché.

2. **Desarrollo Local**: Durante el desarrollo con `npm run dev`, el sistema usa el index.html normal. El index.php solo funciona en el build de producción.

3. **Imágenes**: Las URLs de imágenes en `og:image` deben ser URLs absolutas y públicamente accesibles.

4. **Timeouts**: El index.php tiene un timeout de 5 segundos para las peticiones a la API. Si la API no responde, se muestran las meta tags por defecto.

5. **SEO**: Este sistema también mejora el SEO porque los bots de búsqueda (Google, Bing) ven el contenido correcto en el HTML inicial.

## Solución de Problemas

### Las meta tags siguen siendo las por defecto

- Verificar que `.htaccess` esté configurado correctamente
- Verificar que `mod_rewrite` esté habilitado en Apache
- Verificar que la URL de la API en `index.php` sea correcta
- Verificar que la API esté accesible desde el servidor

### Error 500 al acceder a páginas

- Verificar logs de PHP: `tail -f /var/log/apache2/error.log`
- Verificar que la extensión cURL esté instalada: `php -m | grep curl`
- Verificar permisos de archivos

### Las meta tags no se actualizan en redes sociales

- Las redes sociales cachean las meta tags por varios días
- Usar las herramientas de depuración para forzar actualización
- Facebook: Debug Tool
- Twitter: Card Validator

## Alternativas

Si no puedes usar PHP para SSR, otras opciones incluyen:

1. **Prerender.io** - Servicio externo de pre-renderizado
2. **Next.js** - Migrar a Next.js para SSR nativo
3. **Netlify/Vercel Functions** - Serverless functions para SSR
4. **Node.js SSR** - Implementar SSR con Express + React
