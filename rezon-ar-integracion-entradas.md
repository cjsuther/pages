# Integración SSO — rezon.ar → entradas.rezon.ar
## Prompt para Claude Code (proyecto rezon.ar)

---

## Contexto

Estamos creando un subdominio `entradas.rezon.ar` (sistema de reservas para shows)
que debe reutilizar la autenticación Google OAuth que ya existe en rezon.ar.
El token de Google OAuth actualmente se guarda en `localStorage`.

Necesito que agregues en el proyecto de rezon.ar los siguientes endpoints y
el JS de soporte para que el flujo SSO funcione de punta a punta.

**No modificar nada del flujo de autenticación existente.** Solo agregar los
archivos/endpoints nuevos que se detallan a continuación.

---

## 1. ENDPOINTS PHP A CREAR

### 1.1 `POST /auth/sso-init.php`

**Propósito:** Recibe el token de Google OAuth desde el JS del frontend,
lo guarda temporalmente en sesión con un código de un solo uso, y redirige
al subdominio.

**Request:** `Content-Type: application/json`
```json
{ "token": "ya29.xxx...", "redirect": "entradas.rezon.ar" }
```

**Lógica:**
1. Leer el body JSON con `json_decode(file_get_contents('php://input'))`
2. Validar que `redirect` sea exactamente `entradas.rezon.ar` (whitelist hardcodeada,
   no aceptar ningún otro valor)
3. Generar un UUID v4 como código temporal
4. Guardar en sesión:
   ```php
   $_SESSION['sso_codes'][$uuid] = [
     'token'   => $token,
     'expires' => time() + 60  // expira en 60 segundos
   ];
   ```
5. Redirigir con header HTTP a:
   `https://entradas.rezon.ar/auth/callback.php?code={UUID}`
6. Si el redirect no está en la whitelist: responder HTTP 400 `{"error": "invalid_redirect"}`
7. Si no viene token: responder HTTP 400 `{"error": "missing_token"}`

---

### 1.2 `GET /auth/sso-validate.php`

**Propósito:** El backend de `entradas.rezon.ar` llama a este endpoint para
canjear el código temporal y obtener el token real.

**Request:** `GET /auth/sso-validate.php?code=UUID`

**Lógica:**
1. Verificar que el header `HTTP_REFERER` contenga `entradas.rezon.ar`
   O verificar un header secreto compartido:
   `X-SSO-Secret: {valor de constante SSO_SECRET definida en config}`
2. Leer `$_SESSION['sso_codes'][$code]`
3. Si no existe: responder HTTP 404 `{"error": "invalid_code"}`
4. Si existe pero `expires < time()`: eliminar el código y responder HTTP 410 `{"error": "expired_code"}`
5. Si es válido:
   - Guardar el token en una variable local
   - **Eliminar inmediatamente** `$_SESSION['sso_codes'][$code]` (one-time use)
   - Obtener nombre y email del usuario desde la sesión activa de rezon.ar
     (buscar dónde se guardan actualmente en `$_SESSION` y usar esos valores)
   - Responder HTTP 200:
     ```json
     { "token": "ya29.xxx...", "email": "usuario@gmail.com", "name": "Nombre Apellido" }
     ```
6. Agregar header `Content-Type: application/json` en todas las respuestas

---

### 1.3 `GET /auth/sso-validate-token.php`

**Propósito:** Valida si un token Google OAuth es vigente. Lo usará
`entradas.rezon.ar` para proteger su endpoint público de API
(`GET /api/eventos.php`).

**Request:**
```
GET /auth/sso-validate-token.php
Authorization: Bearer ya29.xxx...
```

**Lógica:**
1. Leer el token del header `Authorization: Bearer <token>`
2. Si no viene el header: responder HTTP 401 `{"valid": false, "error": "missing_token"}`
3. Validar el token contra Google usando la endpoint pública:
   ```
   GET https://oauth2.googleapis.com/tokeninfo?access_token={token}
   ```
   Hacer el request con `file_get_contents` o `curl`
4. Si Google responde con error o el campo `error` existe en la respuesta:
   responder HTTP 401 `{"valid": false}`
5. Si Google responde OK y `aud` coincide con el Client ID de la app:
   responder HTTP 200:
   ```json
   { "valid": true, "email": "usuario@gmail.com", "name": "Nombre Apellido" }
   ```
6. Usar la constante `GOOGLE_CLIENT_ID` que ya debe existir en el proyecto
   para verificar el campo `aud` de la respuesta de Google
7. Agregar header `Content-Type: application/json` en todas las respuestas

---

## 2. JS A AGREGAR EN EL FRONTEND DE rezon.ar

**Archivo:** agregar el siguiente script en el layout principal de rezon.ar
(el archivo que se incluye en todas las páginas, probablemente un `layout.php`,
`header.php` o similar — buscar cuál es y agregarlo ahí).

**Lógica del script:**
1. Al cargar la página, verificar si la URL contiene el parámetro `?sso_redirect=entradas.rezon.ar`
2. Si existe ese parámetro:
   a. Leer el token de `localStorage` (buscar la clave exacta que usa rezon.ar
      para guardar el token de Google OAuth y usar esa misma clave)
   b. Si hay token: hacer `fetch POST` a `/auth/sso-init.php` con
      `{ token, redirect: "entradas.rezon.ar" }` y seguir el redirect resultante
   c. Si no hay token: significa que el usuario no está logueado.
      Guardar en `sessionStorage` la key `sso_pending_redirect` con valor `entradas.rezon.ar`
      y redirigir al flujo de login de Google que ya existe en rezon.ar
3. Adicionalmente: si existe `sessionStorage.getItem('sso_pending_redirect')`
   y el usuario acaba de completar el login (detectar que el token ahora existe
   en localStorage), ejecutar el paso 2b automáticamente y limpiar el
   `sessionStorage`.

```javascript
// SSO Bridge — rezon.ar → subdominios
(function () {
  const SSO_REDIRECT_PARAM = 'sso_redirect';
  const ALLOWED_REDIRECTS = ['entradas.rezon.ar'];
  // IMPORTANTE: reemplazar 'auth_token' con la clave real que usa rezon.ar en localStorage
  const TOKEN_KEY = 'auth_token';

  function getToken() {
    return localStorage.getItem(TOKEN_KEY);
  }

  function doSSOHandoff(redirect) {
    const token = getToken();
    if (!token) {
      sessionStorage.setItem('sso_pending_redirect', redirect);
      // Redirigir al login de Google — ajustar esta URL al flujo real de rezon.ar
      window.location.href = '/auth/google-login';
      return;
    }
    fetch('/auth/sso-init.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, redirect }),
      redirect: 'follow'
    }).then(response => {
      if (response.redirected) window.location.href = response.url;
    }).catch(err => console.error('SSO error:', err));
  }

  // Detectar parámetro sso_redirect en la URL actual
  const params = new URLSearchParams(window.location.search);
  const redirectParam = params.get(SSO_REDIRECT_PARAM);
  if (redirectParam && ALLOWED_REDIRECTS.includes(redirectParam)) {
    doSSOHandoff(redirectParam);
    return;
  }

  // Detectar si hay un redirect pendiente post-login
  const pendingRedirect = sessionStorage.getItem('sso_pending_redirect');
  if (pendingRedirect && ALLOWED_REDIRECTS.includes(pendingRedirect) && getToken()) {
    sessionStorage.removeItem('sso_pending_redirect');
    doSSOHandoff(pendingRedirect);
  }
})();
```

**IMPORTANTE antes de agregar este script:**
- Verificar el nombre exacto de la clave en `localStorage` donde se guarda el token
  y reemplazar `'auth_token'` en `TOKEN_KEY`
- Verificar la URL del flujo de login de Google y reemplazar `'/auth/google-login'`
  con la ruta real de rezon.ar

---

## 3. CONFIGURACIÓN A VERIFICAR / AGREGAR

En el archivo de configuración principal de rezon.ar (probablemente `config.php`
o similar), verificar que existan las siguientes constantes y agregarlas si no están:

```php
// Secreto compartido con entradas.rezon.ar (generar un string aleatorio seguro)
define('SSO_SECRET', 'REEMPLAZAR_CON_STRING_ALEATORIO_SEGURO');

// Google OAuth Client ID (probablemente ya existe, verificar el nombre de la constante)
define('GOOGLE_CLIENT_ID', 'xxxxxxxx.apps.googleusercontent.com');
```

El valor de `SSO_SECRET` debe ser el mismo que se configure en `entradas.rezon.ar`.
Generar con: `bin2hex(random_bytes(32))`

---

## 4. SESIONES PHP — VERIFICAR CONFIGURACIÓN

Para que el SSO funcione correctamente, la sesión de PHP en rezon.ar debe
persistir entre requests al mismo servidor. Verificar que la sesión esté
configurada con:

```php
session_set_cookie_params([
    'lifetime' => 86400,        // 24 horas
    'domain'   => '.rezon.ar',  // el punto inicial es clave para subdominios
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);
```

Si ya existe una llamada a `session_set_cookie_params` en el proyecto,
**solo agregar o modificar el campo `domain` para que sea `.rezon.ar`**.
No cambiar los demás valores si ya están configurados.

---

## 5. RESUMEN DE ARCHIVOS A CREAR/MODIFICAR

| Acción   | Archivo                            | Descripción                                      |
|----------|------------------------------------|--------------------------------------------------|
| Crear    | `auth/sso-init.php`                | Recibe token JS, genera code, redirige           |
| Crear    | `auth/sso-validate.php`            | Valida code one-time, devuelve token             |
| Crear    | `auth/sso-validate-token.php`      | Valida token contra Google API                   |
| Modificar| layout/header principal            | Agregar el script JS del SSO Bridge              |
| Verificar| `config.php` (o equivalente)       | Agregar `SSO_SECRET` y verificar `GOOGLE_CLIENT_ID` |
| Verificar| Inicialización de sesión PHP       | Agregar `domain => '.rezon.ar'` en cookie params |

---

## NOTAS FINALES

- **No romper nada existente**: todos los cambios son aditivos. No modificar
  el flujo de login/logout actual de rezon.ar.
- **Prioridad de implementación**: primero `sso-init.php` + `sso-validate.php` + el JS,
  ya que son el núcleo del SSO. `sso-validate-token.php` puede ir después.
- **Testing**: una vez implementado, probar el flujo completo accediendo a
  `https://entradas.rezon.ar` desde un browser donde ya se esté logueado en
  `rezon.ar` y verificar que la redirección y el handoff funcionan sin
  que el usuario tenga que hacer nada.
