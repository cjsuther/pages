# Gestor de Páginas Personales - Resumen del Proyecto

## Descripción General

Sistema completo de gestión de páginas personales donde cada usuario puede crear múltiples páginas con enlaces organizados en grupos, completamente personalizables en colores.

## Tecnologías Utilizadas

### Backend
- **PHP 7.4+**: Lenguaje del servidor
- **MySQL**: Base de datos relacional
- **JWT**: Autenticación basada en tokens
- **PDO**: Conexión segura a base de datos con prepared statements

### Frontend
- **React 18**: Librería de interfaz de usuario
- **React Router**: Navegación entre páginas
- **Tailwind CSS**: Framework de estilos
- **Vite**: Build tool y dev server

## Arquitectura

```
┌─────────────────┐
│   Frontend      │
│   (React)       │
└────────┬────────┘
         │ HTTP/REST
         │ JSON
┌────────▼────────┐
│   Backend API   │
│   (PHP)         │
└────────┬────────┘
         │ PDO
┌────────▼────────┐
│   MySQL DB      │
└─────────────────┘
```

## Base de Datos

### Tablas

1. **users** - Usuarios del sistema
   - id, email, password, created_at, updated_at

2. **pages** - Páginas personales
   - id, user_id, title, description, url_slug
   - primary_color, secondary_color, background_color, text_color
   - created_at, updated_at

3. **link_groups** - Grupos de enlaces
   - id, page_id, title, position, created_at, updated_at

4. **links** - Enlaces individuales
   - id, group_id, url, text, image_url, description, position
   - created_at, updated_at

### Relaciones

- Un usuario puede tener muchas páginas
- Una página puede tener muchos grupos
- Un grupo puede tener muchos links
- Cascada de eliminación en todas las relaciones

## Funcionalidades Principales

### Para Usuarios

1. **Autenticación**
   - Registro con email y contraseña
   - Login con JWT token
   - Sesión persistente en localStorage

2. **Gestión de Páginas**
   - Crear páginas con URL personalizada (slug)
   - Editar título y descripción
   - Eliminar páginas
   - Ver todas sus páginas en el dashboard

3. **Personalización de Colores**
   - Color primario (títulos principales)
   - Color secundario (subtítulos y enlaces)
   - Color de fondo
   - Color de texto
   - Vista previa en tiempo real

4. **Grupos de Enlaces**
   - Crear múltiples grupos por página
   - Organizar enlaces por categoría
   - Títulos personalizados para cada grupo
   - Eliminar grupos (y todos sus enlaces)

5. **Enlaces**
   - URL del enlace (requerido)
   - Texto descriptivo (requerido)
   - Imagen opcional (URL)
   - Descripción opcional
   - Eliminar enlaces individuales

6. **Vista Pública**
   - Página pública accesible sin autenticación
   - URL amigable: `/tu-url-personalizada`
   - Diseño responsive
   - Colores personalizados aplicados
   - Efectos hover y transiciones

### Seguridad Implementada

- Contraseñas hasheadas con bcrypt
- Tokens JWT con expiración de 24 horas
- Prepared statements (protección SQL injection)
- Validación de entrada en todos los endpoints
- Headers CORS configurados
- Verificación de propiedad en operaciones CRUD

## Endpoints de la API

### Públicos (sin autenticación)

- `POST /api/auth/register.php` - Crear cuenta
- `POST /api/auth/login.php` - Iniciar sesión
- `GET /api/public/page.php?slug={slug}` - Ver página pública

### Privados (requieren token)

**Páginas:**
- `GET /api/pages/index.php` - Listar páginas del usuario
- `POST /api/pages/index.php` - Crear página
- `GET /api/pages/detail.php?id={id}` - Obtener detalle de página
- `PUT /api/pages/detail.php?id={id}` - Actualizar página
- `DELETE /api/pages/detail.php?id={id}` - Eliminar página

**Grupos:**
- `POST /api/groups/index.php` - Crear grupo
- `PUT /api/groups/detail.php?id={id}` - Actualizar grupo
- `DELETE /api/groups/detail.php?id={id}` - Eliminar grupo

**Enlaces:**
- `POST /api/links/index.php` - Crear enlace
- `PUT /api/links/detail.php?id={id}` - Actualizar enlace
- `DELETE /api/links/detail.php?id={id}` - Eliminar enlace

## Flujo de Uso

1. Usuario se registra (`/register`)
2. Sistema crea cuenta y devuelve JWT token
3. Usuario es redirigido al Dashboard
4. Usuario crea una nueva página con:
   - Título: "Mi Portfolio"
   - Descripción: "Enlaces a mis proyectos"
   - URL: "mi-portfolio"
5. Usuario personaliza colores de la página
6. Usuario crea grupos:
   - "Redes Sociales"
   - "Proyectos"
   - "Contacto"
7. Usuario agrega enlaces a cada grupo con sus respectivas URLs, textos e imágenes
8. Usuario comparte la URL pública: `/mi-portfolio`
9. Visitantes pueden ver la página sin necesidad de registrarse

## Características Destacadas

- Interface limpia y moderna
- Diseño completamente responsive
- Edición en tiempo real
- Sin recarga de página (SPA)
- Feedback visual en todas las acciones
- Modales para crear nuevos elementos
- Confirmación antes de eliminar
- URLs amigables y personalizables
- Sistema de colores con picker visual
- Soporte para imágenes en enlaces
- Efectos de hover y transiciones suaves

## Archivos de Configuración

- `api/config.php` - Configuración de base de datos y JWT
- `frontend/vite.config.js` - Configuración de Vite y proxy
- `frontend/tailwind.config.js` - Configuración de Tailwind CSS
- `.htaccess` - Configuración de rutas para Apache

## Despliegue

### Desarrollo Local

1. Backend: `php -S localhost:8000` en carpeta `api`
2. Frontend: `npm run dev` en carpeta `frontend`
3. Acceder a: `http://localhost:3000`

### Producción

1. Importar `database.sql` en MySQL
2. Configurar `api/config.php` con credenciales
3. Copiar carpeta `api` al servidor web
4. Compilar frontend: `npm run build`
5. Copiar contenido de `dist` al servidor
6. Asegurar que `mod_rewrite` esté habilitado
7. Configurar `.htaccess` para rutas

## Mejoras Futuras Posibles

- Subida de imágenes al servidor
- Reordenamiento drag & drop de grupos y enlaces
- Temas predefinidos
- Exportar/importar páginas
- Estadísticas de visitas
- Múltiples páginas de perfil
- Integración con redes sociales
- Editor WYSIWYG para descripciones
- Modo oscuro
- Compartir en redes sociales
- Códigos QR para páginas

## Soporte

Consulta los archivos:
- `README.md` - Documentación completa
- `QUICKSTART.md` - Inicio rápido
- Código comentado en los archivos PHP y JSX

## Licencia

Código libre para usar, modificar y distribuir.
