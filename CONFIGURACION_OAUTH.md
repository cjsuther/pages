# Configuración de OAuth (Google y Apple Sign In)

Esta guía te mostrará cómo configurar Google y Apple Sign In para tu aplicación paso a paso.

## Tabla de Contenidos

1. [Requisitos Previos](#requisitos-previos)
2. [Configuración de Google OAuth](#configuración-de-google-oauth)
3. [Configuración de Apple Sign In](#configuración-de-apple-sign-in)
4. [Configuración de la Aplicación](#configuración-de-la-aplicación)
5. [Pruebas](#pruebas)
6. [Solución de Problemas](#solución-de-problemas)

---

## Requisitos Previos

- PHP 7.4 o superior con extensión cURL
- MySQL 5.7 o superior
- Base de datos `personal_pages` creada
- Servidor web corriendo (Apache/Nginx o `php -S`)

---

## Configuración de Google OAuth

### Paso 1: Crear Proyecto en Google Cloud Console

1. Ve a [Google Cloud Console](https://console.cloud.google.com)
2. Crea un nuevo proyecto o selecciona uno existente
3. Habilita la API de Google+ en tu proyecto

### Paso 2: Configurar Pantalla de Consentimiento OAuth

1. Ve a **APIs y servicios** → **Pantalla de consentimiento OAuth**
2. Selecciona tipo de usuario: **Externo**
3. Completa la información requerida:
   - Nombre de la aplicación
   - Correo electrónico de soporte
   - Logo (opcional)
4. Añade los scopes necesarios:
   - `openid`
   - `email`
   - `profile`
5. Guarda y continúa

### Paso 3: Crear Credenciales OAuth

1. Ve a **APIs y servicios** → **Credenciales**
2. Haz clic en **Crear credenciales** → **ID de cliente de OAuth 2.0**
3. Tipo de aplicación: **Aplicación web**
4. Configura:
   - **Nombre**: "Personal Pages"
   - **Orígenes autorizados de JavaScript**:
     - `http://localhost:5173` (desarrollo)
     - Tu dominio en producción (ej: `https://tuapp.com`)
   - **URIs de redireccionamiento autorizados**:
     - `http://localhost:8000/auth/google-callback.php` (desarrollo)
     - `https://tudominio.com/api/auth/google-callback.php` (producción)
5. Haz clic en **Crear**
6. Copia tu **Client ID** y **Client Secret**

### Ejemplo de Credenciales de Google

```
Client ID: 123456789-abcdefghijk.apps.googleusercontent.com
Client Secret: GOCSPX-abcdefghijklmnop
```

---

## Configuración de Apple Sign In

### Paso 1: Configurar en Apple Developer

1. Ve a [Apple Developer Portal](https://developer.apple.com/account)
2. Necesitas una cuenta de desarrollador de Apple (puede ser gratuita para desarrollo)

### Paso 2: Crear un App ID

1. Ve a **Certificates, Identifiers & Profiles**
2. Haz clic en **Identifiers** → botón **+**
3. Selecciona **App IDs** → **Continuar**
4. Selecciona **App** → **Continuar**
5. Configura:
   - **Description**: "Personal Pages"
   - **Bundle ID**: `com.tudominio.personalpages` (explicit)
6. Busca y habilita **Sign in with Apple**
7. Haz clic en **Continuar** y **Register**

### Paso 3: Crear un Services ID

1. Ve nuevamente a **Identifiers** → botón **+**
2. Selecciona **Services IDs** → **Continuar**
3. Configura:
   - **Description**: "Personal Pages Web"
   - **Identifier**: `com.tudominio.personalpages.service`
4. Haz clic en **Continuar** y **Register**
5. Haz clic en el Services ID que acabas de crear
6. Marca **Sign in with Apple** → **Configure**
7. Configura:
   - **Primary App ID**: Selecciona el App ID creado anteriormente
   - **Website URLs**:
     - **Domains**: `localhost` (desarrollo) y tu dominio (producción)
     - **Return URLs**:
       - `http://localhost:8000/auth/apple-callback.php`
       - `https://tudominio.com/api/auth/apple-callback.php`
8. Guarda todo

### Paso 4: Crear una Key para Sign in with Apple

1. Ve a **Keys** → botón **+**
2. Configura:
   - **Key Name**: "Personal Pages Sign in with Apple Key"
   - Marca **Sign in with Apple**
   - Haz clic en **Configure** y selecciona tu App ID
3. Haz clic en **Continuar** y **Register**
4. **IMPORTANTE**: Descarga el archivo `.p8` (solo se puede descargar una vez)
5. Anota el **Key ID** (código de 10 caracteres)
6. Anota tu **Team ID** (está en la esquina superior derecha del portal)

### Ejemplo de Credenciales de Apple

```
Client ID (Services ID): com.tudominio.personalpages.service
Team ID: ABCD123456
Key ID: XYZ9876543
Private Key: (contenido del archivo .p8)
```

---

## Configuración de la Aplicación

### Paso 1: Ejecutar Migración SQL

```bash
mysql -u root -p personal_pages < php-mysql-version/migration_oauth_and_events.sql
```

Esto añadirá las columnas necesarias a la tabla `users` para OAuth.

### Paso 2: Configurar archivo config.php

1. Copia el archivo de ejemplo:
```bash
cp php-mysql-version/api/config.example.php php-mysql-version/api/config.php
```

2. Edita `config.php` y configura:

```php
// URLs
define('FRONTEND_URL', 'http://localhost:5173');

// Google OAuth
define('GOOGLE_CLIENT_ID', 'TU_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'TU_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/google-callback.php');

// Apple OAuth
define('APPLE_CLIENT_ID', 'com.tudominio.personalpages.service');
define('APPLE_TEAM_ID', 'TU_TEAM_ID');
define('APPLE_KEY_ID', 'TU_KEY_ID');
define('APPLE_REDIRECT_URI', 'http://localhost:8000/auth/apple-callback.php');
define('APPLE_PRIVATE_KEY', <<<EOD
-----BEGIN PRIVATE KEY-----
(pega aquí el contenido del archivo .p8)
-----END PRIVATE KEY-----
EOD
);
```

### Paso 3: Permisos de Archivos

Asegúrate de que el archivo `config.php` tenga permisos adecuados:

```bash
chmod 600 php-mysql-version/api/config.php
```

---

## Pruebas

### Iniciar la Aplicación

Terminal 1 - Backend:
```bash
cd php-mysql-version
php -S localhost:8000
```

Terminal 2 - Frontend:
```bash
cd php-mysql-version/frontend
npm install
npm run dev
```

### Probar Google Sign In

1. Abre http://localhost:5173
2. Ve a Login o Register
3. Haz clic en "Continuar con Google"
4. Deberías ser redirigido a Google para autenticarte
5. Después de autenticarte, volverás a la aplicación ya logueado

### Probar Apple Sign In

1. Abre http://localhost:5173
2. Ve a Login o Register
3. Haz clic en "Continuar con Apple"
4. Deberías ser redirigido a Apple para autenticarte
5. Después de autenticarte, volverás a la aplicación ya logueado

---

## Solución de Problemas

### Error: "redirect_uri_mismatch" (Google)

**Causa**: La URL de redirección no coincide con las configuradas en Google Cloud Console.

**Solución**:
1. Ve a Google Cloud Console → Credenciales
2. Edita tu OAuth Client ID
3. Asegúrate de que `http://localhost:8000/auth/google-callback.php` esté en "URIs de redireccionamiento autorizados"

### Error: "invalid_client" (Apple)

**Causa**: Las credenciales de Apple no son correctas.

**Solución**:
1. Verifica que el APPLE_CLIENT_ID sea tu Services ID
2. Verifica que APPLE_TEAM_ID y APPLE_KEY_ID sean correctos
3. Verifica que la clave privada esté completa y tenga el formato correcto

### Error: "Invalid JWT Signature"

**Causa**: La clave privada de Apple no es correcta o está mal formateada.

**Solución**:
1. Asegúrate de copiar TODO el contenido del archivo .p8
2. Debe incluir las líneas BEGIN y END
3. No debe tener espacios ni saltos de línea adicionales

### El botón de OAuth no hace nada

**Causa**: JavaScript está bloqueado o hay un error en la consola.

**Solución**:
1. Abre las herramientas de desarrollo del navegador (F12)
2. Revisa la pestaña Console por errores
3. Verifica que `apiUrl` esté configurado correctamente en el frontend

### Error de CORS

**Causa**: El backend no está configurado para aceptar requests del frontend.

**Solución**:
1. Verifica que `config.php` tenga los headers CORS correctos
2. Asegúrate de que el backend esté corriendo en el puerto correcto
3. Verifica que `FRONTEND_URL` en config.php coincida con la URL del frontend

---

## Notas de Seguridad

1. **NUNCA** subas `config.php` a un repositorio público
2. Usa variables de entorno en producción
3. Usa HTTPS en producción (requerido por Apple)
4. Genera un JWT_SECRET fuerte y único:
   ```bash
   openssl rand -base64 32
   ```
5. Mantén tu clave privada de Apple segura

---

## Producción

Cuando despliegues a producción:

1. Actualiza las URLs en Google Cloud Console
2. Actualiza las URLs en Apple Developer Portal
3. Cambia `FRONTEND_URL` y las URIs de redirección
4. Usa HTTPS (obligatorio)
5. Usa variables de entorno para credenciales sensibles
6. Asegúrate de que `config.php` no sea accesible públicamente

---

¿Necesitas ayuda? Revisa la documentación oficial:
- [Google OAuth Documentation](https://developers.google.com/identity/protocols/oauth2)
- [Apple Sign In Documentation](https://developer.apple.com/sign-in-with-apple/)
