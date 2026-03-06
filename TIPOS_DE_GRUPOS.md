# Tipos de Grupos

Esta guía explica los tres tipos de grupos disponibles y cómo utilizarlos.

## Resumen

El sistema ahora soporta tres tipos de grupos diferentes, cada uno con su propia presentación visual y campos específicos:

1. **Links** - Lista de enlaces tradicionales
2. **Galería** - Cuadrícula de imágenes
3. **Eventos** - Tarjetas de eventos con fecha, hora y ubicación

## Migración de Base de Datos

Antes de usar las nuevas funcionalidades, ejecuta la migración:

```bash
mysql -u usuario -p database < migration_add_group_types.sql
```

O ejecuta manualmente:

```sql
ALTER TABLE groups
ADD COLUMN type ENUM('links', 'galeria', 'eventos') DEFAULT 'links' AFTER title;

ALTER TABLE links
ADD COLUMN event_date DATE AFTER description,
ADD COLUMN event_time TIME AFTER event_date,
ADD COLUMN event_address VARCHAR(500) AFTER event_time,
ADD COLUMN event_address_link VARCHAR(1000) AFTER event_address;
```

## Tipo 1: Links

El tipo clásico de grupo, muestra enlaces en formato de lista vertical.

### Campos Requeridos
- **Texto**: Título del enlace
- **URL**: Dirección web del enlace

### Campos Opcionales
- **Imagen**: Ícono o imagen representativa (se muestra a la izquierda)
- **Descripción**: Texto explicativo debajo del título

### Presentación Visual
- Lista vertical con tarjetas
- Borde izquierdo de color secundario
- Imagen circular de 64x64px a la izquierda
- Título y descripción centrados
- Ícono de enlace externo a la derecha
- Efecto hover con escala y sombra

### Casos de Uso
- Portafolio de proyectos
- Redes sociales
- Enlaces a artículos
- Recursos y documentación
- Links de afiliados

### Ejemplo de Uso
```
Título: "Mi Portfolio en GitHub"
URL: https://github.com/usuario
Imagen: logo-github.png
Descripción: "Proyectos open source y contribuciones"
```

## Tipo 2: Galería

Muestra imágenes en cuadrícula, ideal para portafolios visuales o colecciones de fotos.

### Campos Requeridos
- **Imagen**: URL de la imagen (campo obligatorio)

### Campos Opcionales
- **Título**: Texto que aparece sobre la imagen al pasar el cursor
- **Link**: URL a la que redirige al hacer click (si no se proporciona, la imagen no es clickeable)

### Presentación Visual
- Cuadrícula responsive: 2 columnas en móvil, 3 en tablet, 4 en desktop
- Imágenes con altura fija de 192px (12rem)
- Efecto zoom al pasar el cursor
- Título en overlay negro semi-transparente en la parte inferior
- Sombra que se intensifica al hover

### Casos de Uso
- Portafolio de diseño gráfico
- Galería de fotografías
- Showcase de productos
- Colección de ilustraciones
- Trabajos realizados

### Ejemplo de Uso
```
Imagen: https://example.com/proyecto1.jpg
Título: "Rediseño de App Mobile"
Link: https://behance.net/proyecto (opcional)
```

## Tipo 3: Eventos

Tarjetas de eventos con información completa: fecha, hora, ubicación y descripción.

### Campos Requeridos
- **Nombre del Evento**: Título del evento
- **Fecha**: Fecha en formato YYYY-MM-DD

### Campos Opcionales
- **URL**: Link con más información del evento
- **Imagen**: Imagen promocional del evento
- **Hora**: Hora de inicio (formato 24h)
- **Dirección**: Ubicación física del evento
- **Link de Google Maps**: URL a Google Maps con la ubicación
- **Descripción**: Detalles adicionales del evento

### Presentación Visual
- Tarjetas amplias con diseño horizontal en desktop
- Imagen de 192x192px a la izquierda (ancho fijo 192px en desktop)
- Información del evento a la derecha
- Iconos para fecha/hora y ubicación
- Botón "Más información" si hay URL
- Link clickeable en la dirección si hay Google Maps
- Formato de fecha en español largo

### Formato de Fecha
La fecha se muestra en formato español completo:
```
"jueves, 14 de marzo de 2024"
```

### Casos de Uso
- Calendario de conferencias
- Agenda de talleres
- Próximos conciertos
- Eventos corporativos
- Webinars y meetups

### Ejemplo de Uso
```
Nombre: "Workshop de React Avanzado"
Fecha: 2024-03-14
Hora: 18:00
Dirección: "Av. Principal 123, Ciudad"
Link Google Maps: https://maps.google.com/?q=...
Descripción: "Aprende hooks avanzados, performance y patrones de diseño"
URL: https://evento.com/registro
Imagen: workshop-react.jpg
```

## Características Comunes

### En el Editor
- Badge de color indica el tipo de grupo:
  - Links: Azul
  - Galería: Morado
  - Eventos: Naranja
- Botón contextual: "+ Link", "+ Imagen", "+ Evento"
- Formulario adaptativo según el tipo seleccionado

### En la Página Pública
- Todos los tipos mantienen la identidad visual de la página (colores personalizados)
- Animaciones suaves y transiciones
- Responsive design en todos los tipos
- Efectos hover interactivos

## API Endpoints

### Crear Grupo
```
POST /api/groups/index.php

Body:
{
  "page_id": 1,
  "title": "Mis Eventos",
  "type": "eventos",
  "position": 0
}
```

### Actualizar Grupo
```
PUT /api/groups/detail.php?id=1

Body:
{
  "type": "galeria"
}
```

### Crear Link/Evento/Imagen
```
POST /api/links/index.php

Body (Links):
{
  "group_id": 1,
  "url": "https://example.com",
  "text": "Mi Link",
  "image_url": "...",
  "description": "..."
}

Body (Galería):
{
  "group_id": 2,
  "image_url": "https://example.com/image.jpg",
  "text": "Título opcional",
  "url": "https://link-opcional.com"
}

Body (Eventos):
{
  "group_id": 3,
  "text": "Nombre del Evento",
  "url": "https://evento.com",
  "image_url": "...",
  "description": "...",
  "event_date": "2024-03-14",
  "event_time": "18:00",
  "event_address": "Av. Principal 123",
  "event_address_link": "https://maps.google.com/..."
}
```

## Mejores Prácticas

### Links
- Usa imágenes cuadradas de al menos 128x128px
- Mantén las descripciones cortas (máximo 2 líneas)
- Agrupa enlaces relacionados en el mismo grupo

### Galería
- Usa imágenes con relación de aspecto similar
- Tamaño recomendado: 800x600px o mayor
- Optimiza las imágenes antes de subir
- Considera usar títulos descriptivos
- Las imágenes sin link son solo visuales

### Eventos
- Siempre incluye la fecha
- Usa imágenes horizontales (4:3 o 16:9)
- Proporciona dirección completa
- Agrega link de Google Maps para facilitar ubicación
- Incluye hora en formato 24h
- Descripción clara con información clave

## Tips de Diseño

### Organización de Grupos
1. Ordena cronológicamente los eventos (próximos primero)
2. Agrupa links por categoría (Social, Trabajo, Personal)
3. Separa galerías por proyecto o temática

### Nombres de Grupos
- Links: "Redes Sociales", "Proyectos", "Contacto"
- Galería: "Portfolio 2024", "Fotografías", "Diseños"
- Eventos: "Próximos Eventos", "Conferencias 2024"

### Combinación de Tipos
Puedes mezclar diferentes tipos en la misma página:
```
1. Grupo "Sobre Mí" (Links) - Bio y redes sociales
2. Grupo "Portfolio" (Galería) - Trabajos visuales
3. Grupo "Próximas Charlas" (Eventos) - Calendario
4. Grupo "Recursos" (Links) - Links útiles
```

## Solución de Problemas

### Las fechas se muestran en inglés
Verifica que el navegador soporte `toLocaleDateString('es-ES')`. La mayoría de navegadores modernos lo soportan.

### Las imágenes de galería tienen diferentes alturas
Asegúrate de usar `object-cover` en el CSS. Las imágenes se recortan automáticamente a 192px de altura.

### El tipo de grupo no se guarda
Verifica que hayas ejecutado la migración `migration_add_group_types.sql`.

### Los campos de eventos no aparecen
Asegúrate de haber ejecutado la migración que agrega las columnas `event_*` a la tabla `links`.

## Ejemplos Completos

### Página de Músico
```
Grupo 1: "Redes Sociales" (Links)
- Spotify, Apple Music, YouTube

Grupo 2: "Galería" (Galería)
- Fotos de conciertos y sesiones

Grupo 3: "Próximos Shows" (Eventos)
- Conciertos con fecha, lugar y venta de tickets
```

### Página de Conferenciante
```
Grupo 1: "Contacto" (Links)
- LinkedIn, Twitter, Email, Website

Grupo 2: "Charlas" (Eventos)
- Próximas conferencias y talleres

Grupo 3: "Material" (Links)
- Slides, videos, artículos
```

### Página de Fotógrafo
```
Grupo 1: "Portfolio" (Galería)
- Mejores trabajos

Grupo 2: "Exposiciones" (Eventos)
- Próximas exposiciones

Grupo 3: "Contacto" (Links)
- Instagram, web, email
```

## Actualizaciones Futuras Sugeridas

1. **Modo Carrusel para Galería**: Slider de imágenes en lugar de cuadrícula
2. **Eventos Pasados**: Separar eventos pasados y futuros automáticamente
3. **Calendario iCal**: Exportar eventos a calendario
4. **Lightbox**: Ver imágenes de galería en tamaño completo
5. **Filtros**: Filtrar eventos por fecha o categoría
6. **Mapas Embebidos**: Mostrar mapa de Google Maps directamente
7. **RSVP**: Sistema de confirmación de asistencia a eventos

## Compatibilidad

- ✅ Todos los navegadores modernos
- ✅ Responsive (móvil, tablet, desktop)
- ✅ Compatible con imágenes externas y subidas
- ✅ Accesibilidad básica (alt text, enlaces semánticos)
