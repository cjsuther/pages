# Archivos Creados para OAuth

## Backend PHP (api/)

### Autenticación OAuth
- `api/auth/google-login.php` - Inicia el flujo OAuth de Google
- `api/auth/google-callback.php` - Maneja el callback de Google
- `api/auth/apple-login.php` - Inicia el flujo OAuth de Apple
- `api/auth/apple-callback.php` - Maneja el callback de Apple
- `api/auth/AppleJWT.php` - Clase helper para generar JWT de Apple

### APIs Públicas
- `api/public/search.php` - Búsqueda de páginas públicas
- `api/public/events.php` - Calendario de eventos públicos

### Configuración
- `api/config.example.php` - Actualizado con constantes OAuth

## Frontend React (frontend/src/)

### Páginas Actualizadas
- `frontend/src/pages/Login.jsx` - Con botones OAuth y manejo de callbacks
- `frontend/src/pages/Register.jsx` - Con botones OAuth y manejo de callbacks
- `frontend/src/pages/Home.jsx` - Página pública con buscador y calendario

## Base de Datos

- `migration_oauth_and_events.sql` - Migración SQL que añade:
  - Columnas OAuth a tabla users (oauth_provider, oauth_id, name, avatar_url)
  - Tabla events para calendario público
  - Índices para optimización

## Documentación

- `CONFIGURACION_OAUTH.md` - Guía completa paso a paso
- `QUICKSTART_OAUTH.md` - Inicio rápido en 5-15 minutos
- `ARCHIVOS_OAUTH.md` - Este archivo (lista de todos los archivos)

## Flujo de OAuth

### Google
1. Usuario hace clic en "Continuar con Google"
2. `google-login.php` redirige a Google
3. Usuario autoriza en Google
4. Google redirige a `google-callback.php`
5. Backend obtiene token y datos del usuario
6. Crea/actualiza usuario en base de datos
7. Genera JWT propio
8. Redirige al frontend con token

### Apple
1. Usuario hace clic en "Continuar con Apple"
2. `apple-login.php` redirige a Apple
3. Usuario autoriza en Apple
4. Apple redirige a `apple-callback.php` (POST)
5. Backend verifica token Apple usando `AppleJWT.php`
6. Crea/actualiza usuario en base de datos
7. Genera JWT propio
8. Redirige al frontend con token

## URLs del Sistema

### Desarrollo
- Frontend: `http://localhost:5173`
- Backend: `http://localhost:8000`
- Google Callback: `http://localhost:8000/auth/google-callback.php`
- Apple Callback: `http://localhost:8000/auth/apple-callback.php`

### Producción (ejemplos)
- Frontend: `https://tudominio.com`
- Backend: `https://tudominio.com/api`
- Google Callback: `https://tudominio.com/api/auth/google-callback.php`
- Apple Callback: `https://tudominio.com/api/auth/apple-callback.php`

## Siguiente Paso

Ver [QUICKSTART_OAUTH.md](./QUICKSTART_OAUTH.md) para comenzar.
