# Guía de Desarrollo Local

Esta guía explica cómo ejecutar el proyecto completo en modo desarrollo, incluyendo la API PHP y el frontend React.

## Requisitos Previos

- PHP 7.0+ con extensiones: pdo, pdo_mysql, curl
- MySQL 5.7+ o MariaDB
- Node.js 16+ y npm
- Apache (opcional, para probar SSR)

## Estructura de Desarrollo

```
php-mysql-version/
├── api/              # Backend PHP
├── frontend/         # Frontend React
│   ├── src/         # Código fuente React
│   └── public/      # Archivos públicos (incluye index.php para SSR)
└── database.sql     # Schema de base de datos
```

## Configuración Inicial (Solo Primera Vez)

### 1. Configurar la Base de Datos

```bash
# Crear base de datos
mysql -u root -p
CREATE DATABASE personal_pages;
exit;

# Importar schema
mysql -u root -p personal_pages < database.sql
```

### 2. Configurar el Backend (API)

```bash
cd api/

# Copiar configuración de ejemplo
cp config.example.php config.php

# Editar config.php con tus credenciales
nano config.php
```

Configurar en `api/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_pages');
define('DB_USER', 'root');
define('DB_PASS', 'tu_password');
define('JWT_SECRET', 'genera-una-clave-secreta-unica');
```

### 3. Configurar el Frontend

```bash
cd frontend/

# Instalar dependencias
npm install

# Copiar configuración de ejemplo
cp .env.example .env

# Editar .env
nano .env
```

Configurar en `frontend/.env`:
```env
VITE_API_URL=http://localhost:8000
```

## Ejecución en Modo Desarrollo

Necesitas **3 terminales** para ejecutar todos los servicios:

### Terminal 1: Servidor PHP (API Backend)

```bash
# Ir a la carpeta api
cd php-mysql-version/api

# Iniciar servidor PHP en puerto 8000
php -S localhost:8000
```

Deberías ver:
```
PHP 8.x Development Server (http://localhost:8000) started
```

La API estará disponible en: **http://localhost:8000**

### Terminal 2: Servidor Frontend (React + Vite)

```bash
# Ir a la carpeta frontend
cd php-mysql-version/frontend

# Iniciar servidor de desarrollo
npm run dev
```

Deberías ver:
```
VITE v5.x.x  ready in xxx ms

➜  Local:   http://localhost:3000/
➜  Network: use --host to expose
```

La aplicación estará disponible en: **http://localhost:3000**

### Terminal 3: MySQL (Si no está corriendo)

```bash
# Solo si MySQL no está corriendo como servicio
sudo service mysql start

# O en macOS con Homebrew
brew services start mysql
```

## URLs en Desarrollo

| Servicio | URL | Descripción |
|----------|-----|-------------|
| Frontend | http://localhost:3000 | Aplicación React |
| Backend API | http://localhost:8000 | API PHP |
| Login | http://localhost:3000/login | Página de login |
| Registro | http://localhost:3000/register | Crear cuenta |
| Dashboard | http://localhost:3000/dashboard | Panel de control |
| Página Pública | http://localhost:3000/tu-slug | Vista pública |

## Limitaciones en Desarrollo

### Meta Tags Dinámicas (Open Graph)

En modo desarrollo con `npm run dev`:
- ✅ Las meta tags funcionan en el navegador (React Helmet)
- ❌ Las meta tags NO funcionan para scrapers de redes sociales (Facebook, Twitter)
- ❌ El index.php NO se ejecuta (Vite sirve index.html)

**Razón**: Vite sirve directamente el `index.html`, no ejecuta PHP. El SSR solo funciona en producción.

### Probar el SSR Localmente

Si necesitas probar las meta tags dinámicas localmente:

#### Opción 1: Build y Servidor PHP

```bash
# Terminal 1: API (igual que antes)
cd php-mysql-version/api
php -S localhost:8000

# Terminal 2: Build y servir con PHP
cd php-mysql-version/frontend
npm run build
cd dist
php -S localhost:3000

# Ahora visita: http://localhost:3000/tu-slug
```

#### Opción 2: Apache Local

```bash
# Hacer build
cd php-mysql-version/frontend
npm run build

# Copiar dist/ a Apache
sudo cp -r dist/* /var/www/html/

# Visitar: http://localhost/tu-slug
```

## Comandos Útiles

### Frontend

```bash
cd php-mysql-version/frontend

# Desarrollo
npm run dev                # Inicia servidor dev

# Build
npm run build             # Compila para producción

# Preview build
npm run preview           # Vista previa del build

# Linting
npm run lint              # Verifica código
```

### Backend

```bash
cd php-mysql-version/api

# Servidor de desarrollo
php -S localhost:8000     # Puerto 8000

# Verificar sintaxis PHP
php -l archivo.php        # Verifica un archivo

# Ver logs de PHP
tail -f /var/log/php_errors.log
```

### Base de Datos

```bash
# Acceder a MySQL
mysql -u root -p

# Ver base de datos
SHOW DATABASES;
USE personal_pages;
SHOW TABLES;

# Exportar backup
mysqldump -u root -p personal_pages > backup.sql

# Importar backup
mysql -u root -p personal_pages < backup.sql

# Ver logs
tail -f /var/log/mysql/error.log
```

## Flujo de Desarrollo Típico

### 1. Iniciar Todos los Servicios

```bash
# Terminal 1: API
cd php-mysql-version/api && php -S localhost:8000

# Terminal 2: Frontend
cd php-mysql-version/frontend && npm run dev

# Terminal 3: Monitorear logs (opcional)
tail -f api/logs/error.log
```

### 2. Desarrollar

- Edita archivos en `frontend/src/` - Vite recarga automáticamente
- Edita archivos en `api/` - Reinicia el servidor PHP si es necesario
- Los cambios en React se ven instantáneamente (HMR)
- Los cambios en PHP requieren guardar y refrescar

### 3. Probar

1. Abre http://localhost:3000
2. Registra una cuenta de prueba
3. Crea una página
4. Accede a tu página pública

### 4. Verificar Funcionalidad

```bash
# Probar endpoint de la API
curl http://localhost:8000/api/public/page.php?slug=tu-slug

# Debería devolver JSON con los datos de la página
```

## Solución de Problemas

### Error: "Connection refused" en la API

**Problema**: El frontend no puede conectarse a la API

**Solución**:
```bash
# Verifica que la API esté corriendo
curl http://localhost:8000

# Verifica la configuración en frontend/.env
cat frontend/.env
# Debe tener: VITE_API_URL=http://localhost:8000
```

### Error: "Access denied" en MySQL

**Problema**: No puede conectarse a la base de datos

**Solución**:
```bash
# Verifica credenciales en api/config.php
# Prueba la conexión
mysql -u root -p personal_pages
```

### Error: Las meta tags no se actualizan

**Problema**: Las meta tags de Open Graph no cambian

**Solución**:
- En desarrollo con `npm run dev`, esto es normal
- Para probar SSR, haz build y sirve con PHP
- Las meta tags solo funcionan para scrapers en producción

### Error: "Module not found"

**Problema**: Falta alguna dependencia

**Solución**:
```bash
cd php-mysql-version/frontend
rm -rf node_modules package-lock.json
npm install
```

### Puerto 3000 o 8000 ya en uso

**Problema**: El puerto está ocupado

**Solución**:
```bash
# Ver qué proceso usa el puerto
lsof -i :3000
lsof -i :8000

# Matar el proceso
kill -9 PID

# O usar otro puerto
php -S localhost:8080  # API en 8080
npm run dev -- --port 3001  # Frontend en 3001
```

### CORS Errors

**Problema**: Errores de CORS en consola del navegador

**Solución**: Verifica que todos los endpoints en la API tengan los headers CORS correctos. Ya están configurados, pero si modificas endpoints, asegúrate de incluir:

```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
```

## Hot Reload y Cambios en Vivo

### Frontend (React)
- ✅ **Automático**: Los cambios en archivos `.jsx`, `.css` se reflejan instantáneamente
- ✅ **Estado preservado**: React Fast Refresh mantiene el estado del componente
- No necesitas recargar manualmente

### Backend (PHP)
- ❌ **Manual**: Necesitas refrescar el navegador después de cambios
- Si cambias lógica de API, simplemente refresca (F5)
- No necesitas reiniciar el servidor PHP

### Base de Datos
- ❌ **Manual**: Ejecuta las queries SQL manualmente
- Usa un cliente MySQL o phpMyAdmin

## Variables de Entorno

### Frontend (.env)

```env
# URL de la API
VITE_API_URL=http://localhost:8000

# Google Maps (opcional)
VITE_GOOGLE_MAPS_API_KEY=tu_key_aqui

# Google Analytics (opcional)
VITE_GA_TRACKING_ID=G-XXXXXXXXXX
```

### Backend (config.php)

```php
// Base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_pages');
define('DB_USER', 'root');
define('DB_PASS', 'tu_password');

// JWT
define('JWT_SECRET', 'tu-secreto-seguro');
define('JWT_ALGORITHM', 'HS256');

// OAuth (opcional)
define('GOOGLE_CLIENT_ID', 'tu-client-id');
define('GOOGLE_CLIENT_SECRET', 'tu-client-secret');
```

## Consejos de Desarrollo

### 1. Usa las DevTools del Navegador

- **React DevTools**: Inspecciona componentes
- **Network Tab**: Verifica peticiones a la API
- **Console**: Ve errores y logs

### 2. Debugging PHP

Agrega logs temporales:
```php
error_log("Debug: " . print_r($variable, true));
// Ver en /var/log/apache2/error.log o en consola de php -S
```

### 3. Debugging React

```jsx
console.log('Estado actual:', state);
console.table(array);  // Muestra arrays en tabla
```

### 4. Extensiones Recomendadas (VS Code)

- **PHP Intelephense**: Autocompletado PHP
- **ES7+ React/Redux/React-Native**: Snippets React
- **ESLint**: Linting
- **Prettier**: Formateo
- **Thunder Client**: Probar API (como Postman)

### 5. Git Workflow

```bash
# No commitear archivos de configuración
git add .gitignore
git add api/.gitignore
git add frontend/.gitignore

# Estos archivos NO deben ir al repo:
# - api/config.php
# - frontend/.env
# - node_modules/
# - dist/
```

## Testing Rápido

### Probar Registro de Usuario

```bash
curl -X POST http://localhost:8000/api/auth/register.php \
  -H "Content-Type: application/json" \
  -d '{
    "username": "test",
    "email": "test@example.com",
    "password": "Test123!"
  }'
```

### Probar Login

```bash
curl -X POST http://localhost:8000/api/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "Test123!"
  }'
```

### Probar Página Pública

```bash
curl http://localhost:8000/api/public/page.php?slug=tu-slug
```

## Próximos Pasos

1. **Lee** CONFIGURACION_SEO.md para entender el SSR
2. **Explora** los templates en `frontend/src/components/templates/`
3. **Personaliza** los estilos en `frontend/src/index.css`
4. **Agrega** nuevas funcionalidades según necesites

## Recursos Adicionales

- [Vite Docs](https://vitejs.dev/)
- [React Docs](https://react.dev/)
- [PHP Manual](https://www.php.net/manual/)
- [Tailwind CSS](https://tailwindcss.com/)

---

**¿Listo para producción?** Lee `CONFIGURACION_SEO.md` y `README.md` para el despliegue.
