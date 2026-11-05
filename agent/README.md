# VenPos print agent (`agent/`)

Rust workspace: `agent-core` (library), `venpos-agent` (service + CLI),
`venpos-agent-tray` (Windows tray icon). Specification: `docs/prd/agente-impresion.md`;
shop-owner guide: `docs/print-agent.md`.

## Build and test

```
cargo test --workspace
./build.sh            # release builds for Linux and Windows (mingw-w64) into dist/
```

## Windows installer (NSIS)

`installer/venpos-agent.nsi` builds `dist/VenPosAgent-<version>-setup.exe` from
`dist/windows-x86_64/*.exe`:

```
makensis -DVERSION=0.1.0 installer/venpos-agent.nsi
```

Wizard: TPV address + 6-digit code (or "Emparejar más tarde"), ticket/label printer
from the machine-wide printers, optional test page, service install, tray in
`HKLM\...\Run`. Silent: `setup.exe /S [/SERVER=url] [/CODE=123456] [/PRINTER=name]
[/LABELPRINTER=name] [/TESTPRINT]`; uninstall with `uninstall.exe /S [/PURGE]`.
Exit codes are the agent CLI codes (3 code rejected, 5 unreachable, 7 needs admin…).
Not signed yet (PRD I2).

`.github/workflows/print-agent-windows.yml` builds it on `windows-latest`, installs
it silently, checks `sc query VenPosAgent` is RUNNING, prints through the spooler to
a "Generic / Text Only" printer whose port is a file and compares the bytes
exactly (`test-print --bytes-file`, hidden), reinstalls over the running service
and uninstalls.

## Linux packaging (phase 1)

`packaging/linux/install.sh` (root): copies the binary to `/usr/local/bin`, creates
the `venpos-agent` system user in group `lp`, runs `venpos-agent install` (systemd
unit, enabled and started) and, with `--server URL --code 123456`, pairs it.

```
sudo packaging/linux/install.sh dist/linux-x86_64/venpos-agent --server https://tpv --code 123456
```

### .deb (any amd64 Debian/Ubuntu)

```
packaging/linux/build-musl.sh   # static musl binary, built in a rust:alpine container
packaging/linux/build-deb.sh    # dist/venpos-agent_<version>_amd64.deb
```

The binary is static, so the package does not depend on the system glibc. On the
till:

```
sudo apt install ./venpos-agent_0.1.0_amd64.deb
sudo venpos-agent pair 123456 --server https://tu-tpv
sudo venpos-agent set-printer --target ticket --cups NOMBRE_DE_LA_COLA
```

The package adds a **VenPos Agent** entry to the applications menu: `venpos-agent-setup`, a terminal assistant (re-runs itself with sudo) to pair, choose the ticket/label printer from `list-printers`, test print, see the status and restart the service.

`postinst` creates the `venpos-agent` user (group `lp`) and runs `venpos-agent
install` (systemd unit, enabled and started); upgrades keep the pairing and restart
the service; `apt remove` stops and removes the service; `apt purge` also deletes
`/etc/venpos-agent`, its state, logs and user.
