//! ADR-0043 §5: the blocking detector. The interposer times every call that blocks a PHP thread
//! inside a fiber and hands the site and the duration here; this decides whether it is worth a
//! word — by mode, threshold and the allow list — names the request and the route, takes a PHP
//! backtrace on the first occurrence per site, keeps the per-site statistics the shutdown report
//! is written from, and keeps a short ring of records the test helpers read back.
//!
//! Everything here runs on the PHP thread after the syscall returned and before any fiber switch,
//! so reading `EG(current_execute_data)` is sound; nothing here allocates on the path that stays
//! under the threshold.
#![cfg_attr(not(feature = "universal-park"), allow(dead_code))]
use std::cell::RefCell;
use std::collections::{BTreeMap, HashSet};
use std::ffi::{c_char, c_int};
use std::sync::Mutex;

use ignis_sys as sys;

use super::tsrm;
use crate::alerts::{Event, Key, Level};
use crate::lock::LockUnpoisoned;
use crate::recovery::{BlockingMode, Settings};
use crate::scoreboard;

/// One blocking call the detector decided to record.
#[derive(Clone, Debug)]
pub struct Record {
    pub sequence: u64,
    pub site: String,
    pub duration_us: u64,
    pub errno: c_int,
    pub request_id: u64,
    pub uri: String,
    pub allowed: bool,
    pub frames: Vec<String>,
}

/// Lifetime statistics of one site, for the report.
#[derive(Clone, Debug, Default)]
pub struct SiteStats {
    pub count: u64,
    pub max_us: u64,
    pub total_us: u64,
    pub routes: BTreeMap<String, u64>,
    pub first_frames: Vec<String>,
    pub allowed: bool,
}

const RING: usize = 256;

thread_local! {
    static RECORDS: RefCell<Vec<Record>> = const { RefCell::new(Vec::new()) };
    static TRACED: RefCell<HashSet<u32>> = RefCell::new(HashSet::new());
}

static SEQUENCE: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);
static SITES: Mutex<BTreeMap<String, SiteStats>> = Mutex::new(BTreeMap::new());

/// Called by the interposer after a blocking forward inside a fiber.
///
/// # Safety
/// PHP thread inside a fiber, after the syscall returned, before any fiber switch.
pub unsafe fn observe(site: u32, duration_us: u64, errno: c_int) {
    let settings = Settings::global();
    if settings.blocking_mode == BlockingMode::Off || duration_us < settings.blocking_threshold_us {
        return;
    }
    let name = scoreboard::site_name(site);
    let (library, symbol) = name.split_once(':').unwrap_or((name.as_str(), ""));
    // SAFETY: the caller upholds the contract; the meta helpers only read EG.
    let (request_id, fiber_allows) = unsafe {
        let meta = super::fibermeta::current();
        if meta.is_null() { (0, false) } else { ((*meta).request_id, (*meta).allow_blocking) }
    };
    let uri = super::module::try_reactor().and_then(|r| r.uri_of(request_id)).unwrap_or_default();
    let route = route_of(&uri);
    let allowed = fiber_allows || settings.blocking_allowed(library, symbol, &uri);
    let first_here = TRACED.with(|t| t.borrow_mut().insert(site));
    // SAFETY: as above; the frames are read from this thread's own live frame chain.
    let frames = if settings.blocking_trace && first_here { unsafe { frames(6) } } else { Vec::new() };
    let sequence = SEQUENCE.fetch_add(1, std::sync::atomic::Ordering::Relaxed) + 1;
    let record = Record { sequence, site: name.clone(), duration_us, errno, request_id, uri: uri.clone(), allowed, frames: frames.clone() };
    RECORDS.with(|r| {
        let mut r = r.borrow_mut();
        if r.len() == RING {
            r.remove(0);
        }
        r.push(record);
    });
    {
        let mut sites = SITES.lock_unpoisoned();
        let stats = sites.entry(name.clone()).or_default();
        stats.count += 1;
        stats.max_us = stats.max_us.max(duration_us);
        stats.total_us += duration_us;
        *stats.routes.entry(route.clone()).or_default() += 1;
        stats.allowed = allowed;
        if stats.first_frames.is_empty() && !frames.is_empty() {
            stats.first_frames = frames.clone();
        }
    }
    let level = match (allowed, settings.blocking_mode) {
        (true, _) => Level::Info,
        (false, BlockingMode::Strict | BlockingMode::Fatal) => Level::Error,
        (false, _) => Level::Warn,
    };
    let mut fields = vec![
        ("duration_us", duration_us.to_string()),
        ("request", request_id.to_string()),
        ("uri", uri.clone()),
        ("errno", errno.to_string()),
        ("worker", scoreboard::current_index().map_or("?".to_string(), |i| i.to_string())),
    ];
    if !frames.is_empty() {
        fields.push(("trace", frames.join(" <- ")));
    }
    crate::alerts::report(Event { key: Key::new("blocking_call", name.clone(), route), level, value_us: duration_us, fields });
    if settings.blocking_mode == BlockingMode::Fatal && !allowed {
        eprintln!(
            "ignis: blocking call {name} took {duration_us} us inside a fiber on {uri} (IGNIS_BLOCKING=fatal){}",
            if frames.is_empty() { String::new() } else { format!("\n  at {}", frames.join("\n  at ")) }
        );
        std::process::exit(70);
    }
}

/// The path without its query, which is what an alert key and the report group by.
fn route_of(uri: &str) -> String {
    uri.split_once('?').map_or(uri, |(p, _)| p).to_string()
}

/// Up to `limit` user frames of the running fiber, innermost first, as `file:line`.
///
/// # Safety
/// PHP thread; reads this thread's live frame chain only.
unsafe fn frames(limit: usize) -> Vec<String> {
    let mut out = Vec::new();
    // SAFETY: the caller upholds the contract; every pointer is null-checked and only read.
    unsafe {
        let mut frame = (*tsrm::executor_globals()).current_execute_data;
        while !frame.is_null() && out.len() < limit {
            let func = (*frame).func;
            if !func.is_null() && (*func).type_ as u32 == sys::ZEND_USER_FUNCTION {
                let op_array = &(*func).op_array;
                let opline = (*frame).opline;
                if !op_array.filename.is_null() && !opline.is_null() {
                    let file = std::ffi::CStr::from_ptr((*op_array.filename).val.as_ptr() as *const c_char).to_string_lossy();
                    let function = if op_array.function_name.is_null() {
                        String::new()
                    } else {
                        format!(
                            " {}()",
                            std::ffi::CStr::from_ptr((*op_array.function_name).val.as_ptr() as *const c_char).to_string_lossy()
                        )
                    };
                    out.push(format!("{file}:{}{function}", (*opline).lineno));
                }
            }
            frame = (*frame).prev_execute_data;
        }
    }
    out
}

/// The current record sequence; a test takes it before the code under test and reads what came after.
pub fn sequence() -> u64 {
    SEQUENCE.load(std::sync::atomic::Ordering::Relaxed)
}

/// This thread's records newer than `since`.
pub fn records_since(since: u64) -> Vec<Record> {
    RECORDS.with(|r| r.borrow().iter().filter(|rec| rec.sequence > since).cloned().collect())
}

pub fn sites() -> BTreeMap<String, SiteStats> {
    SITES.lock_unpoisoned().clone()
}

/// The shutdown / SIGUSR2 report (ADR-0043 §5): one object per site.
pub fn report_json() -> String {
    let sites = sites();
    let mut out = String::from("{\"blocking_sites\":[");
    for (i, (name, s)) in sites.iter().enumerate() {
        if i > 0 {
            out.push(',');
        }
        let routes = s.routes.iter().map(|(r, n)| format!("{}:{n}", json_string(r))).collect::<Vec<_>>().join(",");
        let frames = s.first_frames.iter().map(|f| json_string(f)).collect::<Vec<_>>().join(",");
        out.push_str(&format!(
            "{{\"site\":{},\"count\":{},\"max_us\":{},\"mean_us\":{},\"allowed\":{},\"routes\":{{{routes}}},\"first_trace\":[{frames}]}}",
            json_string(name),
            s.count,
            s.max_us,
            if s.count == 0 { 0 } else { s.total_us / s.count },
            s.allowed
        ));
    }
    out.push_str("],\"mode\":");
    out.push_str(&json_string(Settings::global().blocking_mode.name()));
    out.push_str(",\"threshold_us\":");
    out.push_str(&Settings::global().blocking_threshold_us.to_string());
    out.push_str("}\n");
    out
}

fn json_string(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('"');
    for c in s.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out.push('"');
    out
}

/// Writes the report where `blocking.report` points, if anywhere. Called at shutdown and on SIGUSR2.
pub fn write_report() {
    if let Some(path) = &Settings::global().blocking_report {
        match std::fs::write(path, report_json()) {
            Ok(()) => tracing::info!(path, "blocking report written"),
            Err(e) => tracing::warn!(path, error = %e, "blocking report not written"),
        }
    }
}

/// `ignis_blocking_sequence(): int`
pub unsafe extern "C" fn zif_ignis_blocking_sequence(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage.
    unsafe { super::zval::set_long(rv, sequence() as i64) };
}

/// `ignis_blocking_records(int $since): array` — this thread's blocking records newer than `$since`.
pub unsafe extern "C" fn zif_ignis_blocking_records(ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: VM frame on the PHP thread; every value is copied into engine-owned storage.
    unsafe {
        let since = super::zval::arg_long(ex, 1).unwrap_or(0).max(0) as u64;
        super::zval::set_new_array(rv);
        for rec in records_since(since) {
            let mut item: sys::zval = std::mem::zeroed();
            super::zval::set_new_array(&mut item);
            sys::add_assoc_long_ex(&mut item, c"sequence".as_ptr(), 8, rec.sequence as i64);
            sys::add_assoc_stringl_ex(&mut item, c"site".as_ptr(), 4, rec.site.as_ptr() as *const c_char, rec.site.len());
            sys::add_assoc_long_ex(&mut item, c"duration_us".as_ptr(), 11, rec.duration_us as i64);
            sys::add_assoc_long_ex(&mut item, c"errno".as_ptr(), 5, rec.errno as i64);
            sys::add_assoc_long_ex(&mut item, c"request".as_ptr(), 7, rec.request_id as i64);
            sys::add_assoc_stringl_ex(&mut item, c"uri".as_ptr(), 3, rec.uri.as_ptr() as *const c_char, rec.uri.len());
            sys::add_assoc_bool_ex(&mut item, c"allowed".as_ptr(), 7, rec.allowed);
            let mut frames: sys::zval = std::mem::zeroed();
            super::zval::set_new_array(&mut frames);
            for f in &rec.frames {
                sys::add_next_index_stringl(&mut frames, f.as_ptr() as *const c_char, f.len());
            }
            sys::add_assoc_zval_ex(&mut item, c"trace".as_ptr(), 5, &mut frames);
            sys::zend_hash_next_index_insert((*rv).value.arr, &mut item);
        }
    }
}

/// `ignis_blocking_report(): string` — the JSON report, for tests and for `SIGUSR2`-less operators.
pub unsafe extern "C" fn zif_ignis_blocking_report(_ex: *mut sys::zend_execute_data, rv: *mut sys::zval) {
    // SAFETY: rv is VM-owned writable storage; the string is copied.
    unsafe { *rv = super::zval::string_zval(report_json().as_bytes()) };
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_route_drops_the_query() {
        assert_eq!(route_of("/a/b?x=1"), "/a/b");
        assert_eq!(route_of("/a"), "/a");
    }

    #[test]
    fn json_strings_are_escaped() {
        assert_eq!(json_string("a\"b\\c\n"), "\"a\\\"b\\\\c\\n\"");
    }
}
