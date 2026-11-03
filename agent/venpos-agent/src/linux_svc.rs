//! Linux service management (A2, I3 phase 1).
use crate::{ExitCode, Failure};
use agent_core::linux_install::{SERVICE_USER, UNIT_PATH, lookup_user, manual_instructions, systemd_unit};
use std::path::Path;
use std::process::Command;

fn is_root() -> bool {
    std::fs::read_to_string("/proc/self/status")
        .ok()
        .and_then(|s| {
            s.lines()
                .find(|l| l.starts_with("Uid:"))
                .map(|l| l.split_whitespace().nth(2) == Some("0"))
        })
        .unwrap_or(false)
}

fn run(cmd: &str, args: &[&str]) -> Result<(), Failure> {
    let st = Command::new(cmd)
        .args(args)
        .status()
        .map_err(|e| Failure::new(ExitCode::Other, format!("{cmd}: {e}")))?;
    if st.success() {
        Ok(())
    } else {
        Err(Failure::new(ExitCode::Other, format!("{cmd} {} falló", args.join(" "))))
    }
}

pub fn install() -> Result<(), Failure> {
    let exe = std::env::current_exe()?;
    if !is_root() {
        println!("{}", systemd_unit(&exe));
        println!("{}", manual_instructions(&exe));
        return Err(Failure::new(
            ExitCode::NeedsAdmin,
            "install necesita root; arriba tienes la unidad y los pasos",
        ));
    }
    let passwd = std::fs::read_to_string("/etc/passwd").unwrap_or_default();
    if lookup_user(&passwd, SERVICE_USER).is_none() {
        run(
            "useradd",
            &[
                "--system",
                "--no-create-home",
                "--shell",
                "/usr/sbin/nologin",
                "--groups",
                "lp",
                SERVICE_USER,
            ],
        )?;
    }
    run(
        "install",
        &[
            "-d",
            "-o",
            SERVICE_USER,
            "-g",
            SERVICE_USER,
            "-m",
            "0750",
            "/etc/venpos-agent",
        ],
    )?;
    chown_to_service_user(&[
        Path::new("/etc/venpos-agent/key"),
        Path::new("/etc/venpos-agent/config.json"),
    ]);
    std::fs::write(UNIT_PATH, systemd_unit(&exe))?;
    run("systemctl", &["daemon-reload"])?;
    run("systemctl", &["enable", "--now", "venpos-agent"])?;
    println!("Servicio instalado: {UNIT_PATH}");
    Ok(())
}

pub fn uninstall() -> Result<(), Failure> {
    if !is_root() {
        println!(
            "Ejecuta como root:\n  systemctl disable --now venpos-agent\n  rm {UNIT_PATH}\n  systemctl daemon-reload"
        );
        return Err(Failure::new(ExitCode::NeedsAdmin, "uninstall necesita root"));
    }
    let _ = run("systemctl", &["disable", "--now", "venpos-agent"]);
    match std::fs::remove_file(UNIT_PATH) {
        Ok(()) => {}
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
        Err(e) => return Err(e.into()),
    }
    run("systemctl", &["daemon-reload"])?;
    println!("Servicio desinstalado; /etc/venpos-agent se conserva");
    Ok(())
}

/// E10: the key belongs to the service user (mode 0600 is set at creation).
pub fn chown_to_service_user(files: &[&Path]) {
    if !is_root() {
        return;
    }
    let passwd = std::fs::read_to_string("/etc/passwd").unwrap_or_default();
    let Some((uid, gid)) = lookup_user(&passwd, SERVICE_USER) else {
        eprintln!("Aviso: el usuario {SERVICE_USER} no existe todavía; ejecuta `install`");
        return;
    };
    for f in files {
        if f.exists()
            && let Err(e) = std::os::unix::fs::chown(f, Some(uid), Some(gid))
        {
            eprintln!("Aviso: no se pudo cambiar el propietario de {}: {e}", f.display());
        }
    }
}

pub fn try_restart() {
    if is_root() {
        let _ = Command::new("systemctl").args(["try-restart", "venpos-agent"]).status();
    }
}
