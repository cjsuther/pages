# Checklist de Instalación

Usa este checklist para asegurarte de completar todos los pasos necesarios.

## Pre-requisitos

- [ ] PHP 7.4 o superior instalado
- [ ] MySQL 5.7 o superior instalado
- [ ] Apache instalado y corriendo
- [ ] Node.js 16 o superior instalado
- [ ] Acceso a terminal/línea de comandos

## Instalación de Base de Datos

- [ ] Base de datos MySQL corriendo
- [ ] Archivo `database.sql` localizado
- [ ] Base de datos `personal_pages` creada
- [ ] Tablas importadas correctamente (users, pages, link_groups, links)
- [ ] Usuario MySQL con permisos adecuados

**Verificación:**
```sql
USE personal_pages;
SHOW TABLES;
-- Deberías ver: users, pages, link_groups, links
```

## Configuración del Backend

- [ ] Carpeta `api/` copiada al servidor
- [ ] Archivo `api/config.example.php` copiado a `api/config.php`
- [ ] `DB_HOST` configurado en `config.php`
- [ ] `DB_NAME` configurado en `config.php`
- [ ] `DB_USER` configurado en `config.php`
- [ ] `DB_PASS` configurado en `config.php`
- [ ] `JWT_SECRET` cambiado a una clave segura aleatoria
- [ ] Archivo `api/.htaccess` presente

**Verificación:**
```bash
# Probar conexión PHP a MySQL
php -r "new PDO('mysql:host=localhost;dbname=personal_pages', 'user', 'pass');"
# No debería mostrar error
```

## Configuración de Apache

- [ ] `mod_rewrite` habilitado
- [ ] `mod_headers` habilitado
- [ ] `AllowOverride All` configurado
- [ ] Apache reiniciado
- [ ] Archivo `.htaccess` en la raíz del proyecto

**Verificación:**
```bash
# Ubuntu/Debian
sudo apache2ctl -M | grep rewrite
# Debería mostrar: rewrite_module (shared)

sudo apache2ctl -M | grep headers
# Debería mostrar: headers_module (shared)
```

## Configuración del Frontend

- [ ] Node.js y npm instalados
- [ ] Carpeta `frontend/` accesible
- [ ] `npm install` ejecutado en carpeta frontend
- [ ] Archivo `src/App.jsx` editado con URL correcta de API
- [ ] Sin errores en la instalación de dependencias

**Verificación:**
```bash
cd frontend
node --version  # Debería mostrar v16 o superior
npm --version   # Debería mostrar versión de npm
ls node_modules # Debería existir y tener muchas carpetas
```

## Testing en Desarrollo

- [ ] Backend corriendo en un puerto (ej: 8000)
- [ ] Frontend corriendo en otro puerto (ej: 3000)
- [ ] Navegador abre correctamente la aplicación
- [ ] Página de login se muestra correctamente
- [ ] Estilos se cargan correctamente

**Comandos:**
```bash
# Terminal 1
cd api
php -S localhost:8000

# Terminal 2
cd frontend
npm run dev
```

## Pruebas Funcionales

- [ ] Puedes registrar un nuevo usuario
- [ ] Recibes un token JWT después de registrarte
- [ ] Puedes iniciar sesión con credenciales existentes
- [ ] Dashboard se carga correctamente
- [ ] Puedes crear una nueva página
- [ ] Puedes editar el título de la página
- [ ] Puedes cambiar colores de la página
- [ ] Puedes crear un grupo
- [ ] Puedes crear un enlace
- [ ] Puedes eliminar un enlace
- [ ] Puedes eliminar un grupo
- [ ] Puedes eliminar una página
- [ ] La página pública se muestra correctamente en `/tu-url`
- [ ] Los colores personalizados se aplican en la vista pública
- [ ] Los enlaces funcionan correctamente en la vista pública

## Compilación para Producción

- [ ] `npm run build` ejecutado sin errores
- [ ] Carpeta `frontend/dist/` creada
- [ ] Archivos compilados presentes en `dist/`

**Verificación:**
```bash
cd frontend
npm run build
ls dist/
# Debería mostrar: index.html, assets/, etc.
```

## Despliegue en Producción

- [ ] Carpeta `api/` subida al servidor
- [ ] Archivos de `frontend/dist/*` subidos a la raíz del servidor web
- [ ] `.htaccess` en la raíz del servidor
- [ ] `api/.htaccess` presente
- [ ] Permisos de archivos correctos
- [ ] URL de API en producción actualizada
- [ ] Base de datos en servidor de producción configurada

## Verificación Final

- [ ] Acceder a la URL de producción
- [ ] Página de login carga sin errores 404
- [ ] Puedes registrarte
- [ ] Puedes iniciar sesión
- [ ] Dashboard funciona
- [ ] Puedes crear y editar páginas
- [ ] Páginas públicas son accesibles sin login
- [ ] No hay errores en la consola del navegador
- [ ] No hay errores 404 al navegar

## Seguridad

- [ ] `JWT_SECRET` es único y seguro
- [ ] Contraseñas se guardan hasheadas
- [ ] `api/config.php` no está en Git (verificar `.gitignore`)
- [ ] HTTPS configurado en producción (recomendado)
- [ ] Firewall configurado en el servidor
- [ ] MySQL no acepta conexiones remotas no autorizadas

## Optimización (Opcional)

- [ ] Compresión GZIP habilitada
- [ ] Caché de archivos estáticos configurado
- [ ] Índices de base de datos verificados
- [ ] Imágenes optimizadas
- [ ] CDN configurado (si aplica)
- [ ] Google Analytics integrado (si deseas)

## Documentación

- [ ] README.md leído
- [ ] QUICKSTART.md consultado
- [ ] FAQ.md revisado para problemas comunes
- [ ] ESTRUCTURA.txt entendido
- [ ] Credenciales documentadas en lugar seguro

## Respaldo

- [ ] Backup de base de datos creado
- [ ] Backup de archivos de código creado
- [ ] Procedimiento de restauración documentado
- [ ] Cronograma de backups establecido

## Problemas Comunes (Si algo falla)

### Backend no conecta a la base de datos
- [ ] Verificar credenciales en `api/config.php`
- [ ] Verificar que MySQL esté corriendo
- [ ] Verificar permisos del usuario MySQL

### Errores 404 en producción
- [ ] Verificar que `mod_rewrite` esté habilitado
- [ ] Verificar que `.htaccess` esté presente
- [ ] Verificar `AllowOverride All` en configuración de Apache

### CORS errors
- [ ] Verificar headers CORS en `api/config.php`
- [ ] Verificar `mod_headers` habilitado
- [ ] Verificar archivo `api/.htaccess`

### Frontend no carga estilos
- [ ] Verificar que la compilación fue exitosa
- [ ] Verificar rutas en `index.html`
- [ ] Limpiar caché del navegador

### Token inválido
- [ ] Verificar que `JWT_SECRET` sea el mismo en config
- [ ] Cerrar sesión y volver a iniciar
- [ ] Verificar que el token no haya expirado (24h)

## Siguiente Paso

Una vez completado todo el checklist:

1. Crea tu primera página de prueba
2. Comparte la URL pública
3. Revisa `EJEMPLOS_DE_USO.md` para ideas
4. Lee `SEO_Y_MEJORAS.md` para optimizaciones

---

## Notas Importantes

- Guarda una copia de este checklist completado
- Documenta cualquier cambio que hagas a la configuración
- Mantén backups regulares
- Actualiza credenciales periódicamente

---

**Fecha de instalación:** _______________
**Instalado por:** _______________
**Ambiente:** [ ] Desarrollo  [ ] Producción
**URL de producción:** _______________

¡Felicitaciones por completar la instalación!
