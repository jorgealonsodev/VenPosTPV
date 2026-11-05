#!/usr/bin/env bash
# Builds the Plesk upload packages from the working tree (tracked and new,
# non-ignored files; uncommitted changes are included and reported):
#   dist/venpostpv-plesk.zip  TPV app with production vendor/ and the web installer
#   dist/tpvconnect.zip       PrestaShop module (without its tests)
# Usage: bin/build-plesk.sh            (PHP_BIN overrides the PHP used for Composer)
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-$ROOT/.runtime/php-8.5.12/bin/php}"
COMPOSER="${COMPOSER_PHAR:-$ROOT/composer.phar}"
DIST="$ROOT/dist"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

[ -x "$PHP_BIN" ] || { echo "PHP not found: $PHP_BIN (set PHP_BIN)" >&2; exit 1; }
[ -f "$COMPOSER" ] || { echo "composer.phar not found: $COMPOSER (set COMPOSER_PHAR)" >&2; exit 1; }

APP="$WORK/venpostpv"
mkdir -p "$APP" "$WORK/mod" "$DIST"
cd "$ROOT"
copy_tree() { # $1 = destination, rest = paths
    local dest="$1"; shift
    git ls-files -z --cached --others --exclude-standard -- "$@" \
        | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done \
        | tar --null -T - -cf - | tar -x -C "$dest"
}
copy_tree "$APP" bin/console config migrations public src templates composer.json composer.lock env.example
copy_tree "$WORK/mod" modules/tpvconnect
rm -rf "$WORK/mod/modules/tpvconnect/tests"

# Local-only XAMPP wrapper out, Plesk .htaccess files in.
rm -f "$APP/public/php85.cgi" "$APP/public/assets/.gitkeep"
cp deploy/plesk/public.htaccess "$APP/public/.htaccess"
cp deploy/plesk/root.htaccess "$APP/.htaccess"
cp deploy/plesk/root-install.php "$APP/install.php"
cp docs/despliegue-plesk.md "$APP/LEEME-PLESK.md"
mkdir -p "$APP/var/cache" "$APP/var/log"
# Database dumps never ship: backups/ is not in the copy list above, and
# tpv:backup:database creates it on the server (0700, with its .htaccess) on its
# first run. This guards against a future copy list that includes it.
rm -rf "$APP/backups"

(cd "$APP" && COMPOSER_HOME="$WORK/composer-home" "$PHP_BIN" "$COMPOSER" install \
    --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)

rm -f "$DIST/venpostpv-plesk.zip" "$DIST/tpvconnect.zip"
# No top folder: extracted straight into the subdomain folder, it is ready.
(cd "$APP" && zip -qr "$DIST/venpostpv-plesk.zip" .)
(cd "$WORK/mod/modules" && zip -qr "$DIST/tpvconnect.zip" tpvconnect)

DIRTY="$(git status --porcelain -- bin/console config migrations public src templates composer.json composer.lock modules/tpvconnect | grep -v "tests/" || true)"
echo "Built from $(git rev-parse --short HEAD)${DIRTY:+ plus uncommitted changes}:"
ls -l "$DIST"/*.zip
