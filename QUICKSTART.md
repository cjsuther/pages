# Inicio Rápido - Gestor de Páginas Personales

## Instalación Rápida (5 minutos)

### 1. Base de Datos

```bash
mysql -u root -p < database.sql
```

### 2. Configurar Backend

```bash
cd api
cp config.example.php config.php
nano config.php  # Edita las credenciales de tu base de datos
```

Cambia estos valores:

```php
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
define('JWT_SECRET', 'genera_una_clave_aleatoria_aqui');
```

### 3. Configurar Frontend

```bash
cd frontend
npm install
```

Edita `src/App.jsx` y cambia la URL de la API (línea 8):

```javascript
const API_URL = 'http://localhost:8000/api';  // Para desarrollo local
```

### 4. Ejecutar (Desarrollo)

**Terminal 1 - Backend:**

```bash
cd api
php -S localhost:8000
```

**Terminal 2 - Frontend:**

```bash
cd frontend
npm run dev
```

Abre tu navegador en: `http://localhost:3000`

### 5. Producción

**Backend:** Copia la carpeta `api` a tu servidor web (Apache/Nginx)

**Frontend:**

```bash
cd frontend
npm run build
```

Copia el contenido de `frontend/dist/*` a tu servidor web.

## Primeros Pasos

1. Ve a `/register` y crea una cuenta
2. Inicia sesión con tu email y contraseña
3. Crea tu primera página con un título y URL personalizada
4. Agrega grupos de links y organiza tus enlaces
5. Personaliza los colores de tu página
6. Comparte tu página en `/tu-url`

## Estructura de URLs

- `/login` - Iniciar sesión
- `/register` - Registrarse
- `/dashboard` - Panel de control
- `/page/:id` - Editar página
- `/:slug` - Ver página pública

## Solución Rápida de Problemas

**Error de conexión a BD:**
- Verifica credenciales en `api/config.php`
- Asegúrate de que MySQL esté corriendo

**Errores 404:**
- Habilita `mod_rewrite` en Apache
- Verifica que el `.htaccess` esté presente

**Errores de CORS:**
- Verifica los headers en `api/config.php`

## URLs de la API

Todas las rutas de la API están en: `http://tu-servidor/api/`

- `/api/auth/register.php` - Registro
- `/api/auth/login.php` - Login
- `/api/pages/` - Gestión de páginas
- `/api/groups/` - Gestión de grupos
- `/api/links/` - Gestión de links
- `/api/public/page.php?slug=X` - Ver página pública

Para más detalles, consulta el archivo `README.md`
