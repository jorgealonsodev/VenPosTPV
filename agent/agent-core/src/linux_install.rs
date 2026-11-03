//! Linux `install`/`uninstall` (A2, I3 phase 1): systemd unit for the
//! `venpos-agent` user in group `lp`.
use std::path::Path;

pub const UNIT_PATH: &str = "/etc/systemd/system/venpos-agent.service";
pub const SERVICE_USER: &str = "venpos-agent";

pub fn systemd_unit(exe: &Path) -> String {
    format!(
        "[Unit]
Description=VenPos print agent
Wants=network-online.target
After=network-online.target cups.service

[Service]
Type=simple
User={SERVICE_USER}
Group={SERVICE_USER}
SupplementaryGroups=lp
ExecStart={exe} run
Restart=always
RestartSec=5
UMask=0077
RuntimeDirectory=venpos-agent
RuntimeDirectoryMode=0750
StateDirectory=venpos-agent
LogsDirectory=venpos-agent
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/etc/venpos-agent

[Install]
WantedBy=multi-user.target
",
        exe = exe.display()
    )
}

/// Commands an administrator runs when `install` is not executed as root.
pub fn manual_instructions(exe: &Path) -> String {
    format!(
        "Ejecuta como root:\n  useradd --system --no-create-home --shell /usr/sbin/nologin --groups lp {SERVICE_USER}\n  install -d -o {SERVICE_USER} -g {SERVICE_USER} -m 0750 /etc/venpos-agent /var/lib/venpos-agent /var/log/venpos-agent\n  {exe} install   # como root escribe {UNIT_PATH}\n  systemctl daemon-reload && systemctl enable --now venpos-agent\n",
        exe = exe.display()
    )
}

/// Parses `/etc/passwd` for a user's uid and gid.
pub fn lookup_user(passwd: &str, user: &str) -> Option<(u32, u32)> {
    passwd.lines().find_map(|line| {
        let mut f = line.split(':');
        if f.next()? != user {
            return None;
        }
        let _pw = f.next()?;
        let uid = f.next()?.parse().ok()?;
        let gid = f.next()?.parse().ok()?;
        Some((uid, gid))
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unit_runs_as_service_user_in_group_lp() {
        let unit = systemd_unit(Path::new("/usr/local/bin/venpos-agent"));
        for line in [
            "User=venpos-agent",
            "SupplementaryGroups=lp",
            "ExecStart=/usr/local/bin/venpos-agent run",
            "Restart=always",
            "WantedBy=multi-user.target",
            "NoNewPrivileges=yes",
            "RuntimeDirectory=venpos-agent",
            "RuntimeDirectoryMode=0750",
        ] {
            assert!(unit.lines().any(|l| l == line), "missing {line}:\n{unit}");
        }
    }

    #[test]
    fn passwd_lookup() {
        let passwd = "root:x:0:0:root:/root:/bin/bash\nvenpos-agent:x:998:997::/nonexistent:/usr/sbin/nologin\n";
        assert_eq!(lookup_user(passwd, "venpos-agent"), Some((998, 997)));
        assert_eq!(lookup_user(passwd, "nobody"), None);
    }
}
