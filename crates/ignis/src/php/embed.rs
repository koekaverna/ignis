//! Lifecycle of the embedded PHP engine on the *current* OS thread.
//!
//! FFI contract:
//! - `Engine::init` must be called on the thread that will execute PHP; it
//!   owns the SAPI for the process (one engine per process in Cycle 0).
//! - All PHP execution happens through `&mut Engine` methods, so the borrow
//!   checker enforces single-threaded access at compile time.
//! - `Drop` runs `php_embed_shutdown`, after which no Zend call is valid.
use std::ffi::{CString, c_char};
use std::marker::PhantomData;
use std::path::Path;

use anyhow::{Context, Result, bail};
use ignis_sys as sys;

use super::module;

pub struct Engine {
    // !Send + !Sync: pins the engine to the thread that initialised it.
    _not_send: PhantomData<*mut ()>,
    argv: Vec<CString>,
}

impl Engine {
    /// Starts TSRM, the SAPI, MINIT (with the ignis module) and RINIT.
    pub fn init(argv0: &str) -> Result<Engine> {
        let argv = vec![CString::new(argv0)?];
        let mut argv_ptrs: Vec<*mut c_char> = argv.iter().map(|s| s.as_ptr() as *mut c_char).collect();
        argv_ptrs.push(std::ptr::null_mut());
        // SAFETY: php_embed_module is a process global written before any PHP
        // runs; we replace one function pointer with a compatible extern "C"
        // fn. php_embed_init then uses it exactly once. argv outlives the call
        // (kept in the struct) because SG(request_info).argv keeps the pointer.
        // Optional php.ini (PHP_INI_SYSTEM entries such as test_scheduler.enable
        // cannot be set at runtime). Kept alive for the process in INI_PATH.
        let ini = std::env::var("IGNIS_PHP_INI").ok().and_then(|p| CString::new(p).ok());
        let rc = unsafe {
            sys::php_embed_module.startup = Some(module::ignis_sapi_startup);
            if let Some(ini) = ini {
                let leaked: &'static CString = Box::leak(Box::new(ini));
                sys::php_embed_module.php_ini_path_override = leaked.as_ptr() as *mut c_char;
            }
            sys::php_embed_init(1, argv_ptrs.as_mut_ptr())
        };
        if rc != sys::SUCCESS as i32 {
            bail!("php_embed_init failed ({rc})");
        }
        #[cfg(php_async_abi)]
        crate::backend::async_core::install();
        Ok(Engine { _not_send: PhantomData, argv })
    }

    /// Runs a script file as the primary script of the current request.
    /// Returns PHP's exit status (0 unless `exit(n)` was called).
    pub fn run_file(&mut self, path: &Path) -> Result<i32> {
        let cpath = CString::new(path.to_str().context("non-utf8 path")?)?;
        // SAFETY: file handle is stack-owned and destroyed after execution;
        // `php_execute_script` may longjmp on fatal error (zend_bailout) — it
        // catches that internally via zend_try when called outside a
        // zend_first_try block? No: php_execute_script uses zend_try itself
        // and returns false on bailout, so no longjmp crosses our frame.
        let status = unsafe {
            let mut fh: sys::zend_file_handle = std::mem::zeroed();
            sys::zend_stream_init_filename(&mut fh, cpath.as_ptr());
            fh.primary_script = true;
            let ok = sys::php_execute_script(&mut fh);
            sys::zend_destroy_file_handle(&mut fh);
            if !ok {
                tracing::warn!(?path, "php_execute_script returned false (fatal error or exit)");
            }
            exit_status()
        };
        Ok(status)
    }
}

/// `EG(exit_status)` without the macro: read through the executor globals
/// exported by TSRM. In a ZTS build the macro expands to
/// `((zend_executor_globals*)(((char*)tsrm_get_ls_cache()) + executor_globals_offset))->exit_status`.
unsafe fn exit_status() -> i32 {
    // SAFETY: on a PHP thread after startup, tsrm_get_ls_cache() is the
    // thread's resource block and executor_globals_offset is the fast-id
    // offset assigned at zend_startup. Same expression as the EG() macro.
    unsafe {
        let base = sys::tsrm_get_ls_cache() as *mut u8;
        let eg = base.add(sys::executor_globals_offset) as *mut sys::zend_executor_globals;
        (*eg).exit_status as i32
    }
}

impl Drop for Engine {
    fn drop(&mut self) {
        // SAFETY: mirrors php_embed_init; called once on the owning thread.
        unsafe { sys::php_embed_shutdown() }
        let _ = &self.argv;
    }
}
