# Configuración de Google Analytics

Este documento explica cómo configurar Google Analytics para rastrear eventos y vistas de página en el proyecto.

## Configuración Inicial

### 1. Obtener el ID de Medición de Google Analytics

1. Ve a [Google Analytics](https://analytics.google.com/)
2. Crea una cuenta o inicia sesión
3. Crea una propiedad nueva
4. Selecciona "Web" como plataforma
5. Copia tu **Measurement ID** (formato: `G-XXXXXXXXXX`)

### 2. Configurar el ID en el Proyecto

#### En el Frontend

1. Crea un archivo `.env` en la carpeta `/frontend`:

```bash
cd frontend
cp .env.example .env
```

2. Edita el archivo `.env` y agrega tu Measurement ID:

```
VITE_GA_MEASUREMENT_ID=G-TU_ID_AQUI
```

3. Reemplaza el ID en el archivo `index.html`:

Busca esta línea en `/frontend/index.html`:

```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
```

Y reemplaza `G-XXXXXXXXXX` con tu Measurement ID real.

## Eventos Rastreados

El sistema rastrea automáticamente los siguientes eventos:

### Autenticación
- `login_attempt` - Intento de inicio de sesión (Google/Apple)
- `login` - Inicio de sesión exitoso
- `sign_up` - Registro de usuario exitoso
- `register_attempt` - Intento de registro (Google/Apple)

### Páginas
- `page_view` - Vista de página (automático en cada cambio de ruta)
- `view_public_page` - Vista de página pública por slug
- `create_page` - Creación de nueva página
- `edit_page` - Edición de página

### Enlaces y Contenido
- `click_link` - Clic en un enlace
- `add_link` - Agregar nuevo enlace
- `view_event_modal` - Visualización del modal de evento
- `view_image_modal` - Visualización del modal de imagen

### Eventos y Búsqueda
- `add_event` - Agregar nuevo evento
- `share_event` - Compartir evento
- `search` - Búsqueda de páginas
- `view_map` - Visualización del mapa
- `map_interaction` - Interacción con el mapa

### Configuración
- `change_template` - Cambio de plantilla
- `upload_image` - Subida de imagen

## Uso de la API de Analytics

### Importar la Utilidad

```javascript
import { trackEvent } from '../utils/analytics';
```

### Trackear Eventos Personalizados

```javascript
// Evento simple
trackEvent.event('nombre_evento', {
  parametro1: 'valor1',
  parametro2: 'valor2'
});

// Eventos predefinidos
trackEvent.userLogin('google');
trackEvent.clickLink('https://ejemplo.com', 'Mi Enlace');
trackEvent.viewPublicPage('mi-slug');
trackEvent.searchPages('término de búsqueda');
```

### Eventos Disponibles

```javascript
// Autenticación
trackEvent.userLogin(method)         // method: 'email', 'google', 'apple', 'oauth'
trackEvent.userRegister(method)      // method: 'email', 'google', 'apple', 'oauth'

// Páginas
trackEvent.createPage(pageId)
trackEvent.editPage(pageId)
trackEvent.viewPublicPage(slug)

// Enlaces
trackEvent.clickLink(url, title)
trackEvent.addLink(linkType)

// Eventos
trackEvent.addEvent(eventId)
trackEvent.shareEvent(eventId, shareMethod)

// Búsqueda y Mapa
trackEvent.searchPages(query)
trackEvent.viewMap()
trackEvent.interactMap(action)

// Configuración
trackEvent.changeTemplate(templateName)
trackEvent.uploadImage(imageType)

// Evento personalizado
trackEvent.event(eventName, params)
```

## Verificación

Para verificar que Google Analytics está funcionando correctamente:

1. Abre tu sitio en el navegador
2. Navega por diferentes páginas
3. Ve a [Google Analytics](https://analytics.google.com/)
4. En el menú lateral, selecciona **Informes** > **Tiempo real**
5. Deberías ver tu actividad en tiempo real

## Debugging

Para verificar que los eventos se están enviando correctamente:

1. Abre las DevTools del navegador (F12)
2. Ve a la pestaña **Network**
3. Filtra por "collect" o "analytics"
4. Realiza acciones en tu sitio
5. Deberías ver peticiones a `www.google-analytics.com/collect`

También puedes instalar la extensión [Google Analytics Debugger](https://chrome.google.com/webstore/detail/google-analytics-debugger) para Chrome.

## Privacidad

Google Analytics respeta la privacidad de los usuarios. Por defecto:
- No se rastrea información personal identificable
- Se respetan las configuraciones de "Do Not Track" del navegador
- Los eventos solo contienen información agregada y anónima

Si necesitas ajustar la configuración de privacidad, edita el archivo `/frontend/src/utils/analytics.js`.
