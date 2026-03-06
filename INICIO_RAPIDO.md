# Inicio Rápido - Desarrollo Local

## Modo Rápido (Recomendado)

### Linux/Mac

```bash
# 1. Ir al directorio del proyecto
cd php-mysql-version

# 2. Configurar (solo primera vez)
cp api/config.example.php api/config.php
# Edita api/config.php con tus credenciales de MySQL

# 3. Ejecutar todo con un solo comando
./dev.sh
```

### Windows

```bash
# 1. Ir al directorio del proyecto
cd php-mysql-version

# 2. Configurar (solo primera vez)
copy api\config.example.php api\config.php
# Edita api\config.php con tus credenciales de MySQL

# 3. Ejecutar todo con un solo comando
dev.bat
```

## Modo Manual (3 Terminales)

Si prefieres control total o el script no funciona:

### Terminal 1: Base de Datos (primera vez)

```bash
mysql -u root -p
CREATE DATABASE personal_pages;
exit;

mysql -u root -p personal_pages < database.sql
```

### Terminal 2: API Backend

```bash
cd php-mysql-version/api
php -S localhost:8000
```

### Terminal 3: Frontend

```bash
cd php-mysql-version/frontend
npm install  # Solo primera vez
npm run dev
```

## URLs

| Servicio | URL |
|----------|-----|
| 🌐 **Aplicación** | http://localhost:3000 |
| 📡 **API** | http://localhost:8000 |
| 🔐 **Login** | http://localhost:3000/login |
| 📝 **Registro** | http://localhost:3000/register |

## Verificación Rápida

```bash
# Probar que la API funciona
curl http://localhost:8000

# Debería responder algo (aunque sea un error 404 es OK)
```

## Problemas Comunes

### Puerto ocupado

```bash
# Ver qué usa el puerto
lsof -i :3000
lsof -i :8000

# Usar otro puerto
php -S localhost:8080  # API
```

### Error de MySQL

Edita `api/config.php` con las credenciales correctas:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'personal_pages');
define('DB_USER', 'root');
define('DB_PASS', 'tu_password_mysql');
```

### Error "Module not found"

```bash
cd frontend
rm -rf node_modules package-lock.json
npm install
```

## ¿Qué Sigue?

1. ✅ Registra una cuenta en http://localhost:3000/register
2. ✅ Crea tu primera página
3. ✅ Personaliza con diferentes templates
4. 📖 Lee **DESARROLLO_LOCAL.md** para detalles completos
5. 🎨 Revisa **EJEMPLOS_DE_USO.md** para inspiración

## Documentación Completa

- **DESARROLLO_LOCAL.md** - Guía detallada de desarrollo
- **CONFIGURACION_SEO.md** - Configurar meta tags para redes sociales
- **README.md** - Documentación técnica completa
- **INDICE_DOCUMENTACION.md** - Índice de toda la documentación

## Detener los Servicios

- **Script**: Presiona `Ctrl+C`
- **Manual**: Presiona `Ctrl+C` en cada terminal

---

**¿Problemas?** Consulta **DESARROLLO_LOCAL.md** o **FAQ.md**
