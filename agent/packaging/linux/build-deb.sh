#!/usr/bin/env bash
# Builds dist/venpos-agent_<version>_amd64.deb from the static (musl) service
# dist/linux-x86_64-musl/venpos-agent (packaging/linux/build-musl.sh) and the
# desktop tray/window dist/linux-x86_64-gnu/venpos-agent-tray
# (packaging/linux/build-gui.sh). Needs dpkg-deb and objdump (binutils).
#
#   sudo apt install ./venpos-agent_0.1.1_amd64.deb
#   sudo venpos-agent pair 123456 --server https://tu-tpv
set -euo pipefail
cd "$(dirname "$0")/../.."

BIN=dist/linux-x86_64-musl/venpos-agent
[ -x "$BIN" ] || { echo "Falta $BIN: ejecuta packaging/linux/build-musl.sh antes" >&2; exit 1; }
TRAY=dist/linux-x86_64-gnu/venpos-agent-tray
[ -x "$TRAY" ] || { echo "Falta $TRAY: ejecuta packaging/linux/build-gui.sh antes" >&2; exit 1; }
# Newest glibc symbol version the tray needs, e.g. 2.35.
GLIBC_MIN=$(objdump -T "$TRAY" | grep -o 'GLIBC_[0-9.]*' | sed 's/GLIBC_//' | sort -uV | tail -1)
[ -n "$GLIBC_MIN" ] || { echo "No encuentro la versión de glibc de $TRAY" >&2; exit 1; }
VERSION=$(sed -n 's/^version = "\(.*\)"/\1/p' Cargo.toml | head -1)
[ -n "$VERSION" ] || { echo "No encuentro la versión en Cargo.toml" >&2; exit 1; }

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
PKG="$WORK/venpos-agent_${VERSION}_amd64"
install -d "$PKG/DEBIAN" "$PKG/usr/bin" "$PKG/usr/share/doc/venpos-agent"
install -m 0755 "$BIN" "$PKG/usr/bin/venpos-agent"
install -m 0755 packaging/linux/deb/venpos-agent-setup "$PKG/usr/bin/venpos-agent-setup"
install -m 0755 "$TRAY" "$PKG/usr/bin/venpos-agent-tray"
# Applications menu: the VenPos Agent window (action: the terminal assistant).
install -D -m 0644 packaging/linux/deb/venpos-agent.desktop "$PKG/usr/share/applications/venpos-agent.desktop"
# Tray icon at login, for every desktop user.
install -D -m 0644 packaging/linux/deb/venpos-agent-tray.desktop "$PKG/etc/xdg/autostart/venpos-agent-tray.desktop"
install -D -m 0644 packaging/linux/deb/venpos-agent.svg "$PKG/usr/share/icons/hicolor/scalable/apps/venpos-agent.svg"
echo /etc/xdg/autostart/venpos-agent-tray.desktop > "$PKG/DEBIAN/conffiles"
install -m 0755 packaging/linux/deb/postinst packaging/linux/deb/prerm packaging/linux/deb/postrm "$PKG/DEBIAN/"
install -m 0644 README.md "$PKG/usr/share/doc/venpos-agent/README.md"

SIZE=$(du -sk "$PKG/usr" "$PKG/etc" | awk '{s += $1} END {print s}')
cat > "$PKG/DEBIAN/control" <<CONTROL
Package: venpos-agent
Version: $VERSION
Section: misc
Priority: optional
Architecture: amd64
Maintainer: VenPosTPV <desarrollos@enfoquestic.com>
Installed-Size: $SIZE
Depends: passwd, libc6 (>= $GLIBC_MIN), libgcc-s1, libegl1, libgl1, libwayland-client0, libwayland-egl1, libx11-6, libx11-xcb1, libxcb1, libxcursor1, libxi6, libxkbcommon0, libxkbcommon-x11-0, libxrender1
Recommends: cups-client, libegl-mesa0, libgl1-mesa-dri, libnotify-bin
Description: VenPos print agent
 Prints the tickets, labels and cash drawer pulses of a VenPosTPV till on
 the printer connected to this computer (CUPS, USB device or network).
 Includes a tray icon and a window (applications menu: VenPos Agent) to
 choose the printers, print a test page and pair with the TPV.
CONTROL

mkdir -p dist
OUT="dist/venpos-agent_${VERSION}_amd64.deb"
dpkg-deb --root-owner-group --build "$PKG" "$OUT" >/dev/null
echo "$OUT"
dpkg-deb --info "$OUT" | sed -n '/Package:/,/Description:/p'
