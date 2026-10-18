#!/usr/bin/env bash
# deploy-auto.sh — despliega main a producción cuando entra un commit nuevo
# con el CI en verde. Corre cada 5 minutos con launchd en la Mac que tiene la
# clave SSH del servidor (la clave nunca sale de esa Mac).
#
# Usa un clon propio ($DEPLOY_DIR), no la carpeta de trabajo: así sólo se
# despliega lo que está en main, nunca cambios sin commitear. El deploy en sí
# lo hace deploy.sh, con todas sus verificaciones (esquema de producción,
# tests, backup, comprobaciones al final).
#
#   touch ~/claude-runner-logs/deploy/PAUSA   no despliega hasta borrarlo
#   rm ~/claude-runner-logs/deploy/fallido    reintenta un commit que falló
#
# Corre con el bash 3.2 de macOS, sin jq: el JSON se lee con el --jq de gh.

set -euo pipefail

# git checkout reescribe este archivo si cambió en main, y bash lo va leyendo
# mientras corre: se ejecuta desde una copia.
if [[ -z "${DEPLOY_AUTO_COPIA:-}" ]]; then
  COPIA=$(mktemp -t deploy-auto)
  cp "$0" "$COPIA"
  DEPLOY_AUTO_COPIA="$COPIA" exec /bin/bash "$COPIA" "$@"
fi

# launchd arranca con un PATH mínimo: php, composer, gh y mariadb vienen de
# Homebrew, node de /usr/local/bin.
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"

REPO="cjsuther/pages"
DEPLOY_DIR="${DEPLOY_DIR:-$HOME/Work/claude/pages-deploy}"
BASE_BRANCH="main"
# Los jobs de .github/workflows/ci.yml que tienen que estar en verde.
CHECKS_REQUERIDOS='["API","Frontend","Seguridad"]'
ESTADO_DIR="$HOME/claude-runner-logs/deploy"
LOCK="/tmp/deploy-auto.lock"
mkdir -p "$ESTADO_DIR"

# Un solo deploy a la vez (mismo esquema que claude-runner.sh).
if ! mkdir "$LOCK" 2>/dev/null; then
  OTRO=$(cat "$LOCK/pid" 2>/dev/null || true)
  if [[ -n "$OTRO" ]] && kill -0 "$OTRO" 2>/dev/null; then
    exit 0
  fi
  rm -rf "$LOCK"
  mkdir "$LOCK" || exit 0
fi
echo $$ > "$LOCK/pid"
trap 'rm -rf "$LOCK" "$DEPLOY_AUTO_COPIA"' EXIT

[[ -f "$ESTADO_DIR/PAUSA" ]] && { echo "Pausado ($ESTADO_DIR/PAUSA)."; exit 0; }

cd "$DEPLOY_DIR"
git fetch -q origin "$BASE_BRANCH"
SHA=$(git rev-parse "origin/$BASE_BRANCH")
CORTO=${SHA:0:7}

# Ya desplegado, o ya falló y espera a que alguien lo mire.
[[ "$SHA" == "$(cat "$ESTADO_DIR/desplegado" 2>/dev/null)" ]] && exit 0
[[ "$SHA" == "$(cat "$ESTADO_DIR/fallido" 2>/dev/null)" ]] && exit 0

# El CI de ese commit: la última corrida de cada job requerido. Si alguno no
# terminó, se espera a la próxima vuelta.
CI=$(gh api "repos/$REPO/commits/$SHA/check-runs?per_page=100" --jq "
  [.check_runs[] | select(.name as \$n | $CHECKS_REQUERIDOS | index(\$n))]
  | group_by(.name) | map(max_by(.started_at)) as \$r
  | if (\$r | length) < ($CHECKS_REQUERIDOS | length) or any(\$r[]; .status != \"completed\") then \"pendiente\"
    elif all(\$r[]; .conclusion == \"success\") then \"verde\"
    else \"rojo\" end")

notificar() { osascript -e "display notification \"$1\" with title \"Deploy rezon.ar\"" 2>/dev/null || true; }

# El PR que trajo el commit, para avisar ahí.
pr_del_commit() { gh api "repos/$REPO/commits/$SHA/pulls" --jq '.[0].number // empty' 2>/dev/null || true; }

# Marca el commit para no reintentarlo cada 5 minutos y avisa en el PR que lo
# trajo, o en un issue si entró sin PR.
avisar_fallo() {
  local motivo="$1" detalle="$2" pr cuerpo
  echo "$SHA" > "$ESTADO_DIR/fallido"
  notificar "No se desplegó $CORTO: $motivo"
  cuerpo="⚠️ El deploy automático de \`$CORTO\` no se hizo: $motivo.

\`\`\`
$detalle
\`\`\`

Producción quedó como estaba, salvo que el detalle diga lo contrario. Para reintentar el mismo commit: \`rm ~/claude-runner-logs/deploy/fallido\` en la Mac del deploy. Un commit nuevo en main se intenta solo."
  pr=$(pr_del_commit)
  if [[ -n "$pr" ]]; then
    gh pr comment "$pr" --repo "$REPO" --body "$cuerpo" || true
  else
    gh issue create --repo "$REPO" --title "Falló el deploy automático de $CORTO" --body "$cuerpo" || true
  fi
}

case "$CI" in
  verde) ;;
  pendiente) echo "$CORTO: CI sin terminar, espero."; exit 0 ;;
  *) avisar_fallo "el CI no está en verde" \
       "$(gh api "repos/$REPO/commits/$SHA/check-runs?per_page=100" --jq '.check_runs[] | "\(.name): \(.conclusion // .status)"')"
     exit 0 ;;
esac

LOG="$ESTADO_DIR/deploy-$CORTO-$(date +%Y%m%d-%H%M%S).log"
echo "Desplegando $CORTO (log: $LOG)"

# Exactamente main, sin restos. -fd no toca lo ignorado: vendor/, node_modules/
# y api/config.php (deploy.sh sólo verifica que exista; no lo sube).
git checkout -q -f -B "$BASE_BRANCH" "origin/$BASE_BRANCH"
git clean -fdq

set +e
{
  (cd api && composer install --no-interaction --no-progress) &&
  (cd frontend && npm ci --no-audit --no-fund) &&
  ./deploy.sh
} > "$LOG" 2>&1
STATUS=$?
set -e

if [[ $STATUS -ne 0 ]]; then
  # Sin los colores de terminal de deploy.sh.
  avisar_fallo "falló el deploy (log: $(basename "$LOG"))" \
    "$(tail -n 25 "$LOG" | sed $'s/\x1b\\[[0-9;]*m//g')"
  exit 1
fi

echo "$SHA" > "$ESTADO_DIR/desplegado"
rm -f "$ESTADO_DIR/fallido"
notificar "Desplegado $CORTO"
PR=$(pr_del_commit)
if [[ -n "$PR" ]]; then
  gh pr comment "$PR" --repo "$REPO" --body "🚀 Desplegado en producción (\`$CORTO\`)." || true
fi
echo "Desplegado $CORTO."

# --- launchd ------------------------------------------------------------------
#
# ~/Library/LaunchAgents/ar.rezon.deploy-auto.plist: igual al del runner, con
# Label ar.rezon.deploy-auto, este script y los logs en
# ~/claude-runner-logs/deploy/launchd.{out,err}.log. Necesita una sesión
# iniciada: ahí están el llavero, gh y la clave SSH.
#
#   launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/ar.rezon.deploy-auto.plist
#   launchctl kickstart gui/$(id -u)/ar.rezon.deploy-auto     # correrlo ya
#   launchctl print gui/$(id -u)/ar.rezon.deploy-auto         # estado
#   launchctl bootout gui/$(id -u)/ar.rezon.deploy-auto       # apagarlo
