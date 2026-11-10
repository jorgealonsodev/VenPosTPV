#!/usr/bin/env bash
# Release builds of the print agent for Linux and Windows (cross, mingw-w64).
# Output in agent/dist/; prints sizes, sha256 and the DLLs each Windows
# binary imports (A10: no external DLLs beyond the system ones).
set -euo pipefail
cd "$(dirname "$0")"

LINUX=x86_64-unknown-linux-gnu
WIN=x86_64-pc-windows-gnu
# System DLLs that every Windows 10/11 has. Anything else fails the build.
ALLOWED_DLLS='^(kernel32|advapi32|bcrypt|bcryptprimitives|crypt32|gdi32|ntdll|ole32|oleaut32|shell32|user32|userenv|winspool\.drv|ws2_32|msvcrt|comctl32|uxtheme|dwmapi|shlwapi|secur32|ncrypt|imm32|opengl32|api-ms-win-[a-z0-9-]+)(\.dll)?$'

cargo build --release --locked --target "$LINUX" -p venpos-agent
cargo build --release --locked --target "$WIN" -p venpos-agent -p venpos-agent-tray

rm -rf dist
mkdir -p dist/linux-x86_64 dist/windows-x86_64
cp "target/$LINUX/release/venpos-agent" dist/linux-x86_64/
cp "target/$WIN/release/venpos-agent.exe" "target/$WIN/release/venpos-agent-tray.exe" dist/windows-x86_64/

echo
echo "== Sizes =="
find dist -type f -printf '%10s  %p\n' | sort -k2
echo
echo "== sha256 =="
sums=$(cd dist && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum)
echo "$sums" | tee dist/SHA256SUMS

OBJDUMP=$(command -v x86_64-w64-mingw32-objdump || command -v objdump)
echo
echo "== DLL imports (Windows) =="
status=0
for exe in dist/windows-x86_64/*.exe; do
    echo "$exe:"
    while read -r dll; do
        lower=$(printf '%s' "$dll" | tr '[:upper:]' '[:lower:]')
        if [[ "$lower" =~ $ALLOWED_DLLS ]]; then
            echo "  $dll"
        else
            echo "  $dll   <-- NOT A SYSTEM DLL"
            status=1
        fi
    done < <("$OBJDUMP" -p "$exe" | awk '/DLL Name:/ {print $3}' | sort -u)
done
echo
echo "== Linux dynamic libraries =="
ldd dist/linux-x86_64/venpos-agent | sed 's/^/  /' || true
if [ "$status" -ne 0 ]; then
    echo "ERROR: a Windows binary imports a non-system DLL" >&2
fi
exit "$status"
