#!/usr/bin/env bash
# VenPos Agent, Linux phase 1 (PRD I3): binary + systemd service.
#
#   sudo ./install.sh [path/to/venpos-agent] [--server https://tpv.example --code 123456]
#
# Copies the binary to /usr/local/bin, creates the system user venpos-agent
# in group lp (USB and CUPS printing), installs and starts the systemd unit
# (`venpos-agent install`) and, with --server and --code, pairs the agent.
# Then choose the printer, e.g.:
#   sudo venpos-agent set-printer --target ticket --cups Epson_TM-T20II
#   sudo venpos-agent set-printer --target ticket --device /dev/usb/lp0
set -euo pipefail

BIN_SRC=""
SERVER=""
CODE=""
while [ $# -gt 0 ]; do
    case "$1" in
        --server) SERVER="${2:?--server necesita la dirección}"; shift 2 ;;
        --code) CODE="${2:?--code necesita el código}"; shift 2 ;;
        -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
        -*) echo "opción desconocida: $1" >&2; exit 2 ;;
        *) BIN_SRC="$1"; shift ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Ejecuta como root: sudo $0 $*" >&2
    exit 7
fi
if [ -n "$CODE" ] && [ -z "$SERVER" ]; then
    echo "--code necesita también --server" >&2
    exit 2
fi

here="$(cd "$(dirname "$0")" && pwd)"
if [ -z "$BIN_SRC" ]; then
    for candidate in "$here/venpos-agent" "$here/../../dist/linux-x86_64/venpos-agent"; do
        if [ -x "$candidate" ]; then BIN_SRC="$candidate"; break; fi
    done
fi
if [ -z "$BIN_SRC" ] || [ ! -f "$BIN_SRC" ]; then
    echo "No encuentro el binario venpos-agent; pásalo como primer argumento" >&2
    exit 2
fi

USER_NAME=venpos-agent
BIN=/usr/local/bin/venpos-agent

if systemctl is-active --quiet venpos-agent 2>/dev/null; then
    systemctl stop venpos-agent
fi
install -m 0755 "$BIN_SRC" "$BIN"
echo "Instalado $BIN ($("$BIN" version))"

getent group lp >/dev/null || groupadd --system lp
if ! id -u "$USER_NAME" >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin --groups lp "$USER_NAME"
    echo "Creado el usuario $USER_NAME (grupo lp)"
else
    usermod -a -G lp "$USER_NAME"
fi

# Writes the unit, creates the directories and enables + starts the service.
"$BIN" install

if [ -n "$CODE" ]; then
    "$BIN" pair "$CODE" --server "$SERVER"
fi

echo
"$BIN" status || true
