#!/usr/bin/env bash
#
# Attendance — deploy on ordinary PHP + MySQL hosting (Hostinger and similar).
#
# Run it from the application root on the server, over SSH, after uploading or
# pulling new code:
#
#     bash scripts/deploy.sh
#
# It is idempotent: running it again on an unchanged checkout is harmless, and
# it is also the correct command for the very first deployment.
#
# It never runs npm or node. Shared hosting has neither, which is why the
# compiled Filament theme in public/build is committed to the repository.
#
# Override the interpreters when the host's default `php` is the wrong
# version (Hostinger exposes e.g. /usr/bin/php8.3):
#
#     PHP_BIN=/usr/bin/php8.3 bash scripts/deploy.sh
#
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

# Split on spaces so an override may carry arguments, e.g.
# COMPOSER_BIN="php /opt/composer.phar" or PHP_BIN="php -d memory_limit=512M".
read -r -a PHP_CMD <<< "$PHP_BIN"
read -r -a COMPOSER_CMD <<< "$COMPOSER_BIN"

# Shared hosting usually ships display_errors=Off for the CLI, which turns a
# fatal error into a silent exit and an empty screen. A deploy must never fail
# quietly, so errors are forced to stderr for the commands this script runs.
PHP_CMD+=(-d display_errors=stderr)

cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail() {
    echo "Deploy stopped: $1" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# Preconditions, checked before anything is changed
# ---------------------------------------------------------------------------

[ -f .env ] || fail "no .env file. Copy .env.example to .env and fill in APP_URL and the DB_* values. APP_KEY is generated for you below."

# APP_KEY is deliberately NOT required here. Generating it needs artisan,
# artisan needs vendor/, and vendor/ is installed by this script - so demanding
# a key up front made the very first deployment impossible. It is generated
# after Composer runs instead.

# The theme is built by Vite on a developer machine and committed. Without it
# every page renders unstyled, which is easy to miss until someone complains.
[ -f public/build/manifest.json ] || fail "public/build/manifest.json is missing. Run 'npm run build' on your own machine, commit public/build, and deploy again."

# Only the program name is checked, so an override may carry arguments
# (COMPOSER_BIN="php /opt/composer.phar" is a normal way to pin a version).
command -v "${PHP_BIN%% *}" >/dev/null 2>&1 || fail "PHP binary '${PHP_BIN%% *}' not found. Set PHP_BIN to the right one."
command -v "${COMPOSER_BIN%% *}" >/dev/null 2>&1 || fail "Composer binary '${COMPOSER_BIN%% *}' not found. Set COMPOSER_BIN to the right one."

echo "==> PHP $("${PHP_CMD[@]}" -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------

echo "==> Installing PHP dependencies (production only)"
"${COMPOSER_CMD[@]}" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

[ -f vendor/autoload.php ] || fail "Composer finished but vendor/autoload.php is missing, so nothing else can run. Read the Composer output above."

# ---------------------------------------------------------------------------
# Application key
# ---------------------------------------------------------------------------

# Only when it is missing. Regenerating a live key would sign every existing
# session and cookie out, and make anything encrypted with the old key
# unreadable, so an existing key is never touched.
if grep -qE '^APP_KEY=base64:.+' .env; then
    echo "==> Application key already set"
else
    echo "==> Generating the application key (first deployment)"
    "${PHP_CMD[@]}" artisan key:generate --force
fi

# Only now can artisan run, so only now is it safe to promise to bring the
# site back up.
trap '"${PHP_CMD[@]}" artisan up >/dev/null 2>&1 || true' EXIT

echo "==> Maintenance mode"
"${PHP_CMD[@]}" artisan down --retry=15 >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------

echo "==> Migrations"
"${PHP_CMD[@]}" artisan migrate --force

# Creates the attendance_settings row (150 m radius) on a fresh install and
# does nothing on later runs. Never seeds demo data: DemoDataSeeder refuses to
# run outside local environments.
echo "==> Reference data"
"${PHP_CMD[@]}" artisan db:seed --force

# ---------------------------------------------------------------------------
# Caches
# ---------------------------------------------------------------------------

echo "==> Rebuilding caches"
"${PHP_CMD[@]}" artisan optimize:clear
"${PHP_CMD[@]}" artisan config:cache
"${PHP_CMD[@]}" artisan route:cache
"${PHP_CMD[@]}" artisan view:cache
"${PHP_CMD[@]}" artisan event:cache
"${PHP_CMD[@]}" artisan filament:optimize

# Says who the super administrator is, and warns when SUPER_ADMIN_EMAIL is
# blank or names no account. Never fails the deployment: the site is
# already migrated by this point and a missing setting is something to
# read and fix, not a reason to leave the application down. It matters
# because with the setting blank nobody can delete an account or change
# a role - the two powers reserved for that address.
echo "==> Super administrator"
"${PHP_CMD[@]}" artisan app:super-admin || true

echo "==> Live"
"${PHP_CMD[@]}" artisan up

echo
echo "Deployed. If this was the first deployment, create the administrator now:"
echo "    $PHP_BIN artisan app:create-admin --name=\"Company Admin\" --email=admin@your-company.example"
echo "then sign in at /admin/login and set the company location under Attendance settings."
