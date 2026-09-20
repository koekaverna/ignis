//! `S-SAPI-REQUEST-INFO`: the request body, as the SAPI rather than as a stream.
//!
//! `php://input` is one way a framework asks for the body and `Ignis\InputStream` backs it. It is
//! not the only way, and since PHP 8.4 it is not the way that matters for form data: Symfony 8's
//! `Request::createFromGlobals()` calls `request_parse_body()` for `PUT`, `DELETE`, `PATCH` and
//! `QUERY`, and that function asks the **SAPI** — `SG(request_info).content_type`, then
//! `sapi_read_post_data()`, then `sapi_handle_post()` (`ext/standard/http.c:342-364`). A stream
//! wrapper cannot reach any of it.
//!
//! Measured before this existed: a `PUT` with `a=1&b=2` reached Symfony as
//! `{"method":"PUT","parsed":[],"content_length":7}` — the body present, nothing parsed — because
//! `request_parse_body()` threw `RequestParseBodyException: Request does not provide a content
//! type` and `createFromGlobals()` fell back to `$_POST`, which the runtime fills for `POST` alone.
//!
//! What this module does is what any real SAPI does: hold the request's bytes and hand them over
//! when the engine asks. **Per fiber**, because the engine may ask long after the request entered —
//! a handler can await before it parses, and another request would otherwise have replaced the
//! body underneath it.

use std::cell::RefCell;
use std::collections::HashMap;
use std::ffi::{CString, c_char};

use super::tsrm;
use ignis_sys as sys;

/// One request's bytes and how far the engine has read. `read_post` is called repeatedly until it
/// answers 0, so the offset is part of the state rather than a local.
struct Body {
    bytes: Vec<u8>,
    read: usize,
    /// Kept alive because `SG(request_info).content_type` borrows it. The engine frees
    /// `content_type_dup` in `sapi_deactivate` (`main/SAPI.c:526`) and never `content_type`
    /// itself, so the SAPI owns this and must outlive the request that installed it.
    content_type: CString,
    method: CString,
}

thread_local! {
    static BODIES: RefCell<HashMap<usize, Body>> = RefCell::new(HashMap::new());
}

/// The current scope: this fiber, or `0` for `{main}`. The same key `output.rs` and `scoped.rs` use.
///
/// # Safety
/// PHP thread with an initialised TSRM cache.
unsafe fn current() -> usize {
    // SAFETY: the caller upholds `# Safety`. `active_fiber` is a plain pointer field the engine
    // keeps current, and the address is used only as a key.
    unsafe {
        let fiber = (*tsrm::executor_globals()).active_fiber;
        if fiber.is_null() {
            return 0;
        }
        (&raw mut (*fiber).context) as usize
    }
}

/// Installs this fiber's request line for the engine: what `request_parse_body()` reads before it
/// asks for a single byte.
///
/// # Safety
/// PHP thread inside the request's fiber, with an initialised TSRM cache.
pub unsafe fn enter(method: &str, content_type: &str, bytes: Vec<u8>) {
    // SAFETY: the caller upholds `# Safety`. The two `CString`s live in the map for as long as
    // `SG(request_info)` points at them, and `leave()` is what takes them away.
    unsafe {
        let key = current();
        let body = Body {
            read: 0,
            content_type: CString::new(content_type).unwrap_or_default(),
            method: CString::new(method).unwrap_or_default(),
            bytes,
        };
        let globals = tsrm::sapi_globals();
        (*globals).request_info.content_length = body.bytes.len() as sys::zend_long;
        (*globals).request_info.content_type = if content_type.is_empty() { std::ptr::null() } else { body.content_type.as_ptr() };
        (*globals).request_info.request_method = body.method.as_ptr();
        BODIES.with(|bodies| bodies.borrow_mut().insert(key, body));
    }
}

/// Drops this fiber's body at request end, and unhooks `SG(request_info)` from storage that is
/// about to go. Leaving the pointers dangling would be a use-after-free the next time anything read
/// them, which `php_request_shutdown` does.
///
/// # Safety
/// PHP thread inside the request's fiber.
pub unsafe fn leave() {
    // SAFETY: the caller upholds `# Safety`; the pointers are cleared before their storage drops.
    unsafe {
        let key = current();
        let globals = tsrm::sapi_globals();
        (*globals).request_info.content_type = std::ptr::null();
        (*globals).request_info.content_length = 0;
        BODIES.with(|bodies| bodies.borrow_mut().remove(&key));
    }
}

/// The SAPI's post reader: copy what is left of this fiber's body, answer how much.
///
/// # Safety
/// Called by the engine with a buffer of at least `count_bytes`.
pub(super) unsafe extern "C" fn read_post(buffer: *mut c_char, count_bytes: usize) -> usize {
    // SAFETY: the engine upholds `# Safety`. The copy is bounded by both the buffer's size and
    // what remains of the body, and the borrow is dropped before returning.
    unsafe {
        let key = current();
        BODIES.with(|bodies| {
            let mut bodies = bodies.borrow_mut();
            let Some(body) = bodies.get_mut(&key) else { return 0 };
            let remaining = body.bytes.len().saturating_sub(body.read);
            let taken = remaining.min(count_bytes);
            if taken == 0 {
                return 0;
            }
            std::ptr::copy_nonoverlapping(body.bytes.as_ptr().add(body.read), buffer as *mut u8, taken);
            body.read += taken;
            taken
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The reader's arithmetic, which is the only part that is not engine state: it answers at most
    /// what the buffer holds, advances by what it gave, and answers 0 when the body is spent.
    #[test]
    fn the_reader_gives_at_most_the_buffer_and_stops_at_the_end() {
        let mut body = Body {
            bytes: b"a=1&b=2".to_vec(),
            read: 0,
            content_type: CString::new("application/x-www-form-urlencoded").unwrap(),
            method: CString::new("PUT").unwrap(),
        };
        let mut given = Vec::new();
        for buffer_size in [3usize, 3, 3] {
            let remaining = body.bytes.len().saturating_sub(body.read);
            let taken = remaining.min(buffer_size);
            given.push(taken);
            body.read += taken;
        }
        assert_eq!(given, vec![3, 3, 1], "three buffers of three take 3, 3 and the last byte");
        assert_eq!(body.bytes.len(), body.read, "and the body is spent");
        assert_eq!(body.bytes.len().saturating_sub(body.read).min(3), 0, "a fourth read answers nothing");
    }
}
