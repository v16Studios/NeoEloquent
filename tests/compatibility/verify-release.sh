#!/usr/bin/env bash
set -euo pipefail

major="${1:-}"
case "$major" in 12|13) ;; *) echo 'Usage: bash tests/compatibility/verify-release.sh 12|13' >&2; exit 1 ;; esac
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
php_bin="${PHP_BIN:-php}"
composer_bin="${COMPOSER_BIN:-$(command -v composer)}"
artifacts="$root/logs/release-laravel$major"
mkdir -p "$artifacts"

"$php_bin" tests/compatibility/prepare-dependencies.php "$major" --locked
"$php_bin" "$composer_bin" --working-dir="vendor/laravel$major" validate --strict > "$artifacts/composer-validate.txt" 2>&1
"$php_bin" "$composer_bin" --working-dir="vendor/laravel$major" install --no-interaction --no-progress --prefer-dist --no-plugins --no-scripts > "$artifacts/composer-install.txt" 2>&1
"$php_bin" "$composer_bin" --working-dir="vendor/laravel$major" check-platform-reqs > "$artifacts/platform.txt" 2>&1

# This fixed Compose project owns disposable synthetic data only. Run majors serially.
compose=(docker compose -f tests/compatibility/compose.yaml)
trap '"${compose[@]}" down -v > "$artifacts/cleanup.txt" 2>&1' EXIT
"${compose[@]}" down -v
"${compose[@]}" build tests > "$artifacts/build.txt" 2>&1
"${compose[@]}" up -d --wait neo4j
"${compose[@]}" run --rm tests php --version > "$artifacts/php.txt" 2>&1
"${compose[@]}" exec -T neo4j neo4j --version > "$artifacts/neo4j.txt" 2>&1
NEOELOQUENT_EXPECT_FIRST_ID_ZERO=1 "${compose[@]}" run --rm -v "$artifacts:/evidence" tests \
    php -d default_socket_timeout=5 "vendor/laravel$major/vendor/bin/phpunit" \
    -c tests/compatibility/release.xml --bootstrap "vendor/laravel$major/vendor/autoload.php" \
    --do-not-cache-result --log-junit /evidence/focused.xml 2>&1 | tee "$artifacts/focused.txt"
"${compose[@]}" run --rm -v "$artifacts:/evidence" tests \
    php -d default_socket_timeout=5 "vendor/laravel$major/vendor/bin/phpunit" \
    --bootstrap "vendor/laravel$major/vendor/autoload.php" --do-not-cache-result \
    --log-junit /evidence/full.xml 2>&1 | tee "$artifacts/full.txt"
