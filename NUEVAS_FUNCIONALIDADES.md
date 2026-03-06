# Nuevas Funcionalidades

## Resumen de Cambios

Se han agregado nuevas funcionalidades al gestor de páginas personales:

## ÚLTIMA ACTUALIZACIÓN: Tipos de Grupos

### Tres Tipos de Grupos Disponibles

Ahora los grupos pueden ser de tres tipos diferentes:

1. **Links** (predeterminado) - Lista tradicional de enlaces
2. **Galería** - Cuadrícula de imágenes estilo portafolio
3. **Eventos** - Tarjetas de eventos con fecha, hora, ubicación y descripción

Consulta [TIPOS_DE_GRUPOS.md](./TIPOS_DE_GRUPOS.md) para documentación completa de cada tipo.

### 1. Menú de Navegación en Páginas Públicas
- Menú sticky en la parte superior con todos los grupos
- Navegación suave al hacer click en cada sección
- Resaltado automático de la sección actual al hacer scroll
- Diseño responsive con scroll horizontal en móviles

### 2. Imagen de Perfil
- Campo para subir imagen de perfil en el editor
- Se muestra en la página pública como avatar circular
- Tamaño: 32x32 píxeles en móvil, 40x40 en desktop
- Validación: máximo 5MB, formatos JPG, PNG, GIF, WebP

### 3. Imagen de Fondo
- Campo para subir imagen de fondo en el editor
- Se muestra como fondo de pantalla completa en la página pública
- Efecto parallax con `background-attachment: fixed`
- Filtro de oscurecimiento automático para mejorar legibilidad

### 4. Subida de Imágenes para Links
- Opción de subir imágenes desde el dispositivo
- Alternativa: ingresar URL de imagen externa
- Vista previa instantánea al subir
- Mismas validaciones que otras imágenes

### 5. Validación de URLs Reservadas
Se agregó validación para evitar que se usen URLs del sistema:
- login, register, dashboard, page, api, admin, auth
- public, pages, groups, links, user, users
- config, settings, logout, profile, account

### 6. Reordenamiento de Grupos y Links
- Botones ↑ y ↓ para cambiar el orden
- Los botones se deshabilitan en los extremos
- Orden se guarda automáticamente en la base de datos

## Migración de Base de Datos

Ejecuta el siguiente SQL para actualizar la base de datos:

```sql
ALTER TABLE pages
ADD COLUMN profile_image VARCHAR(500) AFTER text_color,
ADD COLUMN background_image VARCHAR(500) AFTER profile_image;
```

O ejecuta el archivo:
```bash
mysql -u usuario -p nombre_db < migration_add_images.sql
```

## Configuración del Servidor

### 1. Crear Directorio de Uploads

El directorio `api/uploads/` debe tener permisos de escritura:

```bash
chmod 755 api/uploads
```

### 2. Configurar Apache

Asegúrate de que estos módulos estén habilitados:
```bash
sudo a2enmod headers
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 3. Límite de Subida de Archivos

Verifica el límite de subida en `php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 10M
```

## Validaciones Implementadas

### Backend (PHP)

En `api/upload/image.php`:
- Tipo de archivo: solo JPG, PNG, GIF, WebP
- Tamaño máximo: 5MB
- Verificación con `getimagesize()` para confirmar que es una imagen
- Nombres únicos con `uniqid()` y timestamp

### Frontend (React)

En `PageEditor.jsx`:
- Validación de tamaño antes de subir (5MB)
- Validación de tipo de archivo
- Mensajes de error claros para el usuario
- Indicador de "Subiendo..." durante el proceso

## Endpoints de la API

### Nuevo Endpoint

**POST /api/upload/image.php**
- Requiere autenticación (JWT token)
- Parámetro: `image` (archivo)
- Respuesta exitosa:
  ```json
  {
    "url": "/api/uploads/nombre-archivo.jpg"
  }
  ```
- Respuestas de error:
  - 400: Tipo de archivo inválido
  - 400: Archivo muy grande
  - 400: No es una imagen válida
  - 401: No autenticado
  - 500: Error al subir archivo

### Endpoints Actualizados

**POST /api/pages/index.php**
- Nuevos campos opcionales:
  - `profile_image` (string)
  - `background_image` (string)

**PUT /api/pages/detail.php**
- Campos actualizables:
  - `profile_image` (string)
  - `background_image` (string)

## Uso

### 1. Subir Imagen de Perfil

1. Ve al editor de página
2. En "Configuración de Página" encontrarás "Imagen de Perfil"
3. Click en "Elegir archivo" y selecciona una imagen
4. La imagen se sube automáticamente y se muestra una vista previa

### 2. Subir Imagen de Fondo

1. Similar a imagen de perfil
2. Se recomienda usar imágenes grandes (1920x1080 o superior)
3. El sistema aplica un filtro de oscurecimiento automático

### 3. Subir Imagen de Link

Al crear o editar un link:
1. En el campo "Imagen (opcional)"
2. Puedes subir un archivo O ingresar una URL
3. Vista previa se muestra inmediatamente

### 4. Navegar en Página Pública

1. El menú aparece automáticamente si hay grupos
2. Click en cualquier botón del menú para ir a esa sección
3. El botón activo se resalta automáticamente

## Estructura de Archivos

```
api/
├── upload/
│   └── image.php         # Nuevo endpoint de subida
├── uploads/              # Directorio de imágenes subidas
│   ├── .htaccess        # Configuración de acceso
│   └── .gitkeep         # Mantener directorio en Git
```

## Seguridad

### Validaciones Implementadas

1. **Autenticación**: Solo usuarios autenticados pueden subir imágenes
2. **Tipo de archivo**: Whitelist de tipos MIME permitidos
3. **Tamaño**: Límite de 5MB por archivo
4. **Verificación real**: `getimagesize()` confirma que es imagen válida
5. **Nombres únicos**: Previene sobrescritura de archivos

### Recomendaciones Adicionales

1. **En producción**, considera:
   - Usar CDN para servir imágenes
   - Implementar compresión automática de imágenes
   - Agregar protección contra hotlinking
   - Limitar cantidad de subidas por usuario/día

2. **Backup**: Incluye el directorio `api/uploads/` en tus backups

3. **Limpieza**: Implementa un sistema para eliminar imágenes huérfanas

## Mejoras Futuras Sugeridas

1. **Recorte de imágenes**: Editor en el frontend para recortar antes de subir
2. **Compresión automática**: Reducir tamaño de archivos automáticamente
3. **Múltiples tamaños**: Generar thumbnails y versiones optimizadas
4. **Galería**: Administrador de imágenes subidas
5. **Drag & Drop**: Arrastrar imágenes para subirlas
6. **Progreso de subida**: Barra de progreso durante la subida
7. **Caché de imágenes**: Headers de caché para mejor rendimiento

## Solución de Problemas

### Las imágenes no se suben

1. Verifica permisos del directorio `api/uploads/`:
   ```bash
   ls -la api/uploads/
   ```
   Debe mostrar permisos de escritura (755 o 775)

2. Verifica límites de PHP:
   ```bash
   php -i | grep upload_max_filesize
   php -i | grep post_max_size
   ```

3. Revisa logs de Apache:
   ```bash
   sudo tail -f /var/log/apache2/error.log
   ```

### Las imágenes no se muestran

1. Verifica la URL en la base de datos
2. Asegúrate de que el archivo existe en `api/uploads/`
3. Verifica que el `.htaccess` en uploads permita acceso
4. Revisa la consola del navegador (F12) para errores CORS

### Error 413 (Payload Too Large)

Aumenta límites en Apache (`/etc/apache2/apache2.conf`):
```apache
LimitRequestBody 10485760
```

Reinicia Apache:
```bash
sudo systemctl restart apache2
```

## Testing

Para probar las nuevas funcionalidades:

1. Registra un usuario de prueba
2. Crea una página
3. Sube imagen de perfil (prueba con diferentes tamaños)
4. Sube imagen de fondo
5. Crea varios grupos
6. Agrega links con imágenes subidas
7. Visita la página pública y prueba el menú de navegación
8. Prueba reordenar grupos y links
9. Verifica en móvil el scroll horizontal del menú

## Documentación Relacionada

- README.md - Documentación principal
- QUICKSTART.md - Guía de inicio rápido
- SEO_Y_MEJORAS.md - Sugerencias adicionales de mejoras
