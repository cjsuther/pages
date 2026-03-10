#!/bin/bash
# Comprehensive API test suite
BASE="http://127.0.0.1:8000"
PASS=0
FAIL=0

check() {
  local name="$1" expected="$2" actual="$3"
  if echo "$actual" | grep -q "$expected"; then
    echo "  PASS: $name"
    PASS=$((PASS + 1))
  else
    echo "  FAIL: $name (expected '$expected', got: $actual)"
    FAIL=$((FAIL + 1))
  fi
}

# Clean test data
echo "Cleaning test data..."
mysql -u root -h 127.0.0.1 -P 3307 -ptestroot123 personal_pages -e "
  DELETE FROM notifications;
  DELETE FROM page_followers;
  DELETE FROM links;
  DELETE FROM link_groups;
  DELETE FROM pages;
  DELETE FROM users;
" 2>/dev/null

echo ""
echo "========== AUTH =========="

echo "--- Register ---"
R=$(curl -s -X POST "$BASE/auth/register.php" -H "Content-Type: application/json" \
  -d '{"email":"user1@test.com","password":"Pass123!"}')
check "Register user1" "token" "$R"

R=$(curl -s -X POST "$BASE/auth/register.php" -H "Content-Type: application/json" \
  -d '{"email":"user2@test.com","password":"Pass123!"}')
check "Register user2" "token" "$R"

R=$(curl -s -X POST "$BASE/auth/register.php" -H "Content-Type: application/json" \
  -d '{"email":"user1@test.com","password":"Pass123!"}')
check "Register duplicate" "already exists" "$R"

R=$(curl -s -X POST "$BASE/auth/register.php" -H "Content-Type: application/json" \
  -d '{"email":"bad","password":"Pass123!"}')
check "Register bad email" "Invalid email" "$R"

R=$(curl -s -X POST "$BASE/auth/register.php" -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"12"}')
check "Register short pass" "at least 6" "$R"

echo ""
echo "--- Login ---"
R=$(curl -s -X POST "$BASE/auth/login.php" -H "Content-Type: application/json" \
  -d '{"email":"user1@test.com","password":"Pass123!"}')
check "Login valid" "token" "$R"
TOKEN=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])" 2>/dev/null)

R=$(curl -s -X POST "$BASE/auth/login.php" -H "Content-Type: application/json" \
  -d '{"email":"user1@test.com","password":"wrong"}')
check "Login wrong pass" "Invalid credentials" "$R"

R=$(curl -s -X POST "$BASE/auth/login.php" -H "Content-Type: application/json" \
  -d '{"email":"nobody@test.com","password":"Pass123!"}')
check "Login nonexistent" "Invalid credentials" "$R"

echo ""
echo "========== PAGES =========="

echo "--- Create ---"
R=$(curl -s -X POST "$BASE/pages/index.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Test Page","url_slug":"test-page","description":"A test page"}')
check "Create page" '"page"' "$R"
PAGE_ID=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['page']['id'])" 2>/dev/null)

R=$(curl -s -X POST "$BASE/pages/index.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Dup Page","url_slug":"test-page"}')
check "Create dup slug" "already exists" "$R"

R=$(curl -s -X POST "$BASE/pages/index.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Bad Slug","url_slug":"login"}')
check "Create reserved slug" "reserved" "$R"

R=$(curl -s -X POST "$BASE/pages/index.php" -H "Content-Type: application/json" \
  -d '{"title":"No Auth","url_slug":"noauth"}')
check "Create without auth" "Unauthorized" "$R"

echo ""
echo "--- Read ---"
R=$(curl -s "$BASE/pages/index.php" -H "Authorization: Bearer $TOKEN")
check "List my pages" '"pages"' "$R"

R=$(curl -s "$BASE/pages/detail.php?id=$PAGE_ID" -H "Authorization: Bearer $TOKEN")
check "Get page detail" '"page"' "$R"

echo ""
echo "--- Update ---"
R=$(curl -s -X PUT "$BASE/pages/detail.php?id=$PAGE_ID" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Updated Page","template":"modern","primary_color":"#FF0000"}')
check "Update page" "Updated Page" "$R"

echo ""
echo "========== PUBLIC ENDPOINTS =========="

R=$(curl -s "$BASE/public/page.php?slug=test-page")
check "Public page by slug" "Updated Page" "$R"

R=$(curl -s "$BASE/public/page.php?slug=nonexistent")
check "Public page 404" "not found" "$R"

R=$(curl -s "$BASE/public/recent-pages.php")
check "Recent pages" '"pages"' "$R"

R=$(curl -s "$BASE/public/recent-events.php")
check "Recent events" '"events"' "$R"

R=$(curl -s "$BASE/public/search.php?q=test")
check "Search" '"results"' "$R"

R=$(curl -s "$BASE/public/search.php?q=Updated")
check "Search finds page" "Updated Page" "$R"

echo ""
echo "========== GROUPS =========="

R=$(curl -s -X POST "$BASE/groups/index.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"page_id\":$PAGE_ID,\"title\":\"My Links\",\"type\":\"links\"}")
check "Create links group" '"group"' "$R"
GROUP_ID=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['group']['id'])" 2>/dev/null)

R=$(curl -s -X POST "$BASE/groups/index.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"page_id\":$PAGE_ID,\"title\":\"My Events\",\"type\":\"eventos\"}")
check "Create events group" '"group"' "$R"
EGROUP_ID=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['group']['id'])" 2>/dev/null)

R=$(curl -s -X PUT "$BASE/groups/detail.php?id=$GROUP_ID" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"title":"Updated Links"}')
check "Update group" "Updated Links" "$R"

echo ""
echo "========== LINKS =========="

R=$(curl -s -X POST "$BASE/links/index.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"group_id\":$GROUP_ID,\"url\":\"https://example.com\",\"text\":\"Example Site\",\"description\":\"A test link\"}")
check "Create link" '"link"' "$R"
LINK_ID=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['link']['id'])" 2>/dev/null)

R=$(curl -s -X POST "$BASE/links/index.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"group_id\":$EGROUP_ID,\"url\":\"https://evento.com\",\"text\":\"Concert\",\"event_date\":\"2026-04-15\",\"event_time\":\"20:00\",\"event_address\":\"Buenos Aires\",\"event_latitude\":-34.6037,\"event_longitude\":-58.3816,\"event_maps_url\":\"https://maps.google.com/?q=-34.6037,-58.3816\"}")
check "Create event link" '"link"' "$R"
EVENT_ID=$(echo "$R" | python3 -c "import sys,json; print(json.load(sys.stdin)['link']['id'])" 2>/dev/null)

R=$(curl -s -X PUT "$BASE/links/detail.php?id=$LINK_ID" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"text":"Updated Link","url":"https://updated.com"}')
check "Update link" "Updated Link" "$R"

echo ""
echo "========== FOLLOW =========="

R=$(curl -s -X POST "$BASE/pages/follow.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"page_id\":$PAGE_ID}")
check "Follow page" "success" "$R"

R=$(curl -s "$BASE/pages/following.php" -H "Authorization: Bearer $TOKEN")
check "Get following" "following" "$R"

echo ""
echo "========== EVENTS IN PUBLIC =========="

R=$(curl -s "$BASE/public/recent-events.php")
check "Events include our event" "Concert" "$R"

R=$(curl -s "$BASE/public/page.php?slug=test-page")
check "Public page has groups" "My Events" "$R"
check "Public page has links" "Updated Link" "$R"

echo ""
echo "========== FEED EVENTS =========="

R=$(curl -s "$BASE/pages/feed-events.php" -H "Authorization: Bearer $TOKEN")
check "Feed events (authenticated)" "events" "$R"

echo ""
echo "========== CLEANUP (DELETE) =========="

R=$(curl -s -X DELETE "$BASE/links/detail.php?id=$LINK_ID" -H "Authorization: Bearer $TOKEN")
check "Delete link" "deleted" "$R"

R=$(curl -s -X DELETE "$BASE/groups/detail.php?id=$GROUP_ID" -H "Authorization: Bearer $TOKEN")
check "Delete group" "deleted" "$R"

R=$(curl -s -X POST "$BASE/pages/follow.php" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"page_id\":$PAGE_ID}")
check "Unfollow page" "success" "$R"

R=$(curl -s -X DELETE "$BASE/pages/detail.php?id=$PAGE_ID" -H "Authorization: Bearer $TOKEN")
check "Delete page" "deleted" "$R"

echo ""
echo "========================================="
echo "  Results: $PASS passed, $FAIL failed"
echo "========================================="
