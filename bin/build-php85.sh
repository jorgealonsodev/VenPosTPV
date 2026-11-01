#!/usr/bin/env bash
# Build an isolated, pinned PHP CLI/CGI runtime. It never uses XAMPP libraries.
set -Eeuo pipefail

PHP_VERSION="8.5.12"
PHP_SHA256="08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209"
RELEASES_URL="https://www.php.net/releases/index.php?json&version=8"
DIST_URL="https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_DIR="${ROOT_DIR}/.runtime"
DOWNLOAD_DIR="${RUNTIME_DIR}/downloads"
SOURCE_ROOT="${RUNTIME_DIR}/src"
BUILD_ROOT="${RUNTIME_DIR}/build"
LOG_DIR="${RUNTIME_DIR}/log"
PREFIX="${RUNTIME_DIR}/php-${PHP_VERSION}"
ARCHIVE="${DOWNLOAD_DIR}/php-${PHP_VERSION}.tar.xz"
METADATA="${DOWNLOAD_DIR}/php-${PHP_VERSION}.release.json"
SOURCE_DIR="${SOURCE_ROOT}/php-${PHP_VERSION}"
BUILD_DIR="${BUILD_ROOT}/php-${PHP_VERSION}"
LOG_FILE="${LOG_DIR}/php-${PHP_VERSION}-build.log"
BASE_INI="${ROOT_DIR}/config/runtime/php85.ini"
CGI_INI="${ROOT_DIR}/config/runtime/php85-cgi.ini"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

clean_env=(env -u LD_LIBRARY_PATH -u PHPRC -u PHP_INI_SCAN_DIR)

runtime_is_correct() {
    [[ -x "${PREFIX}/bin/php" && -x "${PREFIX}/bin/php-cgi" ]] || return 1
    [[ -f "${PREFIX}/etc/php.ini" && -f "${PREFIX}/etc/conf.d/20-cgi.ini" ]] || return 1
    grep -Fqx 'display_errors = Off' "${PREFIX}/etc/php.ini" || return 1
    grep -Fqx 'cgi.force_redirect = 1' "${PREFIX}/etc/conf.d/20-cgi.ini" || return 1
    [[ "$("${clean_env[@]}" "${PREFIX}/bin/php" -n -r 'echo PHP_VERSION;' 2>/dev/null)" == "$PHP_VERSION" ]] || return 1
    "${clean_env[@]}" "${PREFIX}/bin/php-cgi" -n -v >/dev/null 2>&1 || return 1

    local extension
    for extension in bcmath ctype curl dom fileinfo gd iconv mbstring mysqli openssl pdo_mysql pdo_sqlite session simplexml sockets sqlite3 xml zlib; do
        "${clean_env[@]}" "${PREFIX}/bin/php" -n -m 2>/dev/null | grep -Fxqi -- "$extension" || return 1
    done
    if pkg-config --exists icu-uc icu-i18n; then
        "${clean_env[@]}" "${PREFIX}/bin/php" -n -m 2>/dev/null | grep -Fxqi -- intl || return 1
    fi
}

preflight() {
    local tool package
    for tool in gcc make pkg-config autoconf curl tar sha256sum awk tee; do
        command -v "$tool" >/dev/null || fail "required host tool is unavailable: ${tool}"
    done
    for package in libxml-2.0 openssl libcurl zlib libpng libjpeg freetype2 sqlite3; do
        pkg-config --exists "$package" || fail "required host package is unavailable: ${package}"
        printf 'host package: %s %s\n' "$package" "$(pkg-config --modversion "$package")"
    done
    if pkg-config --exists icu-uc icu-i18n; then
        printf 'host package: ICU enabled (%s)\n' "$(pkg-config --modversion icu-uc)"
    else
        printf 'limitation: ICU is unavailable; intl will not be built.\n' >&2
    fi
    if ! pkg-config --exists oniguruma; then
        printf 'limitation: oniguruma is unavailable; mbstring is built with mbregex disabled.\n' >&2
    fi
    pkg-config --exists libzip || printf 'note: libzip is unavailable; zip is intentionally not built.\n' >&2
}

validate_pin() {
    [[ "$PHP_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail 'pinned SHA-256 is malformed'
}

fetch_metadata() {
    curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        --connect-timeout 20 --retry 3 "$RELEASES_URL" --output "$METADATA"
    local metadata
    metadata="$(<"$METADATA")"
    [[ "$metadata" == *"\"version\":\"${PHP_VERSION}\""* \
        && "$metadata" == *"\"filename\":\"php-${PHP_VERSION}.tar.xz\""* \
        && "$metadata" == *"\"sha256\":\"${PHP_SHA256}\""* ]] \
        || fail 'official release metadata does not match the pinned version and SHA-256'
}

fetch_archive() {
    if [[ -f "$ARCHIVE" ]]; then
        local existing
        existing="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
        [[ "$existing" == "$PHP_SHA256" ]] && return
        fail "cached archive checksum is ${existing}; remove only ${ARCHIVE} before retrying"
    fi
    curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        --connect-timeout 20 --retry 3 "$DIST_URL" --output "${ARCHIVE}.part"
    local actual
    actual="$(sha256sum "${ARCHIVE}.part" | awk '{print $1}')"
    [[ "$actual" == "$PHP_SHA256" ]] || fail "downloaded archive checksum is ${actual}; expected ${PHP_SHA256}"
    mv "${ARCHIVE}.part" "$ARCHIVE"
}

render_ini() {
    local template="$1" destination="$2"
    awk -v project_root="$ROOT_DIR" '{ gsub(/@PROJECT_ROOT@/, project_root); print }' "$template" > "$destination"
}

run_logged() {
    local status
    set +e
    "$@" 2>&1 | tee -a "$LOG_FILE"
    status=${PIPESTATUS[0]}
    set -e
    printf '[exit %s] %q\n' "$status" "$1" >> "$LOG_FILE"
    return "$status"
}

main() {
    validate_pin
    preflight
    if [[ "${1:-}" == '--preflight' ]]; then
        [[ $# -eq 1 ]] || fail 'usage: bin/build-php85.sh [--preflight]'
        return
    fi
    [[ $# -eq 0 ]] || fail 'usage: bin/build-php85.sh [--preflight]'
    mkdir -p "${ROOT_DIR}/var/log/php85"

    if runtime_is_correct; then
        printf 'PHP %s runtime already verified: %s\n' "$PHP_VERSION" "$PREFIX"
        return
    fi
    [[ ! -e "$PREFIX" ]] || fail "incomplete or incorrect runtime at ${PREFIX}; do not overwrite it"
    [[ ! -e "$SOURCE_DIR" ]] || fail "source directory already exists at ${SOURCE_DIR}; do not overwrite it"
    [[ ! -e "$BUILD_DIR" ]] || fail "build directory already exists at ${BUILD_DIR}; do not overwrite it"
    [[ -f "$BASE_INI" && -f "$CGI_INI" ]] || fail 'runtime INI templates are missing'

    mkdir -p "$DOWNLOAD_DIR" "$SOURCE_ROOT" "$BUILD_ROOT" "$LOG_DIR"
    : > "$LOG_FILE"
    printf 'PHP %s build started: %s\n' "$PHP_VERSION" "$(date -u +%FT%TZ)" | tee -a "$LOG_FILE"
    fetch_metadata
    fetch_archive
    # The archive has a verified official SHA-256, so tar path pre-scanning is unnecessary.
    tar -xJf "$ARCHIVE" -C "$SOURCE_ROOT" 2>&1 | tee -a "$LOG_FILE"
    [[ -x "${SOURCE_DIR}/configure" ]] || fail 'released PHP configure script was not extracted'

    local jobs
    jobs="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '1')"
    [[ "$jobs" =~ ^[1-9][0-9]*$ ]] || jobs=1
    (( jobs <= 4 )) || jobs=4

    local -a configure_args=(
        "--prefix=${PREFIX}"
        "--with-config-file-path=${PREFIX}/etc"
        "--with-config-file-scan-dir=${PREFIX}/etc/conf.d"
        '--enable-cli' '--enable-cgi' '--enable-gd' '--with-jpeg' '--with-freetype'
        '--enable-mbstring' '--disable-mbregex' '--with-mysqli=mysqlnd' '--with-pdo-mysql=mysqlnd'
        '--with-sqlite3' '--with-pdo-sqlite' '--with-curl' '--with-openssl' '--with-zlib'
        '--enable-sockets' '--enable-bcmath' '--with-iconv'
    )
    if pkg-config --exists icu-uc icu-i18n; then
        configure_args+=('--enable-intl')
    fi

    mkdir "$BUILD_DIR"
    (
        cd "$BUILD_DIR"
        run_logged "${SOURCE_DIR}/configure" "${configure_args[@]}"
        run_logged make "-j${jobs}"
        run_logged make install
    )
    mkdir -p "${PREFIX}/etc/conf.d"
    render_ini "$BASE_INI" "${PREFIX}/etc/php.ini"
    render_ini "$CGI_INI" "${PREFIX}/etc/conf.d/20-cgi.ini"

    runtime_is_correct || fail "build finished but PHP ${PHP_VERSION} runtime verification failed; inspect ${LOG_FILE}"
    printf 'PHP %s runtime installed at %s\nBuild log: %s\n' "$PHP_VERSION" "$PREFIX" "$LOG_FILE"
}

main "$@"
