#!/usr/bin/env bash
# Apache Action target for this project's isolated PHP 8.5 front controller.
set -Eeuo pipefail

readonly ACTION_HANDLER='application/x-httpd-php85'

reject() {
    # Do not disclose the configured script or runtime to direct CGI callers.
    printf 'Status: 404 Not Found\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nNot Found\n'
    exit 0
}

service_unavailable() {
    printf 'Status: 503 Service Unavailable\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nService unavailable\n'
    exit 0
}

# mod_actions creates REDIRECT_HANDLER during its internal handler redirect.
# Request headers become HTTP_* CGI variables, so clients cannot forge this
# Apache-side identity. Neither PATH_INFO nor any translated request path is
# used as a PHP filename.
[[ $# -eq 0 ]] || reject
[[ "${REDIRECT_HANDLER:-}" == "$ACTION_HANDLER" ]] || reject

# Apache exposes the CGI wrapper's resolved filename even when it invokes this
# file through the /tpv symlink. Resolve it only after the Apache-side identity
# check, so a direct caller cannot influence even the wrapper-location lookup.
readonly WRAPPER_FILE="${SCRIPT_FILENAME:-${BASH_SOURCE[0]}}"
WRAPPER_DIRECTORY="$(cd "$(dirname "$WRAPPER_FILE")" 2>/dev/null && pwd -P)" || service_unavailable
PROJECT_ROOT="$(cd "${WRAPPER_DIRECTORY}/.." 2>/dev/null && pwd -P)" || service_unavailable
readonly WRAPPER_DIRECTORY PROJECT_ROOT
readonly INDEX="${PROJECT_ROOT}/public/index.php"
readonly PHP_CGI="${PROJECT_ROOT}/.runtime/php-8.5.12/bin/php-cgi"
readonly PHP_INI="${PROJECT_ROOT}/.runtime/php-8.5.12/etc/php.ini"

# The only executable PHP target is this physical front-controller file. The
# request route is retained for Symfony, but cannot influence the PHP target.
[[ -x "$PHP_CGI" && -f "$PHP_INI" && -f "$INDEX" ]] || service_unavailable

# Apache retains the browser URI and query string on the Action request. Its
# PATH_INFO identifies the rewritten index target, not the browser route.
export REQUEST_URI="${REQUEST_URI:-}"
export QUERY_STRING="${QUERY_STRING:-}"
export SCRIPT_NAME='/tpv/index.php'
export PHP_SELF='/tpv/index.php'

# Select the native runtime configuration explicitly and clear XAMPP's loader
# and PHP configuration environment before starting the fixed front controller.
exec env -u LD_LIBRARY_PATH -u PHPRC -u PHP_INI_SCAN_DIR \
    SCRIPT_FILENAME="$INDEX" \
    "$PHP_CGI" -c "$PHP_INI"
