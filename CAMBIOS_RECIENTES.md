# Cambios Recientes - Actualización del Sistema

## Resumen de Cambios Implementados

### 1. Edición de Links
- Ahora cada link tiene un botón "Editar" que permite modificar todos sus campos
- Modal de edición completo con todos los campos del link (texto, URL, imagen, descripción, datos del evento)

### 2. Solo Subida de Imágenes
- Se eliminó la posibilidad de agregar imágenes mediante URL manual
- Ahora solo se pueden subir imágenes desde el disco local
- Validación de archivos: JPG, PNG, GIF, WebP (máximo 5MB)

### 3. Edición de Títulos de Grupos
- Agregado botón "Editar Título" para cada grupo de links
- Modal simple que permite cambiar el nombre del grupo sin afectar su contenido

### 4. Google Maps Places Autocomplete
- Implementado autocompletado de Google Maps para direcciones de eventos
- Captura automática de:
  - Dirección formateada
  - Coordenadas GPS (latitud y longitud)
  - URL de Google Maps del lugar

### 5. Login Solo con OAuth
- Eliminado el login tradicional con usuario y contraseña
- Ahora solo se puede iniciar sesión con Google o Apple
- Interfaz simplificada y más segura

### 6. Indicadores de Carga
- Componente LoadingSpinner reutilizable
- Overlay global que muestra un spinner durante todas las llamadas al backend
- Mejora la experiencia del usuario al saber que se está procesando una acción

## Cambios en la Base de Datos

### Nueva Migración: `migration_add_event_location_fields.sql`
Agrega los siguientes campos a la tabla `links`:
- `event_latitude`: DECIMAL(10, 8) - Latitud del evento
- `event_longitude`: DECIMAL(11, 8) - Longitud del evento
- `event_maps_url`: TEXT - URL de Google Maps
- Índice en coordenadas para búsquedas eficientes

### Migración Previa: `migration_remove_event_address_link.sql`
- Elimina el campo `event_address_link` (ya no es necesario)

## Archivos Nuevos

1. `/frontend/src/components/LoadingSpinner.jsx` - Componente de carga reutilizable
2. `/frontend/src/components/GooglePlacesAutocomplete.jsx` - Componente de autocompletado de Google Maps
3. `migration_add_event_location_fields.sql` - Migración de campos GPS
4. `migration_remove_event_address_link.sql` - Migración para eliminar campo obsoleto

## Archivos Modificados

### Frontend
- `/frontend/index.html` - Agregado script de Google Maps API
- `/frontend/src/pages/PageEditor.jsx` - Agregadas funcionalidades de edición y Google Places
- `/frontend/src/pages/Login.jsx` - Simplificado a solo OAuth

### Backend
- `/api/links/index.php` - Soporte para campos GPS en creación
- `/api/links/detail.php` - Soporte para campos GPS en actualización
- `/api/public/events.php` - Eliminado campo event_address_link

## Configuración Requerida

### Google Maps API Key
Para que funcione el autocompletado de direcciones, necesitas configurar una API Key de Google Maps:

1. Ve a https://console.cloud.google.com/
2. Crea un nuevo proyecto o selecciona uno existente
3. Habilita las APIs:
   - Places API
   - Maps JavaScript API
   - Geocoding API
4. Crea una API Key
5. Reemplaza `YOUR_GOOGLE_MAPS_API_KEY` en `/frontend/index.html` con tu key:
   ```html
   <script src="https://maps.googleapis.com/maps/api/js?key=TU_API_KEY&libraries=places" async defer></script>
   ```

## Pendientes (No Implementados)

Los siguientes cambios fueron solicitados pero no están implementados aún:

1. **Búsqueda de Eventos por Proximidad en Home**
   - Solicitar ubicación del navegador
   - Mostrar eventos más cercanos basados en coordenadas GPS
   - Endpoint de eventos actualizado para soportar búsqueda por proximidad

2. **Actualización del componente Register**
   - Simplificar a solo OAuth (actualmente solo se actualizó Login)

## Instrucciones de Despliegue

1. Ejecutar migraciones de base de datos:
   ```sql
   -- Ejecutar en orden:
   source migration_remove_event_address_link.sql;
   source migration_add_event_location_fields.sql;
   ```

2. Configurar Google Maps API Key (ver sección anterior)

3. Build del frontend:
   ```bash
   cd frontend
   npm install
   npm run build
   ```

4. Los cambios están listos para producción

## Notas Importantes

- La migración GPS agrega campos NULL por defecto para mantener compatibilidad con eventos existentes
- Los eventos creados antes de la migración no tendrán coordenadas GPS hasta que se editen
- El autocompletado de Google Maps solo funciona si la API Key está configurada correctamente
- El login tradicional ha sido completamente eliminado por seguridad
