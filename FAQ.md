# Preguntas Frecuentes (FAQ)

## Instalación y Configuración

### ¿Cómo instalo la base de datos?

```bash
mysql -u root -p < database.sql
```

O desde phpMyAdmin:
1. Crea una base de datos llamada `personal_pages`
2. Selecciona la base de datos
3. Ve a la pestaña "Importar"
4. Selecciona el archivo `database.sql`
5. Click en "Continuar"

### ¿Dónde cambio las credenciales de la base de datos?

Edita el archivo `api/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_pages');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
```

### ¿Qué es JWT_SECRET y por qué debo cambiarlo?

`JWT_SECRET` es la clave que se usa para firmar los tokens de autenticación. Debes cambiarla por una cadena aleatoria y segura. Puedes generarla así:

**Opción 1 - Desde PHP:**
```bash
php -r "echo bin2hex(random_bytes(32));"
```

**Opción 2 - Online:**
Visita: https://randomkeygen.com/

Copia la clave generada en `api/config.php`:
```php
define('JWT_SECRET', 'tu_clave_aleatoria_aqui');
```

### ¿Cómo habilito mod_rewrite en Apache?

**Ubuntu/Debian:**
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**Windows (XAMPP):**
Ya viene habilitado por defecto.

**Verificar:**
Crea un archivo `test.php`:
```php
<?php
phpinfo();
?>
```

Busca "Loaded Modules" y verifica que aparezca `mod_rewrite`.

### Error: "Access to XMLHttpRequest has been blocked by CORS policy"

Asegúrate de que:
1. El archivo `api/config.php` tenga los headers CORS
2. El archivo `api/.htaccess` exista y tenga la configuración correcta
3. Apache tenga habilitado `mod_headers`:

```bash
sudo a2enmod headers
sudo systemctl restart apache2
```

## Uso General

### ¿Puedo crear múltiples páginas con una sola cuenta?

Sí, un usuario puede crear todas las páginas que quiera. Cada página tendrá su propia URL personalizada.

### ¿Puedo cambiar la URL de una página después de crearla?

No, por seguridad y consistencia, el slug de URL no se puede cambiar después de crear la página. Si necesitas cambiarlo, deberás crear una nueva página.

### ¿Cuántos grupos y enlaces puedo crear?

No hay límite técnico. Puedes crear tantos grupos y enlaces como necesites.

### ¿Los visitantes necesitan registrarse para ver mi página?

No, las páginas públicas son accesibles sin necesidad de registro. Solo necesitas compartir la URL: `/tu-url`

### ¿Puedo eliminar mi cuenta?

Actualmente no hay una función automática de eliminación de cuenta. Puedes eliminar todas tus páginas desde el Dashboard, o contactar al administrador para eliminar tu cuenta de la base de datos.

## Personalización

### ¿Cómo subo imágenes para mis enlaces?

El sistema no incluye subida de archivos. Debes subir tus imágenes a un servicio externo como:
- Imgur (https://imgur.com)
- Cloudinary (https://cloudinary.com)
- Google Drive (links públicos)
- Tu propio servidor

Luego copia la URL directa de la imagen en el campo "URL de Imagen".

### ¿Qué formato de imagen debo usar?

Cualquier formato web: JPG, PNG, GIF, WebP. Recomendado:
- Tamaño: 500x500px o similar
- Formato: JPG o PNG
- Peso: Menor a 500KB

### ¿Puedo usar colores personalizados?

Sí, puedes elegir cualquier color usando el selector de colores. Los colores se guardan en formato hexadecimal (#RRGGBB).

### ¿Puedo cambiar el diseño de la página pública?

El diseño está predefinido, pero puedes personalizar:
- Colores (primario, secundario, fondo, texto)
- Contenido (título, descripción, grupos, enlaces)
- Imágenes en los enlaces

Para cambios más profundos, necesitarás editar el archivo `frontend/src/pages/PublicPage.jsx`.

## Seguridad

### ¿Las contraseñas están seguras?

Sí, las contraseñas se almacenan hasheadas usando `bcrypt` con PHP. Nunca se almacenan en texto plano.

### ¿Cuánto tiempo dura la sesión?

Los tokens JWT expiran después de 24 horas. Después de eso, deberás iniciar sesión nuevamente.

### ¿Puedo cambiar el tiempo de expiración del token?

Sí, edita `api/config.php`:

```php
define('JWT_EXPIRATION', 86400); // 24 horas en segundos
```

Por ejemplo, para 7 días:
```php
define('JWT_EXPIRATION', 604800); // 7 días
```

### ¿Otras personas pueden editar mi página?

No, solo el propietario de la página (el usuario que la creó) puede editarla. La autenticación con JWT asegura esto.

## Desarrollo

### ¿Cómo ejecuto el proyecto en modo desarrollo?

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

Accede a: `http://localhost:3000`

### ¿Cómo compilo para producción?

```bash
cd frontend
npm run build
```

Los archivos compilados estarán en `frontend/dist/`

### ¿Puedo usar otro puerto?

**Backend:**
```bash
php -S localhost:PUERTO_DESEADO
```

**Frontend:**
Edita `frontend/vite.config.js`:
```javascript
server: {
  port: 3000, // Cambia este número
}
```

### ¿Cómo agrego nuevas funcionalidades?

1. Backend: Crea nuevos endpoints en `api/`
2. Frontend: Crea nuevos componentes en `frontend/src/`
3. Base de datos: Agrega nuevas tablas/campos con migraciones SQL

## Problemas Comunes

### Error: "Connection Error: Access denied"

Las credenciales de la base de datos son incorrectas. Verifica `api/config.php`.

### Error: "Page not found" en producción

Problema con `.htaccess` o `mod_rewrite`. Asegúrate de que:
1. El archivo `.htaccess` esté en la raíz
2. `mod_rewrite` esté habilitado
3. `AllowOverride All` esté configurado en Apache

### Error: "Invalid token" o "Unauthorized"

Tu token JWT expiró o es inválido. Cierra sesión y vuelve a iniciar sesión.

### La página se ve rota después de compilar

Verifica las rutas de los archivos. En producción, asegúrate de que:
1. Los archivos JS/CSS estén en las ubicaciones correctas
2. Las rutas en `index.html` sean correctas
3. El archivo `.htaccess` esté configurado correctamente

### No puedo ver las imágenes de los enlaces

Verifica que:
1. La URL de la imagen sea pública y accesible
2. La URL sea directa (termine en .jpg, .png, etc.)
3. El servidor de la imagen permita hotlinking
4. La URL use HTTPS si tu sitio usa HTTPS

### La página no guarda los cambios de colores

Los cambios se guardan automáticamente al cambiar el color. Si no funciona:
1. Verifica la consola del navegador (F12) para errores
2. Asegúrate de que tu token sea válido
3. Verifica que el endpoint PUT funcione correctamente

## Despliegue

### ¿Puedo usar este proyecto en hosting compartido?

Sí, siempre que tengas:
- PHP 7.4+
- MySQL
- Acceso para crear/editar archivos `.htaccess`

### ¿Funciona en cPanel?

Sí, funciona perfectamente en cPanel. Sube los archivos vía FTP o File Manager.

### ¿Necesito un servidor dedicado?

No, funciona en cualquier hosting que soporte PHP y MySQL.

### ¿Puedo usar bases de datos PostgreSQL en lugar de MySQL?

Necesitarías modificar todos los archivos PHP para cambiar de PDO MySQL a PDO PostgreSQL. No es complicado pero requiere cambios en el código.

## Integración

### ¿Puedo integrar Google Analytics?

Sí, agrega tu código de Google Analytics en `frontend/index.html` antes del cierre de `</head>`.

### ¿Puedo agregar un dominio personalizado?

Sí, configura tu dominio para apuntar a tu servidor y actualiza la configuración de Apache/Nginx.

### ¿Puedo exportar mis datos?

Puedes hacer un dump de la base de datos:
```bash
mysqldump -u usuario -p personal_pages > backup.sql
```

### ¿Hay una API REST documentada?

Los endpoints están documentados en `README.md` y `ESTRUCTURA.txt`.

## Licencia y Soporte

### ¿Es gratis?

Sí, el código es libre y gratuito para uso personal y comercial.

### ¿Puedo modificar el código?

Sí, puedes modificar, adaptar y redistribuir el código libremente.

### ¿Hay soporte técnico?

Este es un proyecto open source. Puedes:
1. Revisar la documentación incluida
2. Buscar en la comunidad
3. Contratar un desarrollador para ayuda personalizada

### ¿Puedo revender este sistema?

Sí, puedes usar este código en proyectos comerciales y venderlo como parte de tus servicios.
