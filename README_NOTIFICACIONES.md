# Sistema de Notificaciones con Seguimiento de Páginas

## Inicio Rápido

### Instalación Automática (Recomendado)

```bash
cd php-mysql-version
bash install-notifications.sh
```

El script instalará todas las dependencias y generará las claves necesarias.

### Instalación Manual

1. **Instalar dependencias PHP**:
```bash
cd api
composer require minishlink/web-push
```

2. **Generar claves VAPID**:
```bash
php generate-vapid-keys.php
```

3. **Configurar `api/config.php`**:
```php
define('VAPID_PUBLIC_KEY', 'tu_clave_publica');
define('VAPID_PRIVATE_KEY', 'tu_clave_privada');
define('VAPID_SUBJECT', 'mailto:tu-email@ejemplo.com');
define('CRON_SECRET_KEY', 'clave_secreta_aleatoria');
```

4. **Ejecutar migración de base de datos**:
```bash
mysql -u usuario -p base_de_datos < migration_add_notifications_system.sql
```

5. **Configurar cron job**:
```bash
crontab -e
# Agregar esta línea:
0 9 * * * php /ruta/completa/api/notifications/process-daily.php
```

## Verificar Instalación

```bash
php api/test-notifications-system.php
```

Este script verificará que todos los componentes estén correctamente configurados.

## Características Principales

### 1. Ubicación del Usuario
- Definir ubicación mediante GPS o búsqueda manual
- Filtrado automático de eventos cercanos

### 2. Seguimiento de Páginas
- Seguir páginas públicas de interés
- Dos modos de notificación:
  - **Todos los eventos**: Recibir notificaciones de todos los eventos
  - **Eventos cercanos**: Solo eventos dentro de un radio configurable

### 3. Notificaciones Duales
- **Bandeja web**: Icono de campana con historial de notificaciones
- **Push del navegador**: Notificaciones nativas incluso con app cerrada

### 4. Procesamiento Automático
- Script cron diario que analiza eventos nuevos
- Cálculo de distancias con fórmula de Haversine
- Envío inteligente según preferencias del usuario

## Uso del Sistema

### Para Usuarios

1. **Configurar Ubicación**
   - Dashboard → Configuración → Mi Ubicación
   - Usar ubicación actual o buscar dirección
   - Guardar cambios

2. **Seguir Páginas**
   - Visitar página pública
   - Clic en botón "Seguir"
   - Configurar preferencias de notificación

3. **Gestionar Seguimientos**
   - Dashboard → Configuración → Páginas que Sigo
   - Editar preferencias o dejar de seguir

4. **Activar Push Notifications**
   - Dashboard → Configuración → Notificaciones
   - Activar interruptor de notificaciones push
   - Aceptar permisos del navegador

5. **Ver Notificaciones**
   - Clic en icono de campana en el header
   - Ver, marcar como leídas o eliminar

## Archivos Importantes

### Backend
- `api/users/location.php` - API de ubicación
- `api/pages/follow.php` - API de seguimiento
- `api/notifications/index.php` - API de notificaciones
- `api/notifications/subscribe.php` - API de suscripciones push
- `api/notifications/process-daily.php` - Procesador cron
- `api/generate-vapid-keys.php` - Generador de claves
- `api/test-notifications-system.php` - Script de verificación

### Frontend
- `src/components/NotificationBell.jsx` - Campana de notificaciones
- `src/components/LocationSettings.jsx` - Config. de ubicación
- `src/components/FollowingManager.jsx` - Gestión de seguimientos
- `src/components/FollowButton.jsx` - Botón para seguir páginas
- `src/pages/Settings.jsx` - Página de configuración
- `public/sw.js` - Service Worker para push

### Base de Datos
- `migration_add_notifications_system.sql` - Migración completa

### Documentación
- `GUIA_NOTIFICACIONES.md` - Guía completa (léela!)
- `SISTEMA_NOTIFICACIONES_RESUMEN.md` - Resumen ejecutivo
- `README_NOTIFICACIONES.md` - Este archivo

## Requisitos

- PHP 7.4+
- MySQL 5.7+
- Composer
- HTTPS (para notificaciones push)
- Acceso a cron jobs
- Google Maps API Key (para autocompletado de ubicaciones)

## Cron Job

El procesador de notificaciones debe ejecutarse diariamente:

```bash
# Ejecutar todos los días a las 9:00 AM
0 9 * * * php /ruta/completa/api/notifications/process-daily.php

# O vía HTTP (si no tienes acceso a CLI)
# Usar servicio como cron-job.org:
# https://tu-dominio.com/api/notifications/process-daily.php?cron_key=TU_CRON_SECRET_KEY
```

## Solución de Problemas

### Notificaciones no se envían
1. Verificar que el cron se ejecuta: `grep CRON /var/log/syslog`
2. Verificar eventos nuevos: Ver `GUIA_NOTIFICACIONES.md`
3. Ejecutar manualmente: `php api/notifications/process-daily.php`

### Push notifications no funcionan
1. Verificar HTTPS habilitado
2. Revisar permisos del navegador
3. Verificar claves VAPID en config.php
4. Revisar consola del navegador

### Service Worker no se registra
1. Verificar que `sw.js` está en `public/`
2. Limpiar cache del navegador
3. Verificar que el sitio usa HTTPS

## APIs Disponibles

### Ubicación
- `GET /api/users/location.php` - Obtener ubicación
- `PUT /api/users/location.php` - Actualizar ubicación

### Seguimiento
- `GET /api/pages/following.php` - Páginas que sigo
- `POST /api/pages/follow.php` - Seguir página
- `DELETE /api/pages/follow.php?page_id=X` - Dejar de seguir

### Notificaciones
- `GET /api/notifications/index.php` - Listar notificaciones
- `PUT /api/notifications/index.php` - Marcar como leídas
- `DELETE /api/notifications/index.php` - Eliminar

### Push
- `GET /api/notifications/subscribe.php` - Clave pública VAPID
- `POST /api/notifications/subscribe.php` - Suscribirse
- `DELETE /api/notifications/subscribe.php` - Desuscribirse

## Seguridad

- Todas las APIs requieren autenticación JWT
- HTTPS obligatorio para push notifications
- Endpoint de cron protegido con clave secreta
- Validación de datos en todas las entradas
- Sanitización de consultas SQL (PDO prepared statements)

## Próximos Pasos

Después de la instalación:

1. ✅ Ejecutar script de prueba: `php api/test-notifications-system.php`
2. ✅ Crear un usuario de prueba
3. ✅ Configurar ubicación del usuario
4. ✅ Seguir una página de prueba
5. ✅ Crear un evento en esa página
6. ✅ Ejecutar manualmente el cron: `php api/notifications/process-daily.php`
7. ✅ Verificar que se creó la notificación
8. ✅ Activar notificaciones push
9. ✅ Probar notificación push

## Soporte

Para más detalles, consulta `GUIA_NOTIFICACIONES.md`.

Si encuentras problemas:
1. Revisa los logs de PHP y MySQL
2. Ejecuta el script de prueba
3. Verifica la configuración paso a paso
4. Consulta la sección de "Solución de Problemas" en la guía completa

---

**Nota**: Este sistema requiere HTTPS en producción para que las notificaciones push funcionen correctamente.
