# Quick Start - OAuth en 5 minutos

Esta guía te ayudará a tener OAuth funcionando lo más rápido posible.

## Opción 1: Solo Google OAuth (5 minutos)

### 1. Obtener credenciales de Google

1. Ve a [Google Cloud Console](https://console.cloud.google.com)
2. Crea un proyecto nuevo
3. Ve a **Credenciales** → **Crear credenciales** → **ID de cliente OAuth 2.0**
4. Configura:
   - Tipo: Aplicación web
   - URI de redirección: `http://localhost:8000/auth/google-callback.php`
5. Copia el Client ID y Client Secret

### 2. Ejecutar migración

```bash
mysql -u root -p personal_pages < php-mysql-version/migration_oauth_and_events.sql
```

### 3. Configurar aplicación

```bash
cp php-mysql-version/api/config.example.php php-mysql-version/api/config.php
```

Edita `config.php` y añade tus credenciales de Google:

```php
define('GOOGLE_CLIENT_ID', 'TU_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'TU_CLIENT_SECRET');
```

### 4. Iniciar aplicación

Terminal 1:
```bash
cd php-mysql-version && php -S localhost:8000
```

Terminal 2:
```bash
cd php-mysql-version/frontend && npm run dev
```

### 5. Probar

Abre http://localhost:5173 y haz clic en "Continuar con Google"

---

## Opción 2: Desactivar OAuth temporalmente

Si quieres usar solo email/password mientras configuras OAuth:

### Editar Login.jsx

Comenta las secciones de OAuth:

```jsx
{/* Temporalmente desactivado
<div className="space-y-4 mb-6">
  <button onClick={handleGoogleLogin}>...</button>
  <button onClick={handleAppleLogin}>...</button>
</div>
<div className="relative mb-6">...</div>
*/}
```

Haz lo mismo en `Register.jsx`

---

## Opción 3: Configuración Completa (15 minutos)

Si quieres tanto Google como Apple:

1. Sigue los pasos de Google OAuth arriba
2. Para Apple Sign In:
   - Necesitas cuenta de Apple Developer
   - Crea Services ID en developer.apple.com
   - Crea una Key para Sign in with Apple
   - Descarga el archivo .p8
   - Configura las credenciales en `config.php`

Ver [CONFIGURACION_OAUTH.md](./CONFIGURACION_OAUTH.md) para detalles completos.

---

## Solución Rápida de Problemas

### Error: redirect_uri_mismatch

Asegúrate de que la URL de redirección en Google Console sea exactamente:
```
http://localhost:8000/auth/google-callback.php
```

### Error: No se puede conectar

Verifica que el backend esté corriendo:
```bash
curl http://localhost:8000/auth/login.php
```

### Los botones no funcionan

1. Abre la consola del navegador (F12)
2. Busca errores JavaScript
3. Verifica que `apiUrl` sea `http://localhost:8000`

---

## ¿Necesitas más ayuda?

- Ver documentación completa: [CONFIGURACION_OAUTH.md](./CONFIGURACION_OAUTH.md)
- Verificar estructura del proyecto: [ESTRUCTURA.txt](./ESTRUCTURA.txt)
- FAQ: [FAQ.md](./FAQ.md)
