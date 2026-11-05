#!/usr/bin/env bash
# Builds dist/venpos-agent_<version>_amd64.deb from the static (musl) binary
# dist/linux-x86_64-musl/venpos-agent (run packaging/linux/build-musl.sh first),
# so the package installs on any amd64 Debian/Ubuntu. Needs only dpkg-deb.
#
#   sudo apt install ./venpos-agent_0.1.0_amd64.deb
#   sudo venpos-agent pair 123456 --server https://tu-tpv
set -euo pipefail
cd "$(dirname "$0")/../.."

BIN=dist/linux-x86_64-musl/venpos-agent
[ -x "$BIN" ] || { echo "Falta $BIN: ejecuta packaging/linux/build-musl.sh antes" >&2; exit 1; }
VERSION=$(sed -n 's/^version = "\(.*\)"/\1/p' Cargo.toml | head -1)
[ -n "$VERSION" ] || { echo "No encuentro la versión en Cargo.toml" >&2; exit 1; }

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
PKG="$WORK/venpos-agent_${VERSION}_amd64"
install -d "$PKG/DEBIAN" "$PKG/usr/bin" "$PKG/usr/share/doc/venpos-agent"
install -m 0755 "$BIN" "$PKG/usr/bin/venpos-agent"
install -m 0755 packaging/linux/deb/postinst packaging/linux/deb/prerm packaging/linux/deb/postrm "$PKG/DEBIAN/"
install -m 0644 README.md "$PKG/usr/share/doc/venpos-agent/README.md"

SIZE=$(du -sk "$PKG/usr" | cut -f1)
cat > "$PKG/DEBIAN/control" <<CONTROL
Package: venpos-agent
Version: $VERSION
Section: misc
Priority: optional
Architecture: amd64
Maintainer: VenPosTPV <desarrollos@enfoquestic.com>
Installed-Size: $SIZE
Depends: passwd
Recommends: cups-client
Description: VenPos print agent
 Prints the tickets, labels and cash drawer pulses of a VenPosTPV till on
 the printer connected to this computer (CUPS, USB device or network).
 Pair it with: sudo venpos-agent pair CODE --server https://your-tpv
CONTROL

mkdir -p dist
OUT="dist/venpos-agent_${VERSION}_amd64.deb"
dpkg-deb --root-owner-group --build "$PKG" "$OUT" >/dev/null
echo "$OUT"
dpkg-deb --info "$OUT" | sed -n '/Package:/,/Description:/p'
