#!/usr/bin/env bash
# Static Linux build (musl) in a rust:alpine container: runs on any x86_64 Linux,
# whatever its glibc. Output: dist/linux-x86_64-musl/venpos-agent. Needs Docker.
set -euo pipefail
cd "$(dirname "$0")/../.."
RUST_IMAGE="${RUST_IMAGE:-rust:1.98-alpine}"
docker run --rm -e CARGO_TARGET_DIR=/src/target/alpine -v "$PWD":/src -w /src "$RUST_IMAGE" \
    sh -c "apk add --no-cache musl-dev >/dev/null && cargo build --release --locked -p venpos-agent && chown -R $(id -u):$(id -g) /src/target/alpine"
install -D -m 0755 target/alpine/release/venpos-agent dist/linux-x86_64-musl/venpos-agent
file dist/linux-x86_64-musl/venpos-agent
