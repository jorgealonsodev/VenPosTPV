#!/usr/bin/env bash
# Desktop build of venpos-agent-tray (tray icon + window) against glibc, in a
# Debian 12 container so it runs on glibc >= 2.36 desktops (Debian 12,
# Ubuntu 22.04+ ... see the deb Depends). The window needs OpenGL/EGL,
# xkbcommon and X11/Wayland, which it loads with dlopen at run time; that
# does not work in a static musl binary, so only the service is built with
# musl (build-musl.sh). Output: dist/linux-x86_64-gnu/venpos-agent-tray. Needs Docker.
set -euo pipefail
cd "$(dirname "$0")/../.."
RUST_IMAGE="${RUST_IMAGE:-rust:1.98-bookworm}"
docker run --rm -e CARGO_TARGET_DIR=/src/target/bookworm -v "$PWD":/src -w /src "$RUST_IMAGE" \
    sh -c "cargo build --release --locked -p venpos-agent-tray && chown -R $(id -u):$(id -g) /src/target/bookworm"
install -D -m 0755 target/bookworm/release/venpos-agent-tray dist/linux-x86_64-gnu/venpos-agent-tray
file dist/linux-x86_64-gnu/venpos-agent-tray
# Newest glibc symbol version the binary needs (goes into the deb Depends).
objdump -T dist/linux-x86_64-gnu/venpos-agent-tray | grep -o 'GLIBC_[0-9.]*' | sort -uV | tail -1
