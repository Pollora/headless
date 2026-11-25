#!/bin/sh
# End-to-end check over real HTTP: a throwaway WordPress in Docker (PHP's built-in server) with the package loaded as a mu-plugin,
# then curl against it. Usage: tests/E2E/run.sh [package dir]. Needs Docker and `composer install` done in the package.
# Not part of the CI harness (Pest suites cover the code); run it before a release.
set -e
PKG=$(cd "${1:-$PWD}" && pwd)
S=${WP_SCRATCH:-/tmp/wp-e2e-$$}
mkdir -p "$S/wp"
docker rm -f hl-e2e-mysql >/dev/null 2>&1 || true
docker network create hl-net >/dev/null 2>&1 || true
docker run -d --name hl-e2e-mysql --network hl-net -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wordpress mysql:8.0 >/dev/null
# The container writes as root: remove its files from a container too
trap 'docker rm -f hl-e2e-mysql >/dev/null 2>&1 || true; docker run --rm -v "$S":/s alpine rm -rf /s/wp >/dev/null 2>&1; rmdir "$S" 2>/dev/null || true' EXIT
for i in $(seq 1 60); do docker exec hl-e2e-mysql mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1 && break; sleep 2; done

# WordPress comes from the host: a container often cannot reach api.wordpress.org
[ -s /tmp/wp-latest.tar.gz ] || curl -fsSL -o /tmp/wp-latest.tar.gz https://wordpress.org/latest.tar.gz

docker run --rm --network hl-net -u 0 -e SAMPLES="${SAMPLES:-}" -v /tmp/wp-latest.tar.gz:/wp.tar.gz:ro -v "$S/wp":/var/www/html -v "$PKG":/pkg wordpress:cli-php8.4 sh -c '
wp() { php -d memory_limit=1G /usr/local/bin/wp "$@"; }
cd /var/www/html
tar xzf /wp.tar.gz --strip-components=1
wp config create --allow-root --dbhost=hl-e2e-mysql --dbname=wordpress --dbuser=root --skip-check --quiet --extra-php <<PHP
define("HEADLESS_CONFIG", ["frontend_url" => "https://www.example.com", "preview" => ["url" => "https://www.example.com/api/preview", "secret" => "e2e-secret-e2e-secret-e2e-secret-0123"]]);
define("DISABLE_WP_CRON", true);
PHP
wp core install --allow-root --url=http://localhost:8080 --title=e2e --admin_user=admin --admin_password=pass --admin_email=a@a.test --skip-email --quiet
wp rewrite structure "/%postname%/" --allow-root --quiet
mkdir -p wp-content/mu-plugins
echo "<?php require \"/pkg/vendor/autoload.php\"; require \"/pkg/stubs/pollora-headless.php\";" > wp-content/mu-plugins/headless.php
cat > router.php <<ROUTER
<?php
\$path = parse_url(\$_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (\$path !== "/" && file_exists(__DIR__ . \$path) && !is_dir(__DIR__ . \$path)) { return false; }
if (is_dir(__DIR__ . \$path) && file_exists(__DIR__ . rtrim(\$path, "/") . "/index.php")) { \$_SERVER["SCRIPT_NAME"] = rtrim(\$path, "/") . "/index.php"; require __DIR__ . rtrim(\$path, "/") . "/index.php"; return true; }
\$_SERVER["SCRIPT_NAME"] = "/index.php";
require __DIR__ . "/index.php";
ROUTER
wp headless theme install --activate --allow-root >/dev/null
wp post create --post_title="Hello headless" --post_status=publish --porcelain --allow-root > /tmp/published
wp post create --post_title="A secret draft" --post_status=draft --porcelain --allow-root > /tmp/draft
wp headless token create admin --name=e2e --scopes=read --allow-root > /tmp/token.txt 2>&1 || true
(php -S 0.0.0.0:8080 -t /var/www/html router.php >/tmp/server.log 2>&1 &)
sleep 2
cd /pkg && sh tests/E2E/checks.sh /var/www/html
'
