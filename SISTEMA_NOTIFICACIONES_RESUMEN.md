# Sistema de Notificaciones - Resumen

## Nuevas Funcionalidades Implementadas

### 1. Ubicación de Usuarios
Los usuarios pueden definir su ubicación principal de dos formas:
- **Ubicación automática**: Usando la geolocalización del navegador
- **Búsqueda manual**: Mediante autocompletado de Google Places

La ubicación se usa para filtrar eventos cercanos según las preferencias del usuario.

### 2. Seguimiento de Páginas
Los usuarios pueden seguir páginas públicas con dos opciones de notificación:

#### Opción A: Todos los eventos
El usuario recibe notificaciones de TODOS los eventos publicados por la página, sin importar la ubicación.

#### Opción B: Solo eventos cercanos
El usuario define un radio máximo en kilómetros (por ejemplo, 50km) y solo recibe notificaciones de eventos dentro de ese radio desde su ubicación.

### 3. Sistema de Notificaciones Dual

#### Bandeja de Notificaciones (Web)
- Icono de campana en el header del Dashboard
- Contador de notificaciones no leídas
- Panel desplegable con historial de notificaciones
- Opción para marcar como leídas o eliminar

#### Notificaciones Push del Navegador
- Notificaciones nativas del navegador
- Funcionan incluso si la aplicación está cerrada
- Se pueden activar/desactivar desde Configuración
- Requieren permiso del usuario

### 4. Procesamiento Automático
Un script cron se ejecuta diariamente (recomendado: 9:00 AM) que:
- Busca eventos creados en las últimas 24 horas
- Identifica usuarios que siguen esas páginas
- Aplica filtros según preferencias (todos los eventos o cercanos)
- Calcula distancias usando fórmula de Haversine
- Crea notificaciones en la base de datos
- Envía notificaciones push a usuarios suscritos

## Archivos Nuevos Creados

### Base de Datos
- `migration_add_notifications_system.sql` - Migración completa

### Backend (API)
- `api/users/location.php` - Gestión de ubicación del usuario
- `api/pages/follow.php` - Seguir/dejar de seguir páginas
- `api/pages/following.php` - Listar páginas seguidas
- `api/notifications/index.php` - CRUD de notificaciones
- `api/notifications/subscribe.php` - Gestión de suscripciones push
- `api/notifications/process-daily.php` - Script cron para procesamiento diario
- `api/generate-vapid-keys.php` - Utilidad para generar claves VAPID

### Frontend (Componentes)
- `src/components/NotificationBell.jsx` - Icono de campana con dropdown
- `src/components/LocationSettings.jsx` - Configuración de ubicación
- `src/components/FollowingManager.jsx` - Gestión de páginas seguidas
- `src/components/FollowButton.jsx` - Botón para seguir páginas
- `src/utils/pushNotifications.js` - Helper para notificaciones push

### Frontend (Páginas)
- `src/pages/Settings.jsx` - Página de configuración completa

### Frontend (Service Worker)
- `public/sw.js` - Service Worker para notificaciones push

### Documentación
- `GUIA_NOTIFICACIONES.md` - Guía completa de instalación y uso
- `SISTEMA_NOTIFICACIONES_RESUMEN.md` - Este archivo

## Configuración Actualizada

### config.php
Se agregaron nuevas constantes:
```php
define('VAPID_PUBLIC_KEY', '...');
define('VAPID_PRIVATE_KEY', '...');
define('VAPID_SUBJECT', 'mailto:...');
define('CRON_SECRET_KEY', '...');
```

### Plantillas Actualizadas
Todas las plantillas de página pública ahora incluyen el botón "Seguir":
- `MinimalTemplate.jsx`
- `CardsTemplate.jsx`
- `ModernTemplate.jsx`
- `CondensedTemplate.jsx`

### Dashboard Actualizado
- Ahora incluye el componente NotificationBell
- Enlace a la página de Configuración

## Flujo de Uso

### Usuario Nuevo
1. Usuario crea cuenta e inicia sesión
2. Va a Configuración → Mi Ubicación
3. Define su ubicación (actual o manual)
4. Visita páginas públicas de interés
5. Hace clic en "Seguir" en cada página
6. Configura preferencias (todos los eventos o cercanos)
7. Opcionalmente, activa notificaciones push en Configuración → Notificaciones

### Cuando se Publica un Evento
1. Creador publica evento con ubicación
2. Sistema espera hasta el próximo cron (9:00 AM)
3. Script procesa el evento:
   - Encuentra seguidores de esa página
   - Aplica filtros según preferencias
   - Crea notificaciones
   - Envía push a suscritos
4. Usuario ve notificación:
   - En el icono de campana (si está en la app)
   - Como notificación push del navegador
5. Usuario hace clic y va a la página del evento

## Requisitos de Instalación

### Dependencias PHP
```bash
composer require minishlink/web-push
```

### Variables de Entorno
- Claves VAPID (generadas con `generate-vapid-keys.php`)
- Google Maps API Key (para autocompletado de ubicaciones)
- Clave secreta para cron

### Cron Job
```bash
0 9 * * * php /ruta/al/proyecto/api/notifications/process-daily.php
```

### HTTPS
**Obligatorio** para notificaciones push del navegador.

## Próximos Pasos (Opcionales)

### Mejoras Sugeridas
1. **Notificaciones en tiempo real**: Usar WebSockets para notificaciones instantáneas
2. **Email notifications**: Opción adicional para recibir resumen por email
3. **Preferencias granulares**: Horarios preferidos para recibir notificaciones
4. **Categorías de eventos**: Filtrar por tipo de evento (música, deportes, etc.)
5. **Notificaciones de recordatorio**: Avisar X días antes del evento
6. **Analytics**: Dashboard con estadísticas de notificaciones

### Optimizaciones
1. **Cache**: Implementar cache para las consultas de distancia
2. **Queue system**: Usar una cola para enviar notificaciones push
3. **Batch processing**: Agrupar notificaciones del mismo usuario
4. **Database indexing**: Optimizar índices según patrones de consulta

## Soporte Técnico

Para más detalles, consulta la guía completa: `GUIA_NOTIFICACIONES.md`

### Preguntas Frecuentes

**P: ¿Las notificaciones son en tiempo real?**
R: No, se envían una vez al día mediante cron job. Para tiempo real necesitarías WebSockets.

**P: ¿Funcionan las notificaciones push en iOS?**
R: Sí, pero solo en Safari 16.4+ y requieren que la app esté instalada como PWA.

**P: ¿Puedo cambiar la hora del cron?**
R: Sí, edita el crontab y cambia el horario. Se recomienda horario de baja actividad.

**P: ¿Qué pasa si un usuario cambia su ubicación?**
R: La nueva ubicación se usa para las próximas notificaciones. Las anteriores no se recalculan.

**P: ¿Las notificaciones caducan?**
R: Se recomienda implementar limpieza de notificaciones leídas después de 30 días.
