# Guía de Instalación y Configuración del Sistema de Notificaciones

Este documento describe cómo instalar y configurar el sistema completo de notificaciones con seguimiento de páginas, ubicación de usuarios y notificaciones push.

## Contenido

1. [Características del Sistema](#características-del-sistema)
2. [Requisitos Previos](#requisitos-previos)
3. [Instalación de la Base de Datos](#instalación-de-la-base-de-datos)
4. [Instalación de Dependencias](#instalación-de-dependencias)
5. [Configuración del Backend](#configuración-del-backend)
6. [Configuración del Frontend](#configuración-del-frontend)
7. [Configuración del Cron Job](#configuración-del-cron-job)
8. [Uso del Sistema](#uso-del-sistema)
9. [Solución de Problemas](#solución-de-problemas)

## Características del Sistema

### 1. Ubicación del Usuario
- Los usuarios pueden definir su ubicación principal manualmente o mediante geolocalización
- La ubicación se utiliza para filtrar eventos cercanos
- Se almacena la última fecha de actualización de ubicación

### 2. Seguimiento de Páginas
- Los usuarios pueden seguir páginas públicas
- Configuración flexible por página:
  - **Todos los eventos**: Recibir notificaciones de todos los eventos
  - **Solo eventos cercanos**: Recibir notificaciones de eventos dentro de un radio específico (configurable en km)

### 3. Sistema de Notificaciones
- **Bandeja de notificaciones** en la aplicación web
- **Notificaciones push** del navegador (Web Push API)
- Notificaciones generadas automáticamente una vez al día mediante cron job
- Filtrado inteligente basado en preferencias del usuario

### 4. Procesamiento Automático
- Script cron que se ejecuta diariamente
- Analiza eventos creados en las últimas 24 horas
- Aplica filtros de distancia usando la fórmula de Haversine
- Envía notificaciones push a usuarios suscritos

## Requisitos Previos

- PHP 7.4 o superior
- MySQL 5.7 o superior
- Composer (para instalar dependencias PHP)
- Acceso a cron jobs en el servidor
- HTTPS habilitado (requerido para notificaciones push)

## Instalación de la Base de Datos

### Paso 1: Ejecutar la migración

Ejecuta el siguiente archivo SQL en tu base de datos:

```bash
mysql -u tu_usuario -p tu_base_de_datos < migration_add_notifications_system.sql
```

O desde phpMyAdmin/Adminer, importa el archivo `migration_add_notifications_system.sql`.

### Paso 2: Verificar las tablas creadas

Deberían haberse creado las siguientes tablas:
- `page_followers` - Relación entre usuarios y páginas que siguen
- `notifications` - Almacén de notificaciones
- `push_subscriptions` - Suscripciones a notificaciones push

Y los siguientes campos en la tabla `users`:
- `location_latitude`
- `location_longitude`
- `location_name`
- `last_location_update`

## Instalación de Dependencias

### Paso 1: Instalar Composer (si no lo tienes)

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Paso 2: Instalar la librería web-push-php

Navega al directorio de tu API y ejecuta:

```bash
cd /ruta/a/tu/proyecto/api
composer require minishlink/web-push
```

Esto instalará la librería necesaria para enviar notificaciones push.

## Configuración del Backend

### Paso 1: Generar claves VAPID

Las claves VAPID son necesarias para las notificaciones push. Puedes generarlas con el siguiente script PHP:

Crea un archivo `generate-vapid-keys.php` en el directorio `api/`:

```php
<?php
require 'vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "VAPID Keys generadas:\n\n";
echo "Public Key:\n" . $keys['publicKey'] . "\n\n";
echo "Private Key:\n" . $keys['privateKey'] . "\n";
```

Ejecuta el script:

```bash
php generate-vapid-keys.php
```

Guarda las claves generadas, las necesitarás en el siguiente paso.

### Paso 2: Actualizar config.php

Copia `config.example.php` a `config.php` si aún no lo has hecho:

```bash
cp api/config.example.php api/config.php
```

Edita `api/config.php` y actualiza las siguientes constantes:

```php
// Claves VAPID generadas en el paso anterior
define('VAPID_PUBLIC_KEY', 'TU_CLAVE_PUBLICA_VAPID');
define('VAPID_PRIVATE_KEY', 'TU_CLAVE_PRIVADA_VAPID');
define('VAPID_SUBJECT', 'mailto:tu-email@ejemplo.com'); // Tu email de contacto

// Clave secreta para proteger el endpoint del cron
define('CRON_SECRET_KEY', 'genera_una_clave_aleatoria_segura_aqui');
```

**Importante**: Cambia `CRON_SECRET_KEY` por una cadena aleatoria y segura. Puedes generarla con:

```bash
openssl rand -base64 32
```

## Configuración del Frontend

### Paso 1: Actualizar variables de entorno

Asegúrate de que el archivo `.env` del frontend incluya:

```env
VITE_API_URL=https://tu-dominio.com/api
VITE_GOOGLE_MAPS_API_KEY=tu_clave_de_google_maps
```

La clave de Google Maps es necesaria para el autocompletado de ubicaciones.

### Paso 2: Verificar el Service Worker

El archivo `frontend/public/sw.js` debe estar en la raíz del directorio público. Esto es crucial para que las notificaciones push funcionen.

## Configuración del Cron Job

El script de procesamiento de notificaciones debe ejecutarse automáticamente una vez al día.

### Opción 1: Desde línea de comandos (recomendado)

Edita el crontab:

```bash
crontab -e
```

Agrega la siguiente línea para ejecutar el script todos los días a las 9:00 AM:

```cron
0 9 * * * php /ruta/completa/al/proyecto/api/notifications/process-daily.php
```

### Opción 2: Mediante URL (alternativa)

Si no tienes acceso a cron jobs desde CLI, puedes usar un servicio de cron HTTP como [cron-job.org](https://cron-job.org):

URL: `https://tu-dominio.com/api/notifications/process-daily.php?cron_key=TU_CRON_SECRET_KEY`

**Importante**: Reemplaza `TU_CRON_SECRET_KEY` con el valor que definiste en `config.php`.

### Verificar que el cron funciona

Puedes ejecutar el script manualmente para probar:

```bash
php /ruta/completa/al/proyecto/api/notifications/process-daily.php
```

O desde el navegador:

```
https://tu-dominio.com/api/notifications/process-daily.php?cron_key=TU_CRON_SECRET_KEY
```

## Uso del Sistema

### Para Usuarios

1. **Configurar Ubicación**:
   - Ve a "Configuración" desde el Dashboard
   - Selecciona "Mi Ubicación"
   - Usa tu ubicación actual o busca una dirección
   - Guarda los cambios

2. **Seguir una Página**:
   - Visita cualquier página pública
   - Haz clic en el botón "Seguir"
   - Configura tus preferencias:
     - Todos los eventos: Recibirás todas las publicaciones
     - Solo eventos cercanos: Define el radio máximo en km

3. **Gestionar Páginas que Sigues**:
   - Ve a "Configuración" → "Páginas que Sigo"
   - Edita las preferencias de cada página
   - Deja de seguir páginas que ya no te interesan

4. **Activar Notificaciones Push**:
   - Ve a "Configuración" → "Notificaciones"
   - Activa el interruptor de "Notificaciones Push"
   - Acepta los permisos en tu navegador

5. **Ver Notificaciones**:
   - Haz clic en el icono de campana en el Dashboard
   - Verás todas tus notificaciones no leídas
   - Haz clic en una notificación para ir a la página

### Para Administradores

1. **Monitorear el Cron Job**:
   - Revisa los logs del servidor para ver si el cron se ejecuta correctamente
   - El script imprime un resumen de eventos procesados y notificaciones enviadas

2. **Revisar Suscripciones Push**:
   ```sql
   SELECT COUNT(*) as total_subscriptions FROM push_subscriptions;
   ```

3. **Ver Estadísticas de Seguimiento**:
   ```sql
   SELECT p.title, COUNT(pf.id) as followers
   FROM pages p
   LEFT JOIN page_followers pf ON p.id = pf.page_id
   GROUP BY p.id
   ORDER BY followers DESC;
   ```

## Solución de Problemas

### Las notificaciones no se envían

1. **Verifica que el cron job se está ejecutando**:
   ```bash
   grep CRON /var/log/syslog
   ```

2. **Revisa que hay eventos nuevos**:
   ```sql
   SELECT COUNT(*) FROM links
   WHERE is_event = 1
   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
   ```

3. **Verifica que hay usuarios siguiendo páginas**:
   ```sql
   SELECT COUNT(*) FROM page_followers;
   ```

### Las notificaciones push no funcionan

1. **Verifica que el sitio usa HTTPS** - Las notificaciones push solo funcionan en HTTPS

2. **Verifica que las claves VAPID están correctamente configuradas**:
   ```bash
   php -r "echo defined('VAPID_PUBLIC_KEY') ? 'OK' : 'NO CONFIGURADO';" -c api/config.php
   ```

3. **Revisa la consola del navegador** - Debe mostrar si el Service Worker se registró correctamente

4. **Verifica los permisos del navegador** - En Chrome: Configuración → Privacidad y seguridad → Configuración del sitio → Notificaciones

### Las notificaciones duplicadas

El sistema verifica automáticamente que no exista ya una notificación para el mismo evento y usuario antes de crear una nueva.

Si aún así hay duplicados, verifica que el índice único esté creado:

```sql
SHOW INDEX FROM notifications WHERE Column_name = 'link_id';
```

### Error de distancia en eventos cercanos

Si los usuarios no reciben eventos cercanos:

1. Verifica que los eventos tienen coordenadas:
   ```sql
   SELECT COUNT(*) FROM links
   WHERE is_event = 1
   AND event_latitude IS NOT NULL
   AND event_longitude IS NOT NULL;
   ```

2. Verifica que los usuarios tienen ubicación configurada:
   ```sql
   SELECT COUNT(*) FROM users
   WHERE location_latitude IS NOT NULL
   AND location_longitude IS NOT NULL;
   ```

## Arquitectura del Sistema

### Flujo de Datos

```
1. Usuario crea un evento en su página
   ↓
2. Evento se almacena en la base de datos
   ↓
3. Cron job se ejecuta diariamente (9:00 AM)
   ↓
4. Script busca eventos creados en las últimas 24 horas
   ↓
5. Para cada evento, encuentra usuarios que siguen esa página
   ↓
6. Aplica filtros según preferencias del usuario:
   - Si notify_all_events = true → Crear notificación
   - Si notify_all_events = false → Calcular distancia
     ↓
     Si distancia <= max_distance_km → Crear notificación
   ↓
7. Crear registro en tabla notifications
   ↓
8. Si el usuario tiene suscripciones push activas:
   - Enviar notificación push al navegador
```

### APIs Disponibles

#### Ubicación del Usuario
- `GET /api/users/location.php` - Obtener ubicación actual
- `PUT /api/users/location.php` - Actualizar ubicación

#### Seguimiento de Páginas
- `GET /api/pages/following.php` - Listar páginas que sigue
- `GET /api/pages/follow.php?page_id=X` - Ver estado de seguimiento
- `POST /api/pages/follow.php` - Seguir página o actualizar preferencias
- `DELETE /api/pages/follow.php?page_id=X` - Dejar de seguir página

#### Notificaciones
- `GET /api/notifications/index.php` - Listar notificaciones
- `PUT /api/notifications/index.php` - Marcar como leídas
- `DELETE /api/notifications/index.php` - Eliminar notificaciones

#### Suscripciones Push
- `GET /api/notifications/subscribe.php` - Obtener clave pública VAPID
- `POST /api/notifications/subscribe.php` - Registrar suscripción
- `DELETE /api/notifications/subscribe.php` - Eliminar suscripción

## Seguridad

### Consideraciones Importantes

1. **Autenticación**: Todas las APIs requieren token JWT válido
2. **HTTPS**: Obligatorio para notificaciones push
3. **Validación de datos**: Todas las entradas se validan y sanitizan
4. **Protección del cron**: El endpoint del cron requiere clave secreta
5. **Rate limiting**: Considera implementar límites de solicitudes

### Mejores Prácticas

1. No exponer las claves VAPID privadas en el frontend
2. Usar HTTPS en producción
3. Mantener la librería web-push actualizada
4. Monitorear los logs del cron regularmente
5. Implementar limpieza de notificaciones antiguas

## Mantenimiento

### Limpieza de Notificaciones Antiguas

Considera agregar un script para limpiar notificaciones leídas de más de 30 días:

```sql
DELETE FROM notifications
WHERE is_read = 1
AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Monitoreo de Rendimiento

Consultas útiles para monitorear el sistema:

```sql
-- Notificaciones no leídas por usuario
SELECT u.email, COUNT(n.id) as unread_count
FROM users u
LEFT JOIN notifications n ON u.id = n.user_id AND n.is_read = 0
GROUP BY u.id
ORDER BY unread_count DESC;

-- Páginas más seguidas
SELECT p.title, COUNT(pf.id) as follower_count
FROM pages p
LEFT JOIN page_followers pf ON p.id = pf.page_id
GROUP BY p.id
ORDER BY follower_count DESC
LIMIT 10;

-- Eventos con más notificaciones generadas
SELECT l.title, COUNT(n.id) as notification_count
FROM links l
LEFT JOIN notifications n ON l.id = n.link_id
WHERE l.is_event = 1
GROUP BY l.id
ORDER BY notification_count DESC
LIMIT 10;
```

## Soporte

Para más ayuda o reportar problemas, consulta la documentación completa del proyecto o contacta al equipo de desarrollo.
