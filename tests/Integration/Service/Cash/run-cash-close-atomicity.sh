#!/usr/bin/env bash
set -euo pipefail

container="cash-close-atomicity-${$}-${RANDOM}"
database="cash_close_test_${$}_${RANDOM}"
password="cash-close-${RANDOM}${RANDOM}${RANDOM}"
port=""

cleanup() {
    docker stop "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

docker run --pull=never --rm --name "$container" \
    -p 127.0.0.1::3306 \
    -e MARIADB_ROOT_PASSWORD="$password" \
    -e MARIADB_DATABASE="$database" \
    -e MARIADB_USER=cash_close_test \
    -e MARIADB_PASSWORD="$password" \
    -d mariadb:11 >/dev/null

port="$(docker inspect -f '{{(index (index .NetworkSettings.Ports "3306/tcp") 0).HostPort}}' "$container")"
for _ in $(seq 1 15); do
    if docker exec "$container" mariadb-admin ping --protocol=tcp --host=127.0.0.1 --user=cash_close_test --password="$password" --silent >/dev/null 2>&1; then
        export TPV_CASH_CLOSE_TEST=1
        export TPV_DB_HOST=127.0.0.1
        export TPV_DB_PORT="$port"
        export TPV_DB_NAME="$database"
        export TPV_DB_USER=cash_close_test
        export TPV_DB_PASSWORD="$password"
        ./.runtime/php-8.5.12/bin/php vendor/bin/phpunit --no-coverage --do-not-cache-result tests/Integration/Service/Cash/CashSessionServiceConcurrencyIntegrationTest.php
        exit $?
    fi
    sleep 1
done

echo 'Disposable MariaDB did not become ready within 15 seconds.' >&2
exit 1
