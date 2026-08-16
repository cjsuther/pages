#!/bin/bash
#
# Deployment a producción (rezon.ar, Hostinger).
#
#   ./deploy.sh                 despliega API + frontend
#   ./deploy.sh --dry-run       muestra qué haría, sin tocar el servidor
#   ./deploy.sh --only api      sólo el backend
#   ./deploy.sh --only frontend sólo el frontend
#   ./deploy.sh --skip-tests    omite la suite (no recomendado)
#
# Qué NO toca nunca en el servidor:
#   - api/config.php   credenciales de producción
#   - api/uploads/*    imágenes subidas por los usuarios
#
set -uo pipefail

# ----------------------------------------------------------------- parámetros

SSH_HOST="u414051709@195.35.33.1"
SSH_PORT="65002"
REMOTE_ROOT="/home/u414051709/domains/rezon.ar/public_html"
REMOTE_BACKUPS="/home/u414051709/backups"
SITE_URL="https://rezon.ar"

# El `php` por defecto del servidor es 7.2, pero las dependencias piden >=8.1.
REMOTE_PHP="/opt/alt/php83/usr/bin/php"

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$PROJECT_DIR/api"
FRONTEND_DIR="$PROJECT_DIR/frontend"

DRY_RUN=0
SKIP_TESTS=0
ONLY=""
# Problemas detectados antes de la verificación final (p. ej. composer).
FALLOS_PREVIOS=0

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)    DRY_RUN=1 ;;
    --skip-tests) SKIP_TESTS=1 ;;
    --only)       ONLY="${2:-}"; shift ;;
    -h|--help)    sed -n '2,20p' "$0"; exit 0 ;;
    *)            echo "Opción desconocida: $1"; exit 1 ;;
  esac
  shift
done

if [ -n "$ONLY" ] && [ "$ONLY" != "api" ] && [ "$ONLY" != "frontend" ]; then
  echo "--only acepta 'api' o 'frontend'"; exit 1
fi

hacer_api()      { [ -z "$ONLY" ] || [ "$ONLY" = "api" ]; }
hacer_frontend() { [ -z "$ONLY" ] || [ "$ONLY" = "frontend" ]; }

# ------------------------------------------------------------------- utilidades

VERDE='\033[0;32m'; ROJO='\033[0;31m'; AMARILLO='\033[0;33m'; AZUL='\033[0;34m'; NC='\033[0m'

titulo() { printf "\n${AZUL}=== %s ===${NC}\n" "$1"; }
ok()     { printf "  ${VERDE}✓${NC} %s\n" "$1"; }
aviso()  { printf "  ${AMARILLO}!${NC} %s\n" "$1"; }
morir()  { printf "\n${ROJO}✗ %s${NC}\n" "$1"; exit 1; }

ssh_remoto() { ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST" "$@"; }

# rsync con las exclusiones comunes. En dry-run agrega -n.
sincronizar() {
  local origen="$1" destino="$2"; shift 2
  local flags=(-az --delete --omit-dir-times --no-perms)
  [ "$DRY_RUN" -eq 1 ] && flags+=(-n -v)
  rsync "${flags[@]}" "$@" -e "ssh -p $SSH_PORT -o BatchMode=yes" "$origen" "$SSH_HOST:$destino"
}

# ------------------------------------------------------------------ preliminares

titulo "Preliminares"

[ "$DRY_RUN" -eq 1 ] && aviso "MODO DRY-RUN: no se modifica nada en el servidor"

command -v rsync >/dev/null || morir "falta rsync"
command -v npm   >/dev/null || morir "falta npm"
command -v php   >/dev/null || morir "falta php"

ssh_remoto 'echo ok' >/dev/null 2>&1 || morir "no se puede conectar por SSH a $SSH_HOST:$SSH_PORT"
ok "conexión SSH"

RAMA=$(git -C "$PROJECT_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null)
COMMIT=$(git -C "$PROJECT_DIR" rev-parse --short HEAD 2>/dev/null)
ok "rama $RAMA ($COMMIT)"

if [ -n "$(git -C "$PROJECT_DIR" status --porcelain 2>/dev/null)" ]; then
  aviso "hay cambios sin commitear: se desplegará el working tree tal como está"
fi

[ -f "$API_DIR/config.php" ] || morir "falta api/config.php (copiá config.example.php)"

# ------------------------------------------------------------------------ tests

if [ "$SKIP_TESTS" -eq 1 ]; then
  titulo "Tests"
  aviso "omitidos por --skip-tests"
else
  titulo "Tests del backend"
  if [ -x "$API_DIR/vendor/bin/phpunit" ]; then
    ( cd "$API_DIR" && ./vendor/bin/phpunit --no-coverage ) || morir "fallaron los tests de PHP; no se despliega"
    ok "PHPUnit en verde"
  else
    aviso "PHPUnit no instalado (composer install en api/); se omite"
  fi

  titulo "Tests del frontend"
  if [ -d "$FRONTEND_DIR/node_modules" ]; then
    ( cd "$FRONTEND_DIR" && npm test --silent ) || morir "fallaron los tests del frontend; no se despliega"
    ok "Vitest en verde"
  else
    aviso "node_modules ausente (npm install en frontend/); se omite"
  fi
fi

# ------------------------------------------------------------------------ build

if hacer_frontend; then
  titulo "Build del frontend"
  ( cd "$FRONTEND_DIR" && npm run build ) >/dev/null 2>&1 || morir "falló el build"

  [ -f "$FRONTEND_DIR/dist/index.html" ] || morir "el build no generó dist/index.html"

  BUNDLE=$(ls "$FRONTEND_DIR/dist/assets/"*.js 2>/dev/null | head -1)
  [ -n "$BUNDLE" ] || morir "el build no generó ningún bundle JS"

  # Salvaguarda: un bundle apuntando a localhost significa que faltó
  # .env.production y rompería el sitio en producción.
  if grep -q "localhost:8000" "$BUNDLE"; then
    morir "el bundle apunta a localhost: revisá frontend/.env.production"
  fi
  ok "build OK ($(basename "$BUNDLE"))"
fi

# ----------------------------------------------------------------------- backup

if [ "$DRY_RUN" -eq 0 ]; then
  titulo "Backup en el servidor"
  STAMP=$(date +%Y%m%d-%H%M%S)
  ssh_remoto "mkdir -p $REMOTE_BACKUPS && cd $REMOTE_ROOT && tar czf $REMOTE_BACKUPS/rezonar-predeploy-$STAMP.tar.gz \
      --exclude='api/uploads' --exclude='api/vendor' \
      api index.html index.php .vite .htaccess sw.js 2>/dev/null" \
    && ok "rezonar-predeploy-$STAMP.tar.gz" \
    || morir "no se pudo crear el backup; se aborta"
fi

# -------------------------------------------------------------------------- API

if hacer_api; then
  titulo "Desplegando API"

  # config.php y uploads/ quedan fuera: son estado del servidor.
  # tests/, phpunit.xml y vendor/ no van a producción.
  #
  # Las exclusiones de config van ancladas con /: sin la barra inicial rsync
  # las aplica en cualquier nivel, y se llevó puesto api/entradas/config.php.
  sincronizar "$API_DIR/" "$REMOTE_ROOT/api/" \
    --exclude '/config.php' \
    --exclude '/config_prod.php' \
    --exclude 'uploads/' \
    --exclude 'tests/' \
    --exclude 'phpunit.xml' \
    --exclude '.phpunit.cache/' \
    --exclude 'vendor/' \
    --exclude '.DS_Store' \
    || morir "falló el rsync de la API"
  ok "archivos de la API sincronizados"

  # El .htaccess de uploads sí se despliega (uploads/ está excluido, pero este
  # archivo es configuración nuestra, no contenido del usuario).
  if [ -f "$API_DIR/uploads/.htaccess" ] && [ "$DRY_RUN" -eq 0 ]; then
    scp -q -P "$SSH_PORT" -o BatchMode=yes "$API_DIR/uploads/.htaccess" \
      "$SSH_HOST:$REMOTE_ROOT/api/uploads/.htaccess" && ok "endurecido api/uploads/.htaccess"
  fi

  # Dependencias de producción. vendor/ no se sincroniza (vive sólo en el
  # servidor), así que sólo hace falta tocarlo cuando cambió composer.lock.
  #
  # El php de la línea de comandos del servidor es 7.2 y los paquetes piden
  # >=8.1: hay que invocar composer con un binario moderno o falla entero.
  if [ "$DRY_RUN" -eq 0 ]; then
    LOCK_LOCAL=$(md5 -q "$API_DIR/composer.lock" 2>/dev/null || md5sum "$API_DIR/composer.lock" | cut -d' ' -f1)
    LOCK_REMOTO=$(ssh_remoto "md5sum $REMOTE_ROOT/api/composer.lock 2>/dev/null | cut -d' ' -f1")

    if [ "$LOCK_LOCAL" = "$LOCK_REMOTO" ]; then
      ok "dependencias sin cambios (composer.lock idéntico)"
    else
      COMPOSER_SALIDA=$(ssh_remoto "cd $REMOTE_ROOT/api && $REMOTE_PHP \$(command -v composer) install --no-dev --optimize-autoloader --no-interaction 2>&1")
      # Sin el $? directo: una tubería devolvería el estado del último comando.
      if [ $? -eq 0 ]; then
        ok "composer install --no-dev"
      else
        aviso "composer falló; vendor/ quedó como estaba:"
        echo "$COMPOSER_SALIDA" | tail -5 | sed 's/^/      /'
        FALLOS_PREVIOS=1
      fi
    fi
  fi
fi

# --------------------------------------------------------------------- frontend

if hacer_frontend; then
  titulo "Desplegando frontend"

  # Sin --delete aquí: en la raíz conviven directorios que no maneja el build
  # (social/, uploads de otras secciones, etc.).
  local_flags=(-az --omit-dir-times --no-perms)
  [ "$DRY_RUN" -eq 1 ] && local_flags+=(-n -v)

  rsync "${local_flags[@]}" \
    --exclude '.DS_Store' \
    -e "ssh -p $SSH_PORT -o BatchMode=yes" \
    "$FRONTEND_DIR/dist/" "$SSH_HOST:$REMOTE_ROOT/" \
    || morir "falló el rsync del frontend"
  ok "dist/ sincronizado"

  # Los bundles llevan hash en el nombre: los de builds viejos quedan huérfanos.
  if [ "$DRY_RUN" -eq 0 ]; then
    VIGENTES=$(cd "$FRONTEND_DIR/dist/assets" && ls | tr '\n' '|' | sed 's/|$//')
    ssh_remoto "cd $REMOTE_ROOT/assets 2>/dev/null && ls | grep -Ev '^($VIGENTES)$' | xargs -r rm -f" \
      && ok "bundles de builds anteriores eliminados"
  fi
fi

# ----------------------------------------------------------------- verificación

if [ "$DRY_RUN" -eq 1 ]; then
  titulo "Dry-run terminado"
  echo "  No se modificó nada. Quitá --dry-run para desplegar de verdad."
  exit 0
fi

titulo "Verificación post-deploy"

FALLOS=$FALLOS_PREVIOS
comprobar() { # descripción, url, código esperado
  local codigo
  codigo=$(curl -s -o /dev/null -w "%{http_code}" --max-time 20 "$2")
  if [ "$codigo" = "$3" ]; then
    ok "$1 ($codigo)"
  else
    printf "  ${ROJO}✗${NC} %s: esperaba %s, obtuvo %s\n" "$1" "$3" "$codigo"
    FALLOS=$((FALLOS+1))
  fi
}

comprobar "home"                  "$SITE_URL/"                              200
comprobar "páginas recientes"     "$SITE_URL/api/public/recent-pages.php"   200
comprobar "eventos recientes"     "$SITE_URL/api/public/recent-events.php"  200
comprobar "buscador"              "$SITE_URL/api/public/search.php?q=ab"    200
comprobar "página sin slug (400)" "$SITE_URL/api/public/page.php"           400
comprobar "endpoint protegido"    "$SITE_URL/api/pages/index.php"           401

if hacer_frontend; then
  BUNDLE_NOMBRE=$(basename "$(ls "$FRONTEND_DIR/dist/assets/"*.js | head -1)")
  comprobar "bundle JS" "$SITE_URL/assets/$BUNDLE_NOMBRE" 200
fi

# Que ningún archivo de test o backup haya llegado al servidor.
RESIDUOS=$(ssh_remoto "ls $REMOTE_ROOT/api/tests $REMOTE_ROOT/api/phpunit.xml 2>/dev/null" | wc -l | tr -d ' ')
if [ "$RESIDUOS" = "0" ]; then
  ok "no se subieron archivos de test"
else
  printf "  ${ROJO}✗${NC} quedaron archivos de test en el servidor\n"
  FALLOS=$((FALLOS+1))
fi

titulo "Resultado"
if [ "$FALLOS" -eq 0 ]; then
  printf "  ${VERDE}Deploy correcto${NC} — $RAMA ($COMMIT)\n"
  echo "  Backup: $REMOTE_BACKUPS/rezonar-predeploy-$STAMP.tar.gz"
  exit 0
fi

printf "  ${ROJO}$FALLOS comprobación(es) fallaron${NC}\n"
echo "  Para revertir:"
echo "    ssh -p $SSH_PORT $SSH_HOST 'cd $REMOTE_ROOT && tar xzf $REMOTE_BACKUPS/rezonar-predeploy-$STAMP.tar.gz'"
exit 1
