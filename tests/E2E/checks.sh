#!/bin/sh
# The assertions of tests/E2E/run.sh, run inside the container: $1 is the WordPress directory.
WP=${1:-/var/www/html}
B=http://localhost:8080
FRONT=https://www.example.com
FAILED=0
ok() { printf '  ok    %s\n' "$1"; }
ko() { printf '  FAIL  %s\n' "$1"; FAILED=1; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else ko "$1 (got \"$2\", wanted \"$3\")"; fi; }
header() { curl -s -o /dev/null -D - "$@" | tr -d '\r'; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "Front gate"
H=$(header "$B/hello-headless/")
check "a public page redirects" "$(echo "$H" | head -1 | cut -d' ' -f2)" "302"
check "to the same path on the front end" "$(echo "$H" | grep -i '^location:' | cut -d' ' -f2)" "$FRONT/hello-headless/"
check "wp-login stays on WordPress" "$(code "$B/wp-login.php")" "200"
check "a feed goes to the front end like any page" "$(code "$B/feed/")" "302"
check "the core sitemap is gone" "$(code "$B/wp-sitemap.xml")" "404"

echo "REST API"
H=$(header "$B/wp-json/wp/v2/posts")
check "posts answer" "$(echo "$H" | head -1 | cut -d' ' -f2)" "200"
ETAG=$(echo "$H" | grep -i '^etag:' | cut -d' ' -f2-)
check "they carry an ETag" "$([ -n "$ETAG" ] && echo yes)" "yes"
check "they say how long to cache" "$(echo "$H" | grep -ic '^cache-control:.*max-age')" "1"
check "a matching ETag gives 304" "$(code -H "If-None-Match: $ETAG" "$B/wp-json/wp/v2/posts")" "304"
check "users are closed to visitors" "$(code "$B/wp-json/wp/v2/users")" "401"

echo "CORS"
H=$(header -H "Origin: $FRONT" "$B/wp-json/wp/v2/posts")
check "the front end is allowed" "$(echo "$H" | grep -i '^access-control-allow-origin:' | cut -d' ' -f2)" "$FRONT"
H=$(header -H "Origin: https://evil.example.net" "$B/wp-json/wp/v2/posts")
check "another origin gets nothing" "$(echo "$H" | grep -ic '^access-control-allow-origin:')" "0"
check "and every answer varies on Origin" "$(echo "$H" | grep -ic '^vary:.*origin')" "1"
H=$(header -X OPTIONS -H "Origin: $FRONT" -H "Access-Control-Request-Method: GET" "$B/wp-json/wp/v2/posts")
check "a preflight lists methods" "$(echo "$H" | grep -ic '^access-control-allow-methods:')" "1"

echo "Manifest, search, redirects"
check "the manifest answers" "$(code "$B/wp-json/headless/v1/site")" "200"
check "the manifest names the front end" "$(curl -s "$B/wp-json/headless/v1/site" | grep -c 'www.example.com')" "1"
check "search finds the post" "$(curl -s "$B/wp-json/headless/v1/search?q=headless" | sed "s#\\\\/#/#g" | grep -c "$FRONT/hello-headless/")" "1"
check "search refuses a short term" "$(code "$B/wp-json/headless/v1/search?q=a")" "400"
check "a post link points to the front end" "$(curl -s "$B/wp-json/wp/v2/posts" | sed "s#\\\\/#/#g" | grep -c "$FRONT/hello-headless/")" "1"

echo "Preview and tokens"
DRAFT=$(cat /tmp/draft)
check "a draft is not public" "$(code "$B/wp-json/wp/v2/posts/$DRAFT")" "401"
check "preview needs a session" "$(code "$B/wp-json/headless/v1/preview/posts/$DRAFT")" "401"
TOKEN=$(grep -o 'hl\.[A-Za-z0-9._-]*' /tmp/token.txt | head -1)
check "a token was issued" "$([ -n "$TOKEN" ] && echo yes)" "yes"
check "the token reads the draft" "$(code -H "Authorization: Bearer $TOKEN" "$B/wp-json/headless/v1/preview/posts/$DRAFT")" "200"
check "a wrong token is refused" "$(code -H "Authorization: Bearer hl.1.aaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" "$B/wp-json/wp/v2/posts")" "401"
check "a read token cannot write" "$(code -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"title":"x"}' "$B/wp-json/wp/v2/posts")" "403"

echo "Doctor"
cd "$WP" && php -d memory_limit=1G /usr/local/bin/wp headless doctor --allow-root >/tmp/doctor.txt 2>&1
check "the doctor finds nothing failing" "$(grep -c '^\[fail\]\|Error' /tmp/doctor.txt)" "0"

if [ -n "$SAMPLES" ]; then
  # SAMPLES=1 tests/E2E/run.sh prints what each route really answers, to write the contract from
  pretty() { php -r 'echo json_encode(json_decode(stream_get_contents(STDIN)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";'; }
  for route in site redirects "redirects/resolve?path=/nope/" "search?q=headless" "search?q=a" forms "media/1" "preview/posts/$DRAFT"; do
    echo "=== GET /headless/v1/$route"; curl -s -H "Authorization: Bearer $TOKEN" "$B/wp-json/headless/v1/$route" | pretty
  done
  echo "=== GET /wp/v2/posts/$(cat /tmp/published)?_fields=id,link,headless_blocks,headless_seo,headless_featured_media"; curl -s "$B/wp-json/wp/v2/posts/$(cat /tmp/published)?_fields=id,link,headless_blocks,headless_seo,headless_featured_media" | pretty
  echo "=== POST preview/exchange (bad token)"; curl -s -X POST -H 'Content-Type: application/json' -d '{"token":"x"}' "$B/wp-json/headless/v1/preview/exchange" | pretty
fi

echo
if [ "$FAILED" = 0 ]; then echo "E2E: all checks passed"; else echo "E2E: some checks failed"; cat /tmp/doctor.txt; tail -5 /tmp/server.log; exit 1; fi
