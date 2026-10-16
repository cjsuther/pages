#!/usr/bin/env bash
# claude-runner.sh — toma issues con la etiqueta claude-ready y se los pasa a Claude Code.
# Uso: ./claude-runner.sh   (ideal correrlo cada 5 min con launchd o cron en la Mac mini)
# Requisitos: gh (autenticado), claude (Claude Code), git, php y mariadb (brew).
# Un worktree limpio en $REPO_DIR.
# Corre con el bash 3.2 de macOS y sin jq ni flock: el JSON se lee con el --jq de gh.

set -euo pipefail

# launchd arranca con un PATH mínimo (/usr/bin:/bin:/usr/sbin:/sbin): gh viene
# de Homebrew y claude de ~/.local/bin.
export PATH="/opt/homebrew/bin:/usr/local/bin:$HOME/.local/bin:$PATH"

REPO="cjsuther/pages"            # owner/repo en GitHub
REPO_DIR="$HOME/Work/claude/pages"  # clon local del repo
BASE_BRANCH="main"
LABEL_READY="claude-ready"
LABEL_WORKING="claude-working"
LABEL_REVIEW="claude-review"
LABEL_FAILED="claude-failed"
LOCK="/tmp/claude-runner.lock"
LOG_DIR="$HOME/claude-runner-logs"
mkdir -p "$LOG_DIR"

# Un solo runner a la vez. macOS no trae flock: mkdir es atómico, así que sólo
# uno lo crea. El pid adentro permite recuperar un lock que dejó un runner
# muerto (reinicio, kill -9) sin que quede trabado para siempre.
if ! mkdir "$LOCK" 2>/dev/null; then
  OTRO=$(cat "$LOCK/pid" 2>/dev/null || true)
  if [[ -n "$OTRO" ]] && kill -0 "$OTRO" 2>/dev/null; then
    echo "Otro runner en ejecución, salgo."
    exit 0
  fi
  echo "Lock abandonado (pid ${OTRO:-?}), lo tomo."
  rm -rf "$LOCK"
  mkdir "$LOCK" || { echo "Otro runner tomó el lock, salgo."; exit 0; }
fi
echo $$ > "$LOCK/pid"
trap 'rm -rf "$LOCK"' EXIT

cd "$REPO_DIR"

# Sin nombre y email de git, Claude hace todo el trabajo y no puede commitear:
# mejor no tomar el issue. Se configura una vez en el clon con git config.
if [[ -z "$(git config user.name)" || -z "$(git config user.email)" ]]; then
  echo "Falta git config user.name/user.email en $REPO_DIR, salgo." >&2
  exit 1
fi

# Un issue por corrida: el más antiguo con claude-ready y sin claude-working
NUM=$(gh issue list --repo "$REPO" --label "$LABEL_READY" --state open \
        --json number,labels --limit 50 \
        --jq "[.[] | select(all(.labels[].name; . != \"$LABEL_WORKING\"))] | sort_by(.number) | first | .number // empty")

[[ -z "$NUM" ]] && { echo "Sin issues pendientes."; exit 0; }

TITLE=$(gh issue view "$NUM" --repo "$REPO" --json title --jq '.title')
BODY=$(gh issue view "$NUM" --repo "$REPO" --json body --jq '.body')
BRANCH="claude/issue-$NUM"
LOGFILE="$LOG_DIR/issue-$NUM-$(date +%Y%m%d-%H%M%S).log"

echo "Tomando issue #$NUM: $TITLE"
gh issue edit "$NUM" --repo "$REPO" --add-label "$LABEL_WORKING"
gh issue comment "$NUM" --repo "$REPO" --body "🤖 Claude Code tomó este issue. Log: $(basename "$LOGFILE")"

# Rama limpia desde main. Lo que haya quedado de una corrida anterior se
# descarta: el clon es del runner, nadie trabaja a mano ahí (lo valioso ya
# quedó guardado en un stash, ver más abajo). -fd no toca vendor/ ni
# node_modules/ ni api/config.php, que están en .gitignore.
git fetch origin "$BASE_BRANCH"
git checkout -f -B "$BRANCH" "origin/$BASE_BRANCH"
git clean -fdq

PROMPT=$(cat <<EOF
Trabajá sobre el issue #$NUM del repo $REPO.

Título: $TITLE

Descripción:
$BODY

Instrucciones:
1. Leé CLAUDE.md y respetá las convenciones del proyecto.
2. Analizá el issue y, si es un bug, reproducilo con un test antes de corregirlo.
3. Implementá la solución con tests que cubran el cambio.
4. Corré la suite de tests completa y asegurate de que pase.
5. Hacé commits chicos y descriptivos que referencien "#$NUM".
6. Al terminar, resumí en 5 líneas qué cambiaste y qué riesgo ves. No hagas push ni crees el PR; eso lo hace el runner.
Si el issue es ambiguo o no se puede resolver con seguridad, no inventes: explicá qué falta y terminá sin cambios.
EOF
)

# Permisos: claude-runner-permisos.json (el de main recién bajado, no el de la
# rama). Sólo se cargan esos y .claude/settings.json del proyecto: nunca el
# settings.local.json ni el de usuario, que pueden traer bypassPermissions.
# dontAsk rechaza todo lo que no esté en la lista. Y el sandbox (Seatbelt) vale
# también para lo que ejecuten los tests: sin ~/.ssh, sin credenciales de gh y
# con red sólo a GitHub, Packagist y npm. Así Claude no puede llegar al servidor
# aunque un test haga shell_exec('ssh ...').
PERMISOS=$(mktemp -t claude-runner-permisos)
git show "origin/$BASE_BRANCH:claude-runner-permisos.json" > "$PERMISOS"

# MariaDB para los tests de integración, levantada acá afuera: dentro del
# sandbox mariadbd no arranca. Los tests la usan por RZ_TEST_DB_HOST/PORT, como
# en el CI (root sin contraseña, sólo en 127.0.0.1). Ruta corta: el socket no
# admite más de 103 caracteres.
DB_DIR=$(mktemp -d /tmp/rz-runner-db.XXXXXX)
limpiar() {
  [[ -f "$DB_DIR/pid" ]] && kill "$(cat "$DB_DIR/pid")" 2>/dev/null && sleep 2
  rm -rf "$LOCK" "$PERMISOS" "$DB_DIR"
}
trap limpiar EXIT

mariadb-install-db --datadir="$DB_DIR/data" --auth-root-authentication-method=normal \
  --skip-test-db > "$DB_DIR/install.log" 2>&1
DB_PORT=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);')
(cd "$DB_DIR" && mariadbd --no-defaults --datadir="$DB_DIR/data" \
  --bind-address=127.0.0.1 --port="$DB_PORT" --socket=./s --pid-file=./pid --log-error=./error.log \
  --innodb-buffer-pool-size=32M --innodb-log-file-size=8M --skip-log-bin > /dev/null 2>&1 &)
for _ in $(seq 75); do nc -z 127.0.0.1 "$DB_PORT" 2>/dev/null && break; sleep 0.2; done
export RZ_TEST_DB_HOST=127.0.0.1 RZ_TEST_DB_PORT="$DB_PORT"

set +e
claude -p "$PROMPT" < /dev/null \
  --setting-sources project \
  --settings "$PERMISOS" \
  --permission-mode dontAsk \
  --max-turns 60 \
  --output-format text > "$LOGFILE" 2>&1
STATUS=$?
set -e

SUMMARY=$(tail -n 40 "$LOGFILE")

if [[ $STATUS -ne 0 ]] || git diff --quiet "origin/$BASE_BRANCH"..HEAD; then
  echo "Sin cambios o falló (status $STATUS)."
  gh issue edit "$NUM" --repo "$REPO" --remove-label "$LABEL_WORKING" --remove-label "$LABEL_READY" --add-label "$LABEL_FAILED"
  gh issue comment "$NUM" --repo "$REPO" --body "⚠️ Claude Code no pudo resolverlo o no hizo cambios.

\`\`\`
$SUMMARY
\`\`\`"
  # Si dejó cambios sin commitear, se guardan en un stash en vez de
  # arrastrarlos a main (git stash list en el clon para recuperarlos).
  if [[ -n "$(git status --porcelain)" ]]; then
    git stash push --include-untracked -m "$BRANCH sin commitear $(date +%Y%m%d-%H%M%S)"
  fi
  git checkout "$BASE_BRANCH"
  exit 0
fi

git push -u origin "$BRANCH" --force-with-lease
PR_URL=$(gh pr create --repo "$REPO" --base "$BASE_BRANCH" --head "$BRANCH" \
  --title "$TITLE (#$NUM)" \
  --body "Closes #$NUM

## Resumen de Claude Code
\`\`\`
$SUMMARY
\`\`\`")

gh issue edit "$NUM" --repo "$REPO" --remove-label "$LABEL_WORKING" --remove-label "$LABEL_READY" --add-label "$LABEL_REVIEW"
gh issue comment "$NUM" --repo "$REPO" --body "✅ PR listo para revisión: $PR_URL"
git checkout "$BASE_BRANCH"
echo "Listo: $PR_URL"

# --- launchd: correrlo cada 5 minutos en la Mac mini --------------------------
#
# Guardar como ~/Library/LaunchAgents/ar.rezon.claude-runner.plist
# (un LaunchAgent corre en la sesión del usuario, con acceso al llavero donde
# gh y claude guardan sus credenciales; tiene que haber una sesión iniciada):
#
# <?xml version="1.0" encoding="UTF-8"?>
# <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
# <plist version="1.0">
# <dict>
#   <key>Label</key>
#   <string>ar.rezon.claude-runner</string>
#   <key>ProgramArguments</key>
#   <array>
#     <string>/bin/bash</string>
#     <string>/Users/USUARIO/Work/claude/pages/claude-runner.sh</string>
#   </array>
#   <key>StartInterval</key>
#   <integer>300</integer>
#   <key>RunAtLoad</key>
#   <true/>
#   <key>StandardOutPath</key>
#   <string>/Users/USUARIO/claude-runner-logs/launchd.out.log</string>
#   <key>StandardErrorPath</key>
#   <string>/Users/USUARIO/claude-runner-logs/launchd.err.log</string>
# </dict>
# </plist>
#
# (launchd no expande ~ ni $HOME: reemplazar USUARIO por el usuario de la Mac mini.)
#
# Cargar, probar y descargar:
#   mkdir -p ~/claude-runner-logs
#   plutil -lint ~/Library/LaunchAgents/ar.rezon.claude-runner.plist
#   launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/ar.rezon.claude-runner.plist
#   launchctl kickstart -k gui/$(id -u)/ar.rezon.claude-runner     # correrlo ya
#   launchctl print gui/$(id -u)/ar.rezon.claude-runner            # estado y último exit code
#   launchctl bootout gui/$(id -u)/ar.rezon.claude-runner          # detenerlo
