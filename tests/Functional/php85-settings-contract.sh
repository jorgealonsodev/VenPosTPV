#!/usr/bin/env bash
# Effective settings compatibility contract for the isolated public PHP CLI/CGI.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUNTIME="${ROOT_DIR}/.runtime/php-8.5.12"
clean_env=(env -u LD_LIBRARY_PATH -u PHPRC -u PHP_INI_SCAN_DIR)

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

require_line() {
    local expected="$1" actual="$2"
    grep -Fx -- "$expected" <<<"$actual" >/dev/null || fail "expected CLI setting ${expected}"
}

[[ -x "${RUNTIME}/bin/php" && -x "${RUNTIME}/bin/php-cgi" ]] \
    || fail 'isolated PHP CLI/CGI runtime is missing'

cli_settings="$("${clean_env[@]}" "${RUNTIME}/bin/php" -r '
echo "date.timezone=", date_default_timezone_get(), "\n";
echo "upload_max_filesize=", ini_get("upload_max_filesize"), "\n";
echo "post_max_size=", ini_get("post_max_size"), "\n";
echo "memory_limit=", ini_get("memory_limit"), "\n";
')"
require_line 'date.timezone=Europe/Berlin' "$cli_settings"
require_line 'upload_max_filesize=40M' "$cli_settings"
require_line 'post_max_size=40M' "$cli_settings"
require_line 'memory_limit=512M' "$cli_settings"

# php-cgi -i renders HTML. Retain only the four private settings rows; never emit phpinfo.
cgi_rows="$("${clean_env[@]}" "${RUNTIME}/bin/php-cgi" -i | grep -E \
    '(<td class="e">date.timezone</td>|<td class="e">upload_max_filesize</td>|<td class="e">post_max_size</td>|<td class="e">memory_limit</td>)')"
for expected in \
    '<td class="e">date.timezone</td><td class="v">Europe/Berlin</td><td class="v">Europe/Berlin</td>' \
    '<td class="e">upload_max_filesize</td><td class="v">40M</td><td class="v">40M</td>' \
    '<td class="e">post_max_size</td><td class="v">40M</td><td class="v">40M</td>' \
    '<td class="e">memory_limit</td><td class="v">512M</td><td class="v">512M</td>'; do
    grep -F -- "$expected" <<<"$cgi_rows" >/dev/null || fail "expected CGI setting ${expected}"
done

printf 'PHP 8.5 inherited settings contract passed.\n'
