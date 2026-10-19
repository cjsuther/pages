# Rezon.ar — guía para Claude Code

Plataforma de eventos tipo linktree con venta de entradas (Mercado Pago), SSO, notificaciones push e integración MCP. Producción: rezon.ar y carcajada.rezon.ar (Hostinger, PHP 8.3, MariaDB 11.8). Dinero real de organizadores pasa por acá: cualquier cambio en cobros, entradas o QR es de alto riesgo.

## Estructura
- `/frontend` — React 18 + React Router 7, Vite 6, TailwindCSS 3, lucide-react. Service worker en `frontend/public/sw.js` (push). Tests en `frontend/tests/`.
- `/api` — PHP sin framework (8.3 en producción; `composer.json` acepta >=7.4). Un archivo `.php` por endpoint (`api/public/`, `api/entradas/`, `api/pages/`…) que carga `bootstrap.php` y llama a un handler: `Api::run(['CompartirHandler', 'venta'])`. Handlers en `api/handlers/`, clases compartidas en `api/lib/` (autoload en `lib/autoload.php`). JWT propio para login (`api/JWT.php`).
- `database.sql` y `migration_*.sql` — esquema base y migraciones sueltas, en la raíz del repo. El orden está en `migraciones.txt` (raíz), con rutas relativas a la raíz.
- `deploy.sh` — tests, build del frontend, backup, diff de esquema, rsync/SSH. **Nunca lo ejecutes.**
- `deploy-auto.sh` — corre `deploy.sh` solo cuando entra un commit a `main` con el CI en verde: **mergear un PR es desplegarlo a producción** en unos minutos. Por eso una migración nueva tiene que estar aplicada en producción antes de mergear (si no, `deploy.sh` frena el deploy).
- Importadores de agendas (Boletería, Niceto, Colón, C Art Media, Movistar Arena; Eventbrite está desactivado) en `api/lib/importadores/`, registrados en `api/lib/Importaciones.php`. Corren por cron con `api/importar/diario.php`. No cambies su contrato de salida sin un test que lo cubra.

## Configuración y entorno
- La API no lee variables de entorno ni `.env`: todo son constantes en `api/config.php` (base de datos `DB_HOST/DB_NAME/DB_USER/DB_PASS`, `JWT_SECRET`, VAPID, OAuth, Mercado Pago). Está en `.gitignore`; `composer install` lo crea desde `api/config.example.php` con un `JWT_SECRET` aleatorio (`api/bin/crear-config.php`). El `config.php` local no apunta a producción.
- Los tests no cargan `config.php`: `api/tests/bootstrap.php` define las mismas constantes con valores de prueba.
- Frontend: variables `VITE_*` (`VITE_API_URL`, `VITE_GA_MEASUREMENT_ID`, `VITE_GOOGLE_MAPS_API_KEY`) en `frontend/.env*`; ejemplos en `frontend/.env.example` y `frontend/.env.production.example`.

## Comandos
```bash
# API
cd api && composer install
cd api && vendor/bin/phpunit                          # unitarios (FakePdo) + integración (MariaDB temporal)
cd api && vendor/bin/phpunit --testsuite unit         # solo unitarios, rápido
cd api && vendor/bin/phpunit --testsuite integracion  # solo integración
cd api && php -d pcov.directory=$PWD vendor/bin/phpunit --coverage-text   # con cobertura (requiere pcov)

# Frontend
cd frontend && npm ci
cd frontend && npm test                               # Vitest + Testing Library
cd frontend && npm run test:coverage
cd frontend && npm run build
```
- Los tests de integración (`api/tests/Integration`, base `IntegracionTestCase`) levantan solos una MariaDB descartable en un directorio temporal y un puerto libre (`brew install mariadb`; no tocan ningún servidor instalado) y arman el esquema desde `migraciones.txt`. Para usar un servidor ya levantado: `RZ_TEST_DB_HOST` y `RZ_TEST_DB_PORT` (usuario `root` sin contraseña). Sin MariaDB se saltean, salvo con `RZ_EXIGIR_BASE=1`, que los hace fallar.

## Convenciones
- PHP: PDO con prepared statements siempre; nunca interpolar entrada del usuario en SQL. Validar y sanear toda entrada al inicio del endpoint. Respuestas JSON con códigos HTTP correctos.
- Nuevo endpoint = nuevo archivo `.php` siguiendo el patrón de los existentes + handler en `api/handlers/` + test unitario con FakePdo (`api/tests/Unit`). Si toca la base, también test de integración (`api/tests/Integration`): FakePdo reconoce las consultas por fragmentos de texto y nunca verifica que existan tablas o columnas.
- Cambios de esquema: nuevo `migration_<descripcion>.sql` en la raíz, agregarlo al final de `migraciones.txt` y regenerar el esquema esperado con `cd api && php tests/esquema.php generar` (si falta cualquiera de los dos pasos, falla un test). Nunca editar una migración ya existente. Las migraciones deben ser idempotentes cuando sea posible.
- Frontend: componentes funcionales con hooks, Tailwind para estilos (sin CSS suelto salvo que ya exista), íconos de lucide-react. Tests con Testing Library enfocados en comportamiento, no en implementación.
- Secretos y credenciales viven en `api/config.php` / archivos ignorados por git. Nunca los hardcodees ni los commitees (el repo es público). Nunca escribas claves de Mercado Pago, Google, Meta o VAPID en el código.
- Commits chicos, en español, en imperativo, referenciando el issue: `Corrige validación de cupón (#42)`.

## Zonas sensibles (pedir confirmación en el resumen final si las tocás)
- Flujo de pago y OAuth de Mercado Pago, liberación de fondos, comisiones.
- Generación y validación de QR de entradas, y envío de mails con PHPMailer.
- JWT: generación, verificación, expiración.
- `frontend/public/sw.js` y push (minishlink/web-push).
- Importadores de agendas y su cron.

## Definición de "hecho"
1. Hay test que cubre el cambio (bug → primero test que reproduce, después fix).
2. `phpunit` y `vitest` pasan completos.
3. No baja la cobertura.
4. Sin nuevos hallazgos de Semgrep.
5. Si hay migración, está en `migraciones.txt`, el esquema esperado está regenerado y es reversible o idempotente.
6. Resumen final de 5 líneas: qué cambió, qué riesgo ves, qué habría que verificar a mano en producción.

## Lo que NO hacer
- No ejecutar `deploy.sh` ni `deploy-auto.sh`, ni rsync, ni nada contra producción.
- No hacer push ni crear PRs: eso lo hace el runner.
- No modificar `main` directamente.
- No instalar dependencias nuevas de Composer o npm sin justificarlo en el resumen.
- Si el issue es ambiguo o requiere una decisión de producto, no inventar: explicar qué falta y terminar sin cambios.
