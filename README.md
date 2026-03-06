# Gestor de Páginas Personales

Sistema completo para crear y gestionar páginas personales con grupos de links personalizables.

## Características

- Sistema de registro y autenticación de usuarios
- Creación de páginas personales con URL personalizada
- Gestión de grupos de links
- Cada link puede tener URL, texto, imagen y descripción
- Customización completa de colores (primario, secundario, fondo, texto)
- Vista pública de las páginas creadas
- Frontend moderno con React
- Backend en PHP con MySQL

## Requisitos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Apache con mod_rewrite habilitado
- Node.js 16 o superior (para desarrollo del frontend)

## Instalación

### 1. Configurar la Base de Datos

1. Crea la base de datos ejecutando el archivo SQL:

```bash
mysql -u root -p < database.sql
```

O importa manualmente desde phpMyAdmin o tu gestor de bases de datos preferido.

2. Edita el archivo `api/config.php` y configura las credenciales de tu base de datos:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_pages');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
define('JWT_SECRET', 'cambia_esto_por_una_clave_secreta_segura');
```

**IMPORTANTE:** Cambia el valor de `JWT_SECRET` por una clave aleatoria y segura.

### 2. Configurar el Backend (PHP)

1. Copia la carpeta `api` a tu servidor web (por ejemplo, en `/var/www/html/api`)

2. Asegúrate de que Apache tenga habilitado `mod_rewrite`:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

3. Verifica que el archivo `.htaccess` en la carpeta `api` esté presente

4. Asegúrate de que la configuración de Apache permita el uso de `.htaccess`. En tu configuración de VirtualHost o en `/etc/apache2/sites-available/000-default.conf`:

```apache
<Directory /var/www/html>
    AllowOverride All
</Directory>
```

5. Reinicia Apache:

```bash
sudo systemctl restart apache2
```

### 3. Configurar el Frontend

1. Ve a la carpeta `frontend`:

```bash
cd frontend
```

2. Instala las dependencias:

```bash
npm install
```

3. Edita el archivo `src/App.jsx` y cambia la URL de la API si es necesario:

```javascript
const API_URL = 'http://tu-servidor.com/api';  // Cambia esto
```

4. Para desarrollo, ejecuta:

```bash
npm run dev
```

5. Para producción, compila el frontend:

```bash
npm run build
```

6. Los archivos compilados estarán en la carpeta `dist`. Copia el contenido a tu servidor web:

```bash
cp -r dist/* /var/www/html/
```

### 4. Configuración de Rutas para React Router

Para que las rutas del frontend funcionen correctamente, crea un archivo `.htaccess` en la raíz de tu servidor web:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /
  RewriteRule ^index\.html$ - [L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_URI} !^/api/
  RewriteRule . /index.html [L]
</IfModule>
```

## Estructura del Proyecto

```
php-mysql-version/
├── database.sql                 # Estructura de la base de datos
├── api/                        # Backend PHP
│   ├── config.php             # Configuración
│   ├── Database.php           # Clase de conexión a BD
│   ├── JWT.php                # Utilidades de autenticación
│   ├── .htaccess              # Configuración de Apache
│   ├── auth/                  # Endpoints de autenticación
│   │   ├── register.php
│   │   └── login.php
│   ├── pages/                 # Endpoints de páginas
│   │   ├── index.php
│   │   └── detail.php
│   ├── groups/                # Endpoints de grupos
│   │   ├── index.php
│   │   └── detail.php
│   ├── links/                 # Endpoints de links
│   │   ├── index.php
│   │   └── detail.php
│   └── public/                # Endpoints públicos
│       └── page.php
└── frontend/                   # Frontend React
    ├── package.json
    ├── vite.config.js
    ├── tailwind.config.js
    ├── index.html
    └── src/
        ├── main.jsx
        ├── App.jsx
        ├── index.css
        └── pages/
            ├── Login.jsx
            ├── Register.jsx
            ├── Dashboard.jsx
            ├── PageEditor.jsx
            └── PublicPage.jsx
```

## API Endpoints

### Autenticación

- `POST /api/auth/register.php` - Registro de usuario
- `POST /api/auth/login.php` - Inicio de sesión

### Páginas (requieren autenticación)

- `GET /api/pages/index.php` - Listar páginas del usuario
- `POST /api/pages/index.php` - Crear nueva página
- `GET /api/pages/detail.php?id={id}` - Obtener página específica
- `PUT /api/pages/detail.php?id={id}` - Actualizar página
- `DELETE /api/pages/detail.php?id={id}` - Eliminar página

### Grupos (requieren autenticación)

- `POST /api/groups/index.php` - Crear grupo
- `PUT /api/groups/detail.php?id={id}` - Actualizar grupo
- `DELETE /api/groups/detail.php?id={id}` - Eliminar grupo

### Links (requieren autenticación)

- `POST /api/links/index.php` - Crear link
- `PUT /api/links/detail.php?id={id}` - Actualizar link
- `DELETE /api/links/detail.php?id={id}` - Eliminar link

### Público (sin autenticación)

- `GET /api/public/page.php?slug={slug}` - Ver página pública

## Uso

1. Registra un nuevo usuario en `/register`
2. Inicia sesión en `/login`
3. Desde el Dashboard, crea una nueva página con:
   - Título
   - Descripción
   - URL personalizada (solo letras, números y guiones)
4. Edita la página para:
   - Cambiar colores personalizados
   - Agregar grupos de links
   - Agregar links dentro de cada grupo (con URL, texto, imagen, descripción)
5. Comparte tu página pública usando la URL: `/{tu-url-personalizada}`

## Seguridad

- Las contraseñas se almacenan hasheadas con bcrypt
- Autenticación mediante JWT
- Validación de entrada en todos los endpoints
- Protección contra SQL injection usando PDO con prepared statements
- Headers CORS configurados
- Verifica que cambies `JWT_SECRET` en producción

## Personalización

### Colores de Página

Cada página puede tener 4 colores personalizables:

- **Color Primario**: Usado en títulos principales
- **Color Secundario**: Usado en títulos de secciones y enlaces
- **Color de Fondo**: Color de fondo de la página
- **Color de Texto**: Color principal del texto

### Estructura de Links

Cada link puede incluir:

- **URL** (requerido): Destino del enlace
- **Texto** (requerido): Texto visible del enlace
- **Imagen** (opcional): URL de una imagen
- **Descripción** (opcional): Texto descriptivo adicional

## Desarrollo

Para trabajar en modo desarrollo:

```bash
# Terminal 1 - Frontend (puerto 3000)
cd frontend
npm run dev

# Terminal 2 - Backend (puerto 8000 con PHP built-in server)
cd api
php -S localhost:8000
```

El proxy de Vite redirigirá las peticiones a `/api` al servidor PHP.

## Solución de Problemas

### Error "Connection Error"

- Verifica las credenciales de base de datos en `api/config.php`
- Asegúrate de que MySQL esté corriendo
- Verifica que la base de datos exista

### Rutas 404 en producción

- Verifica que `mod_rewrite` esté habilitado
- Asegúrate de que el `.htaccess` esté presente
- Verifica que `AllowOverride All` esté configurado en Apache

### Errores de CORS

- Verifica que los headers CORS estén en `config.php`
- Asegúrate de que el archivo `.htaccess` de la API esté presente

### JWT Invalid

- Verifica que el token no haya expirado (24 horas por defecto)
- Asegúrate de que `JWT_SECRET` sea el mismo en el servidor

## Licencia

Este proyecto es de código libre. Puedes usarlo, modificarlo y distribuirlo libremente.
