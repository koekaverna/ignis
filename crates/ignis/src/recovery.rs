//! ADR-0043 §8: recovery, blocking-detector and alert settings, resolved once from the environment
//! (`IGNIS_*` beats profile beats default) so the Rust runtime and the PHP loop agree on one answer.
use std::sync::OnceLock;
use std::time::Duration;

/// One knob for the common case: every duration and mode below has a per-profile default.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Profile {
    Production,
    LoadTest,
    Test,
}

impl Profile {
    pub fn parse(name: &str) -> Option<Profile> {
        match name.trim() {
            "production" | "prod" | "" => Some(Profile::Production),
            "load-test" | "load_test" | "loadtest" | "load" => Some(Profile::LoadTest),
            "test" => Some(Profile::Test),
            _ => None,
        }
    }

    pub fn name(self) -> &'static str {
        match self {
            Profile::Production => "production",
            Profile::LoadTest => "load-test",
            Profile::Test => "test",
        }
    }
}

/// What the detector does with a blocking call over the threshold (ADR-0043 §5).
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum BlockingMode {
    Off,
    Warn,
    Strict,
    Fatal,
}

impl BlockingMode {
    pub fn parse(name: &str) -> Option<BlockingMode> {
        match name.trim() {
            "off" | "0" | "false" => Some(BlockingMode::Off),
            "warn" | "1" | "true" | "" => Some(BlockingMode::Warn),
            "strict" => Some(BlockingMode::Strict),
            "fatal" => Some(BlockingMode::Fatal),
            _ => None,
        }
    }

    pub fn name(self) -> &'static str {
        match self {
            BlockingMode::Off => "off",
            BlockingMode::Warn => "warn",
            BlockingMode::Strict => "strict",
            BlockingMode::Fatal => "fatal",
        }
    }
}

/// How a running fiber is killed at L3: the engine's uncatchable `GracefulExit`, or a catchable
/// `Ignis\KilledException` for applications that want to log it.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum KillKind {
    Graceful,
    Exception,
}

/// The per-route overridable subset (`[recovery.routes."/prefix/"]`).
#[derive(Clone, Copy, PartialEq, Eq, Debug, Default)]
pub struct RouteOverride {
    pub fiber_timeout_ms: Option<u64>,
    pub stall_kill_ms: Option<u64>,
    pub stall_abandon_ms: Option<u64>,
    pub busy_warn_ms: Option<u64>,
}

#[derive(Clone, Debug)]
pub struct Settings {
    pub profile: Profile,
    /// L0, per request; 0 = off.
    pub fiber_timeout_ms: u64,
    /// A single park, userland or C-side, may not outlast this; 0 = off, 30 s under `IGNIS_CHAOS`.
    pub park_timeout_ms: u64,
    /// A worker running PHP or a blocking forward this long without yielding is a `stall` warn.
    pub busy_warn_ms: u64,
    /// L3/L4; 0 = off.
    pub stall_kill_ms: u64,
    /// L5; 0 = off.
    pub stall_abandon_ms: u64,
    /// Health answers 503 past this many leaked (abandoned) workers.
    pub leaked_workers_max: u64,
    pub kill: KillKind,
    /// L2: force-close a fiber that parks again after a cancellation, or only log it.
    pub force_close_on_swallowed_cancel: bool,
    /// Longest-prefix route overrides, sorted longest first.
    pub routes: Vec<(String, RouteOverride)>,
    pub blocking_mode: BlockingMode,
    pub blocking_threshold_us: u64,
    pub blocking_trace: bool,
    pub blocking_report: Option<String>,
    /// `library:symbol[@route-prefix]` patterns reported at info instead of the mode's level.
    #[cfg_attr(not(feature = "universal-park"), allow(dead_code))]
    pub blocking_allow: Vec<String>,
    pub alert_window: Duration,
    pub alert_escalate_count: u64,
    pub alert_recover_windows: u32,
    pub alert_max_lines_per_s: u32,
    pub watch_tick: Duration,
}

/// The profile table of ADR-0043 §8, one row per key that a profile changes.
struct ProfileDefaults {
    fiber_timeout_ms: u64,
    busy_warn_ms: u64,
    stall_kill_ms: u64,
    stall_abandon_ms: u64,
    blocking_mode: BlockingMode,
    blocking_threshold_us: u64,
    blocking_trace: bool,
}

fn defaults_of(profile: Profile) -> ProfileDefaults {
    match profile {
        Profile::Production => ProfileDefaults {
            fiber_timeout_ms: 0,
            busy_warn_ms: 500,
            stall_kill_ms: 10_000,
            stall_abandon_ms: 30_000,
            blocking_mode: BlockingMode::Warn,
            blocking_threshold_us: 1_000,
            blocking_trace: false,
        },
        Profile::LoadTest => ProfileDefaults {
            fiber_timeout_ms: 30_000,
            busy_warn_ms: 50,
            stall_kill_ms: 10_000,
            stall_abandon_ms: 30_000,
            blocking_mode: BlockingMode::Strict,
            blocking_threshold_us: 200,
            blocking_trace: true,
        },
        Profile::Test => ProfileDefaults {
            fiber_timeout_ms: 5_000,
            busy_warn_ms: 20,
            stall_kill_ms: 2_000,
            stall_abandon_ms: 10_000,
            blocking_mode: BlockingMode::Strict,
            blocking_threshold_us: 100,
            blocking_trace: true,
        },
    }
}

/// Chaos scheduling is a gate tool, and a gate needs a floor under a hung park (S-FIBER-TIMEOUT).
pub const CHAOS_PARK_TIMEOUT_MS: u64 = 30_000;

fn chaos_park_timeout_ms(lookup: &dyn Fn(&str) -> Option<String>) -> u64 {
    if flag(lookup, "IGNIS_CHAOS", false) { CHAOS_PARK_TIMEOUT_MS } else { 0 }
}

/// Read a variable through `lookup`; unset or unparsable keeps `default` (a typo must not move a limit).
fn number(lookup: &dyn Fn(&str) -> Option<String>, name: &str, default: u64) -> u64 {
    lookup(name).and_then(|v| v.trim().parse().ok()).unwrap_or(default)
}

fn flag(lookup: &dyn Fn(&str) -> Option<String>, name: &str, default: bool) -> bool {
    match lookup(name).as_deref().map(str::trim) {
        Some("1") | Some("true") | Some("on") | Some("yes") => true,
        Some("0") | Some("false") | Some("off") | Some("no") | Some("") => false,
        _ => default,
    }
}

impl Settings {
    /// Resolves everything from the process environment, once.
    pub fn global() -> &'static Settings {
        static SETTINGS: OnceLock<Settings> = OnceLock::new();
        SETTINGS.get_or_init(|| Settings::from_lookup(&|name| std::env::var(name).ok()))
    }

    /// The resolution itself, over any source of variables, so it can be tested without touching
    /// the process environment.
    pub fn from_lookup(lookup: &dyn Fn(&str) -> Option<String>) -> Settings {
        let profile = lookup("IGNIS_PROFILE").and_then(|v| Profile::parse(&v)).unwrap_or(Profile::Production);
        let d = defaults_of(profile);
        Settings {
            profile,
            fiber_timeout_ms: number(lookup, "IGNIS_FIBER_TIMEOUT_MS", d.fiber_timeout_ms),
            park_timeout_ms: number(lookup, "IGNIS_PARK_TIMEOUT_MS", chaos_park_timeout_ms(lookup)),
            busy_warn_ms: number(lookup, "IGNIS_BUSY_WARN_MS", d.busy_warn_ms),
            stall_kill_ms: number(lookup, "IGNIS_STALL_KILL_MS", d.stall_kill_ms),
            stall_abandon_ms: number(lookup, "IGNIS_STALL_ABANDON_MS", d.stall_abandon_ms),
            leaked_workers_max: number(lookup, "IGNIS_LEAKED_WORKERS_MAX", 3),
            kill: match lookup("IGNIS_KILL").as_deref().map(str::trim) {
                Some("exception") => KillKind::Exception,
                _ => KillKind::Graceful,
            },
            force_close_on_swallowed_cancel: lookup("IGNIS_ON_SWALLOWED_CANCEL").as_deref().map(str::trim) != Some("log"),
            routes: parse_routes(&lookup("IGNIS_RECOVERY_ROUTES").unwrap_or_default()),
            blocking_mode: lookup("IGNIS_BLOCKING").and_then(|v| BlockingMode::parse(&v)).unwrap_or(d.blocking_mode),
            blocking_threshold_us: number(lookup, "IGNIS_BLOCKING_US", d.blocking_threshold_us),
            blocking_trace: flag(lookup, "IGNIS_BLOCKING_TRACE", d.blocking_trace),
            blocking_report: lookup("IGNIS_BLOCKING_REPORT").filter(|p| !p.trim().is_empty()),
            blocking_allow: lookup("IGNIS_BLOCKING_ALLOW")
                .unwrap_or_default()
                .split(',')
                .map(str::trim)
                .filter(|s| !s.is_empty())
                .map(str::to_string)
                .collect(),
            alert_window: Duration::from_secs(number(lookup, "IGNIS_ALERT_WINDOW_S", 60).max(1)),
            alert_escalate_count: number(lookup, "IGNIS_ALERT_ESCALATE_COUNT", 100).max(2),
            alert_recover_windows: number(lookup, "IGNIS_ALERT_RECOVER_WINDOWS", 5).max(1) as u32,
            alert_max_lines_per_s: number(lookup, "IGNIS_ALERT_MAX_LINES_PER_S", 20).max(1) as u32,
            watch_tick: Duration::from_millis(number(lookup, "IGNIS_WATCH_TICK_MS", 100).clamp(10, 10_000)),
        }
    }

    /// The override for `uri`, longest matching prefix wins; an empty override when none matches.
    pub fn route(&self, uri: &str) -> RouteOverride {
        self.routes.iter().find(|(prefix, _)| uri.starts_with(prefix.as_str())).map(|(_, o)| *o).unwrap_or_default()
    }

    pub fn fiber_timeout_ms_for(&self, uri: &str) -> u64 {
        self.route(uri).fiber_timeout_ms.unwrap_or(self.fiber_timeout_ms)
    }

    pub fn stall_kill_ms_for(&self, uri: &str) -> u64 {
        self.route(uri).stall_kill_ms.unwrap_or(self.stall_kill_ms)
    }

    pub fn stall_abandon_ms_for(&self, uri: &str) -> u64 {
        self.route(uri).stall_abandon_ms.unwrap_or(self.stall_abandon_ms)
    }

    pub fn busy_warn_ms_for(&self, uri: &str) -> u64 {
        self.route(uri).busy_warn_ms.unwrap_or(self.busy_warn_ms)
    }

    /// Whether `library:symbol` on `uri` is an accepted site (`blocking.allow`), reported at info.
    pub fn blocking_allowed(&self, library: &str, symbol: &str, uri: &str) -> bool {
        self.blocking_allow.iter().any(|pattern| allow_matches(pattern, library, symbol, uri))
    }

    /// One line for the startup banner.
    pub fn summary(&self) -> String {
        format!(
            "profile={} fiber_timeout={}ms park_timeout={}ms busy_warn={}ms stall_kill={}ms stall_abandon={}ms kill={} swallowed_cancel={} blocking={}@{}us{}",
            self.profile.name(),
            self.fiber_timeout_ms,
            self.park_timeout_ms,
            self.busy_warn_ms,
            self.stall_kill_ms,
            self.stall_abandon_ms,
            match self.kill {
                KillKind::Graceful => "graceful",
                KillKind::Exception => "exception",
            },
            if self.force_close_on_swallowed_cancel { "force-close" } else { "log" },
            self.blocking_mode.name(),
            self.blocking_threshold_us,
            if self.blocking_trace { "+trace" } else { "" }
        )
    }
}

/// `library[:symbol][@route-prefix]`; `*` matches any library or symbol.
fn allow_matches(pattern: &str, library: &str, symbol: &str, uri: &str) -> bool {
    let (site, route) = pattern.split_once('@').unwrap_or((pattern, ""));
    if !route.is_empty() && !uri.starts_with(route) {
        return false;
    }
    let (lib_pattern, sym_pattern) = site.split_once(':').unwrap_or((site, "*"));
    let lib_ok = lib_pattern == "*" || library.starts_with(lib_pattern);
    let sym_ok = sym_pattern == "*" || sym_pattern == symbol;
    lib_ok && sym_ok
}

/// `IGNIS_RECOVERY_ROUTES`: `prefix=key:value;key:value,prefix2=...` — the wire form of
/// `[recovery.routes."prefix"]`, written by `config.rs` and read here and by `Loop.php`.
pub fn parse_routes(text: &str) -> Vec<(String, RouteOverride)> {
    let mut routes: Vec<(String, RouteOverride)> = text
        .split(',')
        .filter_map(|entry| {
            let (prefix, keys) = entry.split_once('=')?;
            let prefix = prefix.trim();
            if prefix.is_empty() {
                return None;
            }
            let mut o = RouteOverride::default();
            for kv in keys.split(';') {
                let Some((k, v)) = kv.split_once(':') else { continue };
                let v: Option<u64> = v.trim().parse().ok();
                match k.trim() {
                    "fiber_timeout_ms" => o.fiber_timeout_ms = v,
                    "stall_kill_ms" => o.stall_kill_ms = v,
                    "stall_abandon_ms" => o.stall_abandon_ms = v,
                    "busy_warn_ms" => o.busy_warn_ms = v,
                    _ => {}
                }
            }
            Some((prefix.to_string(), o))
        })
        .collect();
    routes.sort_by_key(|route| std::cmp::Reverse(route.0.len()));
    routes
}

/// The inverse of `parse_routes`, for `config.rs`.
pub fn format_routes(routes: &[(String, RouteOverride)]) -> String {
    routes
        .iter()
        .map(|(prefix, o)| {
            let mut keys = Vec::new();
            if let Some(v) = o.fiber_timeout_ms {
                keys.push(format!("fiber_timeout_ms:{v}"));
            }
            if let Some(v) = o.stall_kill_ms {
                keys.push(format!("stall_kill_ms:{v}"));
            }
            if let Some(v) = o.stall_abandon_ms {
                keys.push(format!("stall_abandon_ms:{v}"));
            }
            if let Some(v) = o.busy_warn_ms {
                keys.push(format!("busy_warn_ms:{v}"));
            }
            format!("{prefix}={}", keys.join(";"))
        })
        .collect::<Vec<_>>()
        .join(",")
}

#[cfg(test)]
mod tests {
    #[test]
    fn a_park_has_no_ceiling_unless_chaos_or_the_variable_says_so() {
        let none = |_: &str| None;
        assert_eq!(Settings::from_lookup(&none).park_timeout_ms, 0);
        let chaos = |name: &str| (name == "IGNIS_CHAOS").then(|| "1".to_string());
        assert_eq!(Settings::from_lookup(&chaos).park_timeout_ms, CHAOS_PARK_TIMEOUT_MS);
        let explicit = |name: &str| match name {
            "IGNIS_CHAOS" => Some("1".to_string()),
            "IGNIS_PARK_TIMEOUT_MS" => Some("2000".to_string()),
            _ => None,
        };
        assert_eq!(Settings::from_lookup(&explicit).park_timeout_ms, 2000, "an explicit value beats the chaos default");
    }

    use super::*;
    use std::collections::HashMap;

    fn settings(vars: &[(&str, &str)]) -> Settings {
        let map: HashMap<String, String> = vars.iter().map(|(k, v)| (k.to_string(), v.to_string())).collect();
        Settings::from_lookup(&|name| map.get(name).cloned())
    }

    #[test]
    fn the_production_profile_is_the_default_and_its_numbers_are_the_adrs() {
        let s = settings(&[]);
        assert_eq!(s.profile, Profile::Production);
        assert_eq!((s.fiber_timeout_ms, s.busy_warn_ms, s.stall_kill_ms, s.stall_abandon_ms), (0, 500, 10_000, 30_000));
        assert_eq!(s.blocking_mode, BlockingMode::Warn);
        assert_eq!(s.blocking_threshold_us, 1_000);
        assert!(!s.blocking_trace);
        assert_eq!(s.kill, KillKind::Graceful);
        assert!(s.force_close_on_swallowed_cancel);
    }

    #[test]
    fn the_test_profile_sets_every_derived_key() {
        let s = settings(&[("IGNIS_PROFILE", "test")]);
        assert_eq!((s.fiber_timeout_ms, s.busy_warn_ms, s.stall_kill_ms), (5_000, 20, 2_000));
        assert_eq!(s.blocking_mode, BlockingMode::Strict);
        assert_eq!(s.blocking_threshold_us, 100);
        assert!(s.blocking_trace);
    }

    #[test]
    fn an_explicit_key_beats_the_profile() {
        let s =
            settings(&[("IGNIS_PROFILE", "test"), ("IGNIS_STALL_KILL_MS", "0"), ("IGNIS_BLOCKING", "warn"), ("IGNIS_BLOCKING_TRACE", "0")]);
        assert_eq!(s.stall_kill_ms, 0);
        assert_eq!(s.blocking_mode, BlockingMode::Warn);
        assert!(!s.blocking_trace);
        assert_eq!(s.busy_warn_ms, 20, "untouched keys keep the profile's value");
    }

    #[test]
    fn an_unparsable_number_keeps_the_default_instead_of_moving_a_limit() {
        let s = settings(&[("IGNIS_STALL_KILL_MS", "lots")]);
        assert_eq!(s.stall_kill_ms, 10_000);
    }

    #[test]
    fn an_unknown_profile_or_mode_falls_back_to_the_default() {
        let s = settings(&[("IGNIS_PROFILE", "staging"), ("IGNIS_BLOCKING", "loud")]);
        assert_eq!(s.profile, Profile::Production);
        assert_eq!(s.blocking_mode, BlockingMode::Warn);
    }

    #[test]
    fn route_overrides_use_the_longest_matching_prefix() {
        let s = settings(&[("IGNIS_RECOVERY_ROUTES", "/=fiber_timeout_ms:100,/export/=fiber_timeout_ms:120000;stall_kill_ms:0")]);
        assert_eq!(s.fiber_timeout_ms_for("/export/x"), 120_000);
        assert_eq!(s.stall_kill_ms_for("/export/x"), 0);
        assert_eq!(s.fiber_timeout_ms_for("/x"), 100);
        assert_eq!(s.stall_kill_ms_for("/x"), 10_000, "a key the route does not set keeps the global value");
        assert_eq!(s.busy_warn_ms_for("/export/x"), 500);
    }

    #[test]
    fn routes_round_trip_through_the_wire_form() {
        let routes = parse_routes("/export/=fiber_timeout_ms:120000;stall_kill_ms:0,/=busy_warn_ms:5");
        assert_eq!(parse_routes(&format_routes(&routes)), routes);
        assert_eq!(routes[0].0, "/export/", "sorted longest first");
    }

    #[test]
    fn allow_patterns_match_library_symbol_and_route() {
        let s = settings(&[("IGNIS_BLOCKING_ALLOW", "libphp:read@/config, sqlite3.so, *:fdatasync")]);
        assert!(s.blocking_allowed("libphp.so", "read", "/config/reload"));
        assert!(!s.blocking_allowed("libphp.so", "read", "/api"));
        assert!(s.blocking_allowed("sqlite3.so", "pread64", "/anything"));
        assert!(s.blocking_allowed("libpq.so.5", "fdatasync", "/"));
        assert!(!s.blocking_allowed("libpq.so.5", "poll", "/"));
    }

    #[test]
    fn alert_settings_have_floors() {
        let s = settings(&[("IGNIS_ALERT_WINDOW_S", "0"), ("IGNIS_ALERT_ESCALATE_COUNT", "1"), ("IGNIS_ALERT_MAX_LINES_PER_S", "0")]);
        assert_eq!(s.alert_window, Duration::from_secs(1));
        assert_eq!(s.alert_escalate_count, 2);
        assert_eq!(s.alert_max_lines_per_s, 1);
    }
}
