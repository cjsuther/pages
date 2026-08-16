#!/bin/bash
# Smoke test end-to-end de la API refactorizada.
# Levanta php -S contra la base rezonar_test, con una config temporal.
# Restaura siempre la config real, incluso si algo falla.

set -u
API=/Users/cristiansuther/Work/claude/pages/api
PORT=8123
BASE="http://127.0.0.1:$PORT"
BACKUP="$API/config.php.smoke-backup"
FALLOS=0

SQLMODE_PREVIO=$(mysql -u root -N -e "SELECT @@GLOBAL.sql_mode" 2>/dev/null)

restaurar() {
  [ -n "${SRV_PID:-}" ] && kill "$SRV_PID" 2>/dev/null
  if [ -n "${SQLMODE_PREVIO:-}" ]; then
    mysql -u root -e "SET GLOBAL sql_mode='$SQLMODE_PREVIO';" 2>/dev/null
    echo "→ sql_mode local restaurado"
  fi
  if [ -f "$BACKUP" ]; then
    mv -f "$BACKUP" "$API/config.php"
    echo "→ config.php de producción restaurada"
  fi
}
trap restaurar EXIT

# --- config temporal apuntando a la base de test
cp "$API/config.php" "$BACKUP"
cat > "$API/config.php" <<'EOF'
<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'rezonar_test');
define('DB_USER', 'root');
define('DB_PASS', '');
define('JWT_SECRET', 'smoke-test-secret');
define('JWT_EXPIRATION', 86400);
define('FRONTEND_URL', 'http://localhost:3000');
define('UPLOAD_URL', 'http://localhost:8123');
define('GOOGLE_CLIENT_ID', 'smoke-client-id');
define('GOOGLE_CLIENT_SECRET', 'smoke-secret');
define('GOOGLE_REDIRECT_URI', 'http://localhost:8123/auth/google-callback.php');
define('APPLE_CLIENT_ID', 'com.smoke.test');
define('APPLE_TEAM_ID', 'TEAM');
define('APPLE_KEY_ID', 'KEY');
define('APPLE_REDIRECT_URI', 'http://localhost:8123/auth/apple-callback.php');
define('APPLE_PRIVATE_KEY', 'x');
define('VAPID_PUBLIC_KEY', 'vapid-pub');
define('VAPID_PRIVATE_KEY', 'vapid-priv');
define('VAPID_SUBJECT', 'mailto:smoke@test.local');
define('CRON_SECRET_KEY', 'smoke-cron');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
EOF

# Producción corre MariaDB 11.8 con sql_mode permisivo y tiene filas con
# event_date = '0000-00-00'. Sin esto, MySQL 9 local rechaza esas consultas.
mysql -u root -e "SET GLOBAL sql_mode='NO_ENGINE_SUBSTITUTION';" 2>/dev/null

php -S 127.0.0.1:$PORT -t "$API" >/dev/null 2>&1 &
SRV_PID=$!
sleep 1.5

verificar() { # nombre, esperado, actual
  if [ "$2" = "$3" ]; then
    printf "  ✅ %-52s %s\n" "$1" "$3"
  else
    printf "  ❌ %-52s esperado %s, obtuvo %s\n" "$1" "$2" "$3"
    FALLOS=$((FALLOS+1))
  fi
}

code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }

echo "=== Endpoints públicos ==="
verificar "GET /public/recent-pages.php"        200 "$(code $BASE/public/recent-pages.php)"
verificar "GET /public/recent-events.php"       200 "$(code $BASE/public/recent-events.php)"
verificar "GET /public/search.php?q=ab"         200 "$(code $BASE/public/search.php?q=ab)"
verificar "GET /public/events.php"              200 "$(code $BASE/public/events.php)"
verificar "GET /public/page.php (sin slug)"     400 "$(code $BASE/public/page.php)"
verificar "GET /public/page.php?slug=nope"      404 "$(code $BASE/public/page.php?slug=nope)"
verificar "GET /public/event.php (sin id)"      400 "$(code $BASE/public/event.php)"
verificar "GET /public/followers.php (sin id)"  400 "$(code $BASE/public/followers.php)"
verificar "POST /public/recent-pages.php"       405 "$(code -X POST $BASE/public/recent-pages.php)"

echo "=== Autenticación ==="
verificar "GET /auth/google-login.php (redirect)" 302 "$(code $BASE/auth/google-login.php)"
verificar "GET /auth/apple-login.php (redirect)"  302 "$(code $BASE/auth/apple-login.php)"
verificar "GET /auth/login.php"                   405 "$(code $BASE/auth/login.php)"
verificar "POST /auth/register.php (sin datos)"   400 "$(code -X POST -d '{}' $BASE/auth/register.php)"
J='{"email":"x","password":"secreto1"}'
verificar "POST /auth/register.php (mail malo)"   400 "$(code -X POST -d "$J" $BASE/auth/register.php)"

EMAIL="smoke$(date +%s)@test.local"
REG=$(curl -s -X POST -d "{\"email\":\"$EMAIL\",\"password\":\"secreto123\"}" $BASE/auth/register.php)
TOKEN=$(echo "$REG" | python3 -c "import sys,json; print(json.load(sys.stdin).get('token',''))" 2>/dev/null)
if [ -n "$TOKEN" ]; then
  printf "  ✅ %-52s alta OK\n" "POST /auth/register.php (alta real)"
else
  printf "  ❌ %-52s %s\n" "POST /auth/register.php (alta real)" "$REG"
  FALLOS=$((FALLOS+1))
fi

J_MALA="{\"email\":\"$EMAIL\",\"password\":\"mala\"}"
J_OK="{\"email\":\"$EMAIL\",\"password\":\"secreto123\"}"
verificar "POST /auth/login.php (credenciales malas)" 401 "$(code -X POST -d "$J_MALA" $BASE/auth/login.php)"
verificar "POST /auth/login.php (correctas)"          200 "$(code -X POST -d "$J_OK" $BASE/auth/login.php)"

AUTH="Authorization: Bearer $TOKEN"

echo "=== Endpoints protegidos: sin token ==="
verificar "GET /pages/index.php"          401 "$(code $BASE/pages/index.php)"
verificar "GET /notifications/index.php"  401 "$(code $BASE/notifications/index.php)"
verificar "GET /pages/following.php"      401 "$(code $BASE/pages/following.php)"
verificar "GET /users/profile.php"        401 "$(code $BASE/users/profile.php)"
verificar "GET /users/location.php"       401 "$(code $BASE/users/location.php)"
verificar "GET /admins/index.php"         401 "$(code $BASE/admins/index.php)"
verificar "GET /collaborations/index.php" 401 "$(code $BASE/collaborations/index.php)"
verificar "POST /links/index.php"         401 "$(code -X POST $BASE/links/index.php)"

echo "=== Endpoints protegidos: con token ==="
verificar "GET /pages/index.php"                200 "$(code -H "$AUTH" $BASE/pages/index.php)"
verificar "GET /users/profile.php"              200 "$(code -H "$AUTH" $BASE/users/profile.php)"
verificar "GET /pages/following.php"            200 "$(code -H "$AUTH" $BASE/pages/following.php)"
verificar "GET /pages/feed-events.php"          200 "$(code -H "$AUTH" $BASE/pages/feed-events.php)"
verificar "GET /notifications/index.php"        200 "$(code -H "$AUTH" $BASE/notifications/index.php)"
verificar "GET /notifications/subscribe.php"    200 "$(code -H "$AUTH" $BASE/notifications/subscribe.php)"
verificar "GET /admins/index.php?type=pending"  200 "$(code -H "$AUTH" "$BASE/admins/index.php?type=pending")"
verificar "GET /collaborations/index.php"       400 "$(code -H "$AUTH" $BASE/collaborations/index.php)"
J_RESERVADO='{"title":"T","url_slug":"login"}'
verificar "POST /pages/index.php (slug reservado)" 400 "$(code -X POST -H "$AUTH" -d "$J_RESERVADO" $BASE/pages/index.php)"

echo "=== Flujo completo: página → grupo → link ==="
SLUG="smoke$(date +%s)"
PAGE=$(curl -s -X POST -H "$AUTH" -d "{\"title\":\"Smoke\",\"url_slug\":\"$SLUG\"}" $BASE/pages/index.php)
PAGE_ID=$(echo "$PAGE" | python3 -c "import sys,json; print(json.load(sys.stdin)['page']['id'])" 2>/dev/null)
if [ -n "$PAGE_ID" ]; then
  printf "  ✅ %-52s id=%s\n" "POST /pages/index.php (crear)" "$PAGE_ID"
else
  printf "  ❌ %-52s %s\n" "POST /pages/index.php (crear)" "$PAGE"
  FALLOS=$((FALLOS+1))
fi

GROUP=$(curl -s -X POST -H "$AUTH" -d "{\"page_id\":$PAGE_ID,\"title\":\"Links\"}" $BASE/groups/index.php)
GROUP_ID=$(echo "$GROUP" | python3 -c "import sys,json; print(json.load(sys.stdin)['group']['id'])" 2>/dev/null)
if [ -n "$GROUP_ID" ]; then
  printf "  ✅ %-52s id=%s\n" "POST /groups/index.php (crear)" "$GROUP_ID"
else
  printf "  ❌ %-52s %s\n" "POST /groups/index.php (crear)" "$GROUP"
  FALLOS=$((FALLOS+1))
fi

LINK=$(curl -s -X POST -H "$AUTH" -d "{\"group_id\":$GROUP_ID,\"url\":\"https://x.com\",\"text\":\"Un link\",\"url_text\":\"Ir\"}" $BASE/links/index.php)
LINK_ID=$(echo "$LINK" | python3 -c "import sys,json; print(json.load(sys.stdin)['link']['id'])" 2>/dev/null)
if [ -n "$LINK_ID" ]; then
  printf "  ✅ %-52s id=%s\n" "POST /links/index.php (crear)" "$LINK_ID"
else
  printf "  ❌ %-52s %s\n" "POST /links/index.php (crear)" "$LINK"
  FALLOS=$((FALLOS+1))
fi

J_EDIT='{"text":"Editado"}'
verificar "PUT /links/detail.php (editar)"     200 "$(code -X PUT -H "$AUTH" -d "$J_EDIT" "$BASE/links/detail.php?id=$LINK_ID")"
verificar "GET /pages/detail.php (leer)"       200 "$(code -H "$AUTH" "$BASE/pages/detail.php?id=$PAGE_ID")"
verificar "GET /public/page.php (pública)"     200 "$(code "$BASE/public/page.php?slug=$SLUG")"

# El grupo "redes" precarga 6 links
REDES=$(curl -s -X POST -H "$AUTH" -d "{\"page_id\":$PAGE_ID,\"title\":\"Redes\",\"type\":\"redes\"}" $BASE/groups/index.php)
REDES_ID=$(echo "$REDES" | python3 -c "import sys,json; print(json.load(sys.stdin)['group']['id'])" 2>/dev/null)
N=$(mysql -u root rezonar_test -N -e "SELECT COUNT(*) FROM links WHERE group_id=$REDES_ID" 2>/dev/null)
verificar "POST /groups/index.php (redes precarga 6)" 6 "$N"

verificar "DELETE /links/detail.php"           200 "$(code -X DELETE -H "$AUTH" "$BASE/links/detail.php?id=$LINK_ID")"
verificar "DELETE /groups/detail.php"          200 "$(code -X DELETE -H "$AUTH" "$BASE/groups/detail.php?id=$GROUP_ID")"
verificar "DELETE /pages/detail.php"           200 "$(code -X DELETE -H "$AUTH" "$BASE/pages/detail.php?id=$PAGE_ID")"

echo "=== Cron ==="
verificar "GET /notifications/process-daily.php (sin clave)" 403 "$(code $BASE/notifications/process-daily.php)"
verificar "GET /notifications/process-daily.php (con clave)" 200 "$(code "$BASE/notifications/process-daily.php?cron_key=smoke-cron")"

echo
if [ "$FALLOS" -eq 0 ]; then
  echo "✅ Smoke test completo: todo OK"
else
  echo "❌ Smoke test: $FALLOS fallo(s)"
fi
exit $FALLOS
