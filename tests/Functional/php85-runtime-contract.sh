#!/usr/bin/env bash
# Contract checks for the isolated PHP 8.5 runtime. No build is required.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BUILD_SCRIPT="${ROOT_DIR}/bin/build-php85.sh"
BASE_INI="${ROOT_DIR}/config/runtime/php85.ini"
CGI_INI="${ROOT_DIR}/config/runtime/php85-cgi.ini"
RUNTIME="${ROOT_DIR}/.runtime/php-8.5.12"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

require_text() {
    local file="$1" text="$2"
    grep -Fqx -- "$text" "$file" >/dev/null || fail "missing ${text} in ${file#"${ROOT_DIR}/"}"
}

[[ -x "$BUILD_SCRIPT" ]] || fail "build script is missing or not executable"
[[ -f "$BASE_INI" ]] || fail "base runtime INI is missing"
[[ -f "$CGI_INI" ]] || fail "CGI runtime INI is missing"

require_text "$BUILD_SCRIPT" 'PHP_VERSION="8.5.12"'
require_text "$BUILD_SCRIPT" 'PHP_SHA256="08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209"'
for option in \
    '--enable-cli' '--enable-cgi' '--enable-gd' '--with-jpeg' '--with-freetype' \
    '--enable-mbstring' '--disable-mbregex' '--with-mysqli=mysqlnd' \
    '--with-pdo-mysql=mysqlnd' '--with-sqlite3' '--with-pdo-sqlite' \
    '--with-curl' '--with-openssl' '--with-zlib' '--enable-sockets' '--enable-bcmath'; do
    grep -Fq -- "$option" "$BUILD_SCRIPT" || fail "missing configure option ${option}"
done
require_text "$BASE_INI" 'display_errors = Off'
require_text "$BASE_INI" 'log_errors = On'
require_text "$BASE_INI" 'error_log = "@PROJECT_ROOT@/var/log/php85/php-error.log"'
require_text "$CGI_INI" 'cgi.force_redirect = 1'
grep -Fq -- "configure_args+=('--enable-intl')" "$BUILD_SCRIPT" \
    || fail 'intl must be enabled when the separate ICU probe succeeds'

echo 'Static PHP 8.5 runtime contract passed.'

if [[ ! -x "${RUNTIME}/bin/php" ]]; then
    echo 'Runtime absent; skipped CLI/CGI smoke test.'
    exit 0
fi

clean_env=(env -u LD_LIBRARY_PATH -u PHPRC -u PHP_INI_SCAN_DIR)
version="$("${clean_env[@]}" "${RUNTIME}/bin/php" -n -r 'echo PHP_VERSION;')"
[[ "$version" == '8.5.12' ]] || fail "expected PHP 8.5.12, found ${version}"
[[ -x "${RUNTIME}/bin/php-cgi" ]] || fail 'php-cgi is missing'
"${clean_env[@]}" "${RUNTIME}/bin/php-cgi" -n -v >/dev/null
[[ -f "${RUNTIME}/etc/php.ini" && -f "${RUNTIME}/etc/conf.d/20-cgi.ini" ]] \
    || fail 'rendered runtime INI files are missing'
"${clean_env[@]}" "${RUNTIME}/bin/php-cgi" -c "${RUNTIME}/etc/php.ini" -i \
    | grep -F '<td class="e">cgi.force_redirect</td><td class="v">On</td><td class="v">On</td>' >/dev/null \
    || fail 'CGI force redirect is not enabled'

for extension in bcmath ctype curl dom fileinfo gd iconv mbstring mysqli openssl pdo_mysql pdo_sqlite session simplexml sockets sqlite3 xml zlib; do
    "${clean_env[@]}" "${RUNTIME}/bin/php" -n -m | grep -Fxqi -- "$extension" \
        || fail "required extension ${extension} is unavailable"
done

if pkg-config --exists icu-uc icu-i18n; then
    extension=intl
    "${clean_env[@]}" "${RUNTIME}/bin/php" -n -m | grep -Fxqi -- "$extension" \
        || fail "required extension ${extension} is unavailable"
fi

# Required extensions above are checked without inheriting XAMPP's PHP environment.

echo 'PHP 8.5 CLI/CGI smoke test passed.'
