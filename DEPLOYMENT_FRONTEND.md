# Deployment del Frontend con Tags OG Dinámicos

## Cómo Funciona

El sistema utiliza un enfoque híbrido que combina:
- **PHP** para renderizar dinámicamente los meta tags Open Graph según la página solicitada
- **React (Vite)** para la aplicación SPA que se carga después

### Flujo de Funcionamiento

1. **Servidor recibe request** → Apache/Nginx envía todas las rutas a `index.php` (gracias al `.htaccess`)
2. **index.php analiza la URL** → Extrae el slug de la página (ej: `/nombre-usuario`)
3. **Llama a la API** → Hace un request a `/api/public/page.php?slug=nombre-usuario`
4. **Renderiza HTML con meta tags** → Genera el HTML con los meta tags OG dinámicos basados en los datos de la página
5. **Carga la aplicación React** → El navegador ejecuta el JavaScript y React toma el control

### Ventajas

- Los bots de redes sociales (Facebook, Twitter, WhatsApp) ven los meta tags correctos
- Google indexa el contenido correctamente con SEO optimizado
- La experiencia de usuario sigue siendo una SPA rápida
- Sin necesidad de Server-Side Rendering (SSR) complejo

## Estructura de Archivos

```
frontend/
├── public/
│   ├── .htaccess              # Reglas de reescritura de URLs
│   └── index.php              # Punto de entrada que renderiza meta tags
├── dist/                      # Carpeta generada por el build
│   ├── .vite/
│   │   └── manifest.json      # Mapa de archivos compilados
│   ├── assets/
│   │   ├── main-[hash].js     # JavaScript compilado
│   │   └── main-[hash].css    # CSS compilado
│   └── index.php              # Copia del index.php con meta tags
├── src/                       # Código fuente React
└── vite.config.js             # Configuración de Vite
```

## Build y Deployment

### 1. Instalar Dependencias

```bash
cd php-mysql-version/frontend
npm install
```

### 2. Construir para Producción

```bash
npm run build
```

Este comando:
- Compila el código React con Vite
- Genera archivos optimizados en `dist/`
- Crea un `manifest.json` con los nombres de los archivos
- Copia `index.php` y otros archivos públicos a `dist/`

### 3. Subir a Servidor

Sube todo el contenido de la carpeta `dist/` a tu servidor web:

```bash
# Estructura en el servidor
tu-dominio.com/
├── index.php
├── .htaccess
├── logo.png
├── .vite/
│   └── manifest.json
└── assets/
    ├── main-[hash].js
    └── main-[hash].css
```

### 4. Configurar Variables de Entorno

El `index.php` necesita saber dónde está la API:

```php
$apiUrl = getenv('API_URL') ?: 'https://tu-dominio.com';
```

Opciones:
- Configura la variable `API_URL` en tu servidor
- O edita directamente el archivo después del build

## Configuración del Servidor

### Apache

El archivo `.htaccess` ya está configurado:

```apache
RewriteEngine On
RewriteBase /

# Redirige todo a index.php excepto archivos existentes
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]

DirectoryIndex index.php index.html
```

### Nginx

Si usas Nginx, agrega esta configuración:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}

location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
}
```

## Desarrollo Local

### Modo Desarrollo (SPA sin meta tags dinámicos)

```bash
npm run dev
```

Esto inicia Vite en modo desarrollo en `http://localhost:3000`

### Modo Desarrollo con PHP (meta tags dinámicos)

1. Construye el proyecto:
```bash
npm run build
```

2. Inicia un servidor PHP en la carpeta dist:
```bash
cd dist
php -S localhost:8080
```

3. Accede a `http://localhost:8080`

## Verificación de Meta Tags

### Probar meta tags en local

```bash
curl http://localhost:8080/tu-slug | grep "og:"
```

### Probar en producción

Usa las herramientas de las redes sociales:
- Facebook: https://developers.facebook.com/tools/debug/
- Twitter: https://cards-dev.twitter.com/validator
- LinkedIn: https://www.linkedin.com/post-inspector/

## Actualizar el Frontend

Cada vez que hagas cambios:

1. Modifica el código en `src/`
2. Ejecuta `npm run build`
3. Sube los archivos de `dist/` al servidor

Los archivos tienen hash en el nombre, así que no hay problemas de caché.

## Troubleshooting

### Los meta tags no se actualizan

- Verifica que `index.php` pueda conectarse a la API
- Revisa el log de errores de PHP
- Prueba la API directamente: `/api/public/page.php?slug=tu-slug`

### La aplicación React no carga

- Verifica que el `manifest.json` exista en `.vite/manifest.json`
- Confirma que los archivos JS y CSS estén en `assets/`
- Revisa la consola del navegador

### Rutas 404

- Confirma que `.htaccess` esté en la carpeta raíz
- Verifica que `mod_rewrite` esté habilitado en Apache
- Para Nginx, revisa la configuración del `try_files`

### CORS errors

- Verifica que la API tenga los headers CORS correctos
- El `.htaccess` ya incluye CORS para assets estáticos
