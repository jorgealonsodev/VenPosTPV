//! Pairing (E1, E3, I1): one implementation for the CLI `pair` (run by an
//! administrator) and the IPC `pair` command (run by the service, which owns
//! its key). The 6-digit code, created by a TPV administrator, is the
//! authorization; the caller only decides where the key is stored.
use crate::api::{ApiClient, ApiError, HttpTiming, ProxyMode};
use crate::config::{Config, ConfigError};
use crate::secret::{AgentKey, KeyStore, PairingCode, SecretError};

#[derive(Debug, thiserror::Error)]
pub enum PairError {
    #[error("La dirección del TPV no es válida: tiene que empezar por https://")]
    InvalidUrl(#[source] ConfigError),
    #[error("El código tiene 6 cifras.")]
    InvalidCode,
    #[error("Código caducado o incorrecto. Genera uno nuevo en Ajustes → Cajas.")]
    CodeRejected,
    #[error("Demasiados intentos. Espera un minuto y vuelve a probar.")]
    TooManyAttempts,
    #[error("No se puede conectar con el TPV. Revisa la dirección y la conexión a Internet.")]
    Unreachable(#[source] ApiError),
    #[error("Para cambiar de TPV, vuelve a ejecutar el instalador como administrador")]
    OtherServer,
    #[error("{0}")]
    Api(ApiError),
    #[error("{0}")]
    Key(SecretError),
}

impl From<ApiError> for PairError {
    fn from(e: ApiError) -> Self {
        match e {
            ApiError::PairingCodeInvalid => PairError::CodeRejected,
            ApiError::RateLimited { .. } => PairError::TooManyAttempts,
            e if e.is_connectivity() => PairError::Unreachable(e),
            e => PairError::Api(e),
        }
    }
}

/// What a successful pairing leaves in the configuration.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Paired {
    pub server_url: String,
    pub terminal_id: i64,
    pub terminal_name: String,
}

impl Paired {
    /// Writes the pairing into `cfg`, keeping the chosen printers.
    pub fn apply(&self, cfg: &mut Config) {
        cfg.server_url = Some(self.server_url.clone());
        cfg.terminal_id = Some(self.terminal_id);
        cfg.terminal_name = Some(self.terminal_name.clone());
    }
}

/// Strict code check for the local channel: exactly six ASCII digits, no
/// spaces (the CLI is lenient through [`PairingCode::parse`]).
pub fn strict_code(code: &str) -> Result<PairingCode, PairError> {
    if code.len() == 6 && code.bytes().all(|b| b.is_ascii_digit()) {
        PairingCode::parse(code).map_err(|_| PairError::InvalidCode)
    } else {
        Err(PairError::InvalidCode)
    }
}

/// Where the local channel (tray) may pair (E11): HTTPS only, with no localhost
/// exception unless `allow_http_localhost` (tests), and only the TPV the agent
/// is already paired with, if any: moving to another TPV needs an administrator.
/// Returns the normalized URL.
pub fn local_channel_server(
    server: &str,
    current: Option<&str>,
    allow_http_localhost: bool,
) -> Result<String, PairError> {
    let server = crate::config::validate_server_url(server).map_err(PairError::InvalidUrl)?;
    if !allow_http_localhost && !server.starts_with("https://") {
        return Err(PairError::InvalidUrl(ConfigError::InsecureUrl(server)));
    }
    let current = current.map(|c| crate::config::validate_server_url(c).unwrap_or_else(|_| c.to_owned()));
    if current.is_some_and(|c| !c.eq_ignore_ascii_case(&server)) {
        return Err(PairError::OtherServer);
    }
    Ok(server)
}

/// Validates the URL, redeems the code at `POST /pair` and stores the key.
/// The configuration is left to the caller ([`Paired::apply`]).
pub fn pair(
    server: &str,
    code: &PairingCode,
    hostname: &str,
    proxy: ProxyMode,
    key_store: &KeyStore,
) -> Result<Paired, PairError> {
    let server = crate::config::validate_server_url(server).map_err(PairError::InvalidUrl)?;
    let api = ApiClient::new(&server, None, &HttpTiming::default(), proxy)?;
    let resp = api.pair(code, hostname)?;
    let key = AgentKey::parse(&resp.key).map_err(PairError::Key)?;
    key_store.save(&key).map_err(PairError::Key)?;
    Ok(Paired {
        server_url: server,
        terminal_id: resp.terminal_id,
        terminal_name: resp.terminal_name,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn server_answers_map_to_the_spanish_messages() {
        let msg = |e: ApiError| PairError::from(e).to_string();
        assert_eq!(
            msg(ApiError::PairingCodeInvalid),
            "Código caducado o incorrecto. Genera uno nuevo en Ajustes → Cajas."
        );
        assert_eq!(
            msg(ApiError::RateLimited { retry_after: None }),
            "Demasiados intentos. Espera un minuto y vuelve a probar."
        );
        let unreachable = "No se puede conectar con el TPV. Revisa la dirección y la conexión a Internet.";
        assert_eq!(msg(ApiError::Transport("refused".into())), unreachable);
        assert_eq!(
            msg(ApiError::Status {
                status: 503,
                code: None
            }),
            unreachable
        );
    }

    #[test]
    fn local_codes_are_exactly_six_digits() {
        assert!(strict_code("482913").is_ok());
        for bad in ["48291", "4829134", "48 913", " 482913", "48291a", "４８２９１３", ""] {
            assert!(matches!(strict_code(bad), Err(PairError::InvalidCode)), "{bad:?}");
        }
    }
}
