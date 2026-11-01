#!/usr/bin/env bash
# Verify that the PHP 8.5 CGI action can only invoke the fixed front controller.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HTACCESS="${ROOT_DIR}/public/.htaccess"
WRAPPER="${ROOT_DIR}/public/php85.cgi"
INDEX="${ROOT_DIR}/public/index.php"
ROUTE_FIXTURE="${ROOT_DIR}/tests/Functional/fixtures/php85/wrapper-project"

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

expect_route_forwarding() {
    local output expected
    output="$(env -i PATH="$PATH" \
        REDIRECT_HANDLER=application/x-httpd-php85 \
        SCRIPT_FILENAME="${ROUTE_FIXTURE}/public/php85.cgi" \
        REQUEST_URI='/tpv/api/health?safe=1' \
        QUERY_STRING='safe=1' \
        PATH_INFO=/tpv/index.php \
        REDIRECT_URL=/tpv/php85.cgi \
        "$WRAPPER")"
    output="${output//$'\r'/}"
    expected=$(cat <<EOF
Content-Type: text/plain

REQUEST_URI=/tpv/api/health?safe=1
QUERY_STRING=safe=1
SCRIPT_FILENAME=${ROUTE_FIXTURE}/public/index.php
SCRIPT_NAME=/tpv/index.php
PHP_SELF=/tpv/index.php
EOF
)
    [[ "$output" == "$expected" ]] \
        || fail 'wrapper did not preserve the original Action route and query over PATH_INFO'
}

expect_rejection() {
    local name="$1"
    shift
    local output status expected
    set +e
    output="$(env -i PATH="$PATH" "$@" 2>&1)"
    status=$?
    set -e
    expected=$'Status: 404 Not Found\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nNot Found'
    [[ $status -eq 0 ]] || fail "${name}: wrapper exited ${status} instead of returning a CGI rejection"
    [[ "$output" == "$expected" ]] \
        || fail "${name}: wrapper did not return the fixed 404 response"
}

[[ -x "$WRAPPER" ]] || fail 'PHP 8.5 CGI wrapper is missing or not executable'
[[ -f "$INDEX" ]] || fail 'front controller is missing'

grep -Fq 'exec env -u LD_LIBRARY_PATH -u PHPRC -u PHP_INI_SCAN_DIR' "$WRAPPER" \
    || fail 'wrapper does not clear inherited XAMPP PHP library/configuration variables'
grep -Fq '"$PHP_CGI" -c "$PHP_INI"' "$WRAPPER" \
    || fail 'wrapper does not select the isolated PHP 8.5 configuration explicitly'
grep -Fq 'SCRIPT_FILENAME="$INDEX"' "$WRAPPER" \
    || fail 'wrapper does not pin PHP to the front controller'
grep -Fq '[[ -x "$PHP_CGI" && -f "$PHP_INI" && -f "$INDEX" ]]' "$WRAPPER" \
    || fail 'wrapper does not verify its fixed PHP 8.5 runtime and front controller'
if grep -Fq '$PATH_TRANSLATED' "$WRAPPER" || grep -Fq '$REDIRECT_SCRIPT_FILENAME' "$WRAPPER" \
    || grep -Fq '"$@"' "$WRAPPER" || grep -Fq 'eval ' "$WRAPPER"; then
    fail 'wrapper accepts a caller-controlled PHP target or arguments'
fi
if grep -Fq 'X-PHP85-' "$WRAPPER" || grep -Fqi 'phpinfo' "$WRAPPER"; then
    fail 'wrapper contains a temporary runtime diagnostic or PHP information endpoint'
fi

# These environments model direct CGI access and forged action metadata. Every
# variant must stop before PHP starts, even when it names a plausible PHP file.
expect_rejection 'direct CGI URL' \
    env REQUEST_METHOD=GET SCRIPT_FILENAME="$INDEX" "$WRAPPER"
expect_rejection 'forged HTTP handler header' \
    env REQUEST_METHOD=GET HTTP_REDIRECT_HANDLER=application/x-httpd-php85 \
    SCRIPT_FILENAME="${ROOT_DIR}/tests/Functional/fixtures/php85/forged.php" "$WRAPPER"
expect_rejection 'direct CGI path-info' \
    env REQUEST_METHOD=GET SCRIPT_FILENAME="$INDEX" PATH_INFO=/../../vendor/autoload.php \
    "$WRAPPER"
expect_rejection 'unexpected wrapper argument' \
    env REQUEST_METHOD=GET "$WRAPPER" -d auto_prepend_file=/tmp/forged.php
expect_route_forwarding

grep -Fqx '    SetHandler application/x-httpd-php85' "$HTACCESS" >/dev/null \
    || fail 'index.php is not assigned the dedicated PHP 8.5 handler'
grep -Fqx 'Action application/x-httpd-php85 /tpv/php85.cgi virtual' "$HTACCESS" >/dev/null \
    || fail 'PHP 8.5 handler does not use the project-local CGI wrapper'
grep -Fqx '    SetHandler cgi-script' "$HTACCESS" >/dev/null \
    || fail 'project CGI wrapper is not executed by mod_cgi'

echo 'PHP 8.5 CGI boundary contract passed.'
