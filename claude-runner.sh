#!/usr/bin/env bash
# claude-runner.sh — toma issues con la etiqueta claude-ready y se los pasa a Claude Code.
# Uso: ./claude-runner.sh   (ideal correrlo cada 5 min con launchd o cron en la Mac mini)
# Requisitos: gh (autenticado), claude (Claude Code), git. Un worktree limpio en $REPO_DIR.
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

# Rama limpia desde main
git fetch origin "$BASE_BRANCH"
git checkout -B "$BRANCH" "origin/$BASE_BRANCH"

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

set +e
claude -p "$PROMPT" \
  --allowedTools "Bash,Read,Edit,Write,Grep,Glob" \
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
