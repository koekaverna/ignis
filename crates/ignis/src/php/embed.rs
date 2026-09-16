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
}

/// `argv` handed to PHP (`$argv`/`$_SERVER['argv']`, E15): `[script, args...]` like the CLI.
/// Leaked for the process because `SG(request_info).argv` keeps the pointer on every thread.
struct Argv {
    argc: i32,
    ptrs: *mut *mut c_char,
}
// SAFETY: written once before any thread reads it; the pointed-to strings are never freed.
unsafe impl Sync for Argv {}
unsafe impl Send for Argv {}
static ARGV: std::sync::OnceLock<Argv> = std::sync::OnceLock::new();

fn php_argv() -> &'static Argv {
    ARGV.get().expect("Engine::init before WorkerThread::attach")
}

impl Engine {
    /// Starts TSRM, the SAPI, MINIT (with the ignis module) and RINIT.
    /// `args` is `[script, args...]`; PHP sees it as `$argv` (register_argc_argv is on by default).
    pub fn init(args: &[String]) -> Result<Engine> {
        let argv: Vec<CString> = args.iter().map(|a| CString::new(a.as_str())).collect::<Result<_, _>>()?;
        let argv: &'static [CString] = Box::leak(argv.into_boxed_slice());
        let mut argv_ptrs: Vec<*mut c_char> = argv.iter().map(|s| s.as_ptr() as *mut c_char).collect();
        argv_ptrs.push(std::ptr::null_mut());
        let argc = argv.len() as i32;
        let argv_ptrs: &'static mut [*mut c_char] = Box::leak(argv_ptrs.into_boxed_slice());
        let _ = ARGV.set(Argv { argc, ptrs: argv_ptrs.as_mut_ptr() });
        // SAFETY: php_embed_module is a process global written before any PHP
        // runs; we replace one function pointer with a compatible extern "C"
        // fn. php_embed_init then uses it exactly once. argv outlives the call
        // (kept in the struct) because SG(request_info).argv keeps the pointer.
        // Optional php.ini (PHP_INI_SYSTEM entries such as test_scheduler.enable
        // cannot be set at runtime). Kept alive for the process in INI_PATH.
        let ini = std::env::var("IGNIS_PHP_INI").ok().and_then(|p| CString::new(p).ok());
        // PHP_BINARY (E15a finding: empty under the stock embed, breaks suites that re-exec PHP).
        let exe = std::env::current_exe().ok().and_then(|p| CString::new(p.to_string_lossy().into_owned()).ok());
        let rc = unsafe {
            sys::php_embed_module.startup = Some(module::ignis_sapi_startup);
            sys::php_embed_module.register_server_variables = Some(register_server_variables);
            if let Some(exe) = exe {
                let leaked: &'static CString = Box::leak(Box::new(exe));
                sys::php_embed_module.executable_location = leaked.as_ptr() as *mut c_char;
            }
            if let Some(ini) = ini {
                let leaked: &'static CString = Box::leak(Box::new(ini));
                sys::php_embed_module.php_ini_path_override = leaked.as_ptr() as *mut c_char;
            }
            sys::php_embed_init(argc, argv_ptrs.as_mut_ptr())
        };
        if rc != sys::SUCCESS as i32 {
            bail!("php_embed_init failed ({rc})");
        }
        #[cfg(php_async_abi)]
        crate::backend::async_core::install();
        Ok(Engine { _not_send: PhantomData })
    }

    /// Runs a script file as the primary script of the current request.
    /// Returns PHP's exit status (0 unless `exit(n)` was called).
    pub fn run_file(&mut self, path: &Path) -> Result<i32> {
        run_file_on_current_thread(path)
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

/// A non-main PHP thread (ADR-0004). Created on the thread it will run on.
///
/// FFI contract: `ts_resource(0)` allocates this thread's Zend globals (TSRM
/// runs every registered ctor); `php_request_startup` activates a request on
/// them. `Drop` runs `php_request_shutdown` + `ts_free_thread`. `!Send` pins
/// it to its thread; the main-thread `Engine` must outlive every `WorkerThread`.
pub struct WorkerThread {
    _not_send: PhantomData<*mut ()>,
}

impl WorkerThread {
    pub fn attach() -> Result<WorkerThread> {
        // SAFETY: called on a fresh OS thread after php_embed_init completed on
        // the main thread (the caller guarantees ordering via thread spawn order).
        unsafe {
            if sys::ts_resource_ex(0, std::ptr::null_mut()).is_null() {
                bail!("ts_resource(0) returned NULL");
            }
            // Mirror php_embed_init: never chdir() to the script directory.
            // chdir is process-wide, so a worker doing it would break every
            // other thread's relative paths.
            let sg = (sys::tsrm_get_ls_cache() as *mut u8).add(sys::sapi_globals_offset) as *mut sys::sapi_globals_struct;
            (*sg).options |= 1; // SAPI_OPTION_NO_CHDIR (main/SAPI.h: #define SAPI_OPTION_NO_CHDIR 1)
            // Same `$argv` as the main thread (php_build_argv reads SG(request_info) at request startup).
            let a = php_argv();
            (*sg).request_info.argc = a.argc;
            (*sg).request_info.argv = a.ptrs;
            if sys::php_request_startup() != sys::SUCCESS {
                bail!("php_request_startup failed on worker thread");
            }
        }
        Ok(WorkerThread { _not_send: PhantomData })
    }

    /// Same as [`Engine::run_file`] but on this worker thread.
    pub fn run_file(&mut self, path: &Path) -> Result<i32> {
        run_file_on_current_thread(path)
    }

    /// Evaluate PHP source on this thread (E16 offload worker loop embedded in the binary).
    pub fn eval(&mut self, code: &str, name: &str) -> Result<()> {
        let code = CString::new(code.trim_start_matches("<?php"))?;
        let name = CString::new(name)?;
        // SAFETY: request is started on this thread; zend_eval_stringl compiles and runs the code.
        let rc = unsafe { sys::zend_eval_stringl(code.as_ptr(), code.as_bytes().len(), std::ptr::null_mut(), name.as_ptr()) };
        if rc != sys::SUCCESS {
            bail!("eval failed");
        }
        Ok(())
    }
}

impl Drop for WorkerThread {
    fn drop(&mut self) {
        // SAFETY: mirrors attach(); on the owning thread.
        unsafe {
            sys::php_request_shutdown(std::ptr::null_mut());
            sys::ts_free_thread();
        }
    }
}

fn run_file_on_current_thread(path: &Path) -> Result<i32> {
    let cpath = CString::new(path.to_str().context("non-utf8 path")?)?;
    // SAFETY: file handle is stack-owned and destroyed after execution;
    // php_execute_script wraps execution in zend_try and returns false on
    // bailout, so no longjmp crosses our frame.
    let status = unsafe {
        // Like php-cli: a `#!` first line on the primary script is skipped (E15b: vendor/bin/phpunit).
        let cg = (sys::tsrm_get_ls_cache() as *mut u8).add(sys::compiler_globals_offset) as *mut sys::zend_compiler_globals;
        (*cg).skip_shebang = true;
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

/// SAPI callback at request startup (every thread): the CLI-style `$_SERVER` script entries the
/// stock embed leaves unset (E15b: PHPUnit's Configuration\Merger does `realpath($_SERVER['PHP_SELF'])`).
/// The embed's own callback (environment import) is reproduced first.
unsafe extern "C" fn register_server_variables(track_vars_array: *mut sys::zval) {
    // SAFETY: called by php_hash_environment on the request's thread with the live $_SERVER array.
    unsafe {
        if let Some(import) = sys::php_import_environment_variables {
            import(track_vars_array);
        }
        let Some(a) = ARGV.get() else { return };
        if a.argc < 1 {
            return;
        }
        let script = *a.ptrs;
        for name in [c"PHP_SELF", c"SCRIPT_NAME", c"SCRIPT_FILENAME", c"PATH_TRANSLATED"] {
            sys::php_register_variable(name.as_ptr(), script, track_vars_array);
        }
        sys::php_register_variable(c"DOCUMENT_ROOT".as_ptr(), c"".as_ptr(), track_vars_array);
    }
}

/// MINIT: `PHP_BINARY`. php_embed_init() overwrites `executable_location` with argv[0] (the
/// script), so php_binary_init() finds no executable and registers "" before any MINIT runs.
/// Re-register the persistent constant from `current_exe()` (E15a finding: suites that re-exec PHP).
pub unsafe fn fix_php_binary(module_number: std::ffi::c_int) {
    let Some(exe) = std::env::current_exe().ok().map(|p| p.to_string_lossy().into_owned()) else { return };
    // SAFETY: MINIT on the main thread, before zend_post_startup copies the constants table for
    // other threads; the strings are permanent interned strings and never freed.
    unsafe {
        let eg = (sys::tsrm_get_ls_cache() as *mut u8).add(sys::executor_globals_offset) as *mut sys::zend_executor_globals;
        let Some(intern) = sys::zend_string_init_interned else { return };
        sys::zend_hash_str_del((*eg).zend_constants, c"PHP_BINARY".as_ptr(), 10);
        let mut c: sys::zend_constant = std::mem::zeroed();
        c.value.value.str_ = intern(exe.as_ptr() as *const c_char, exe.len(), true);
        c.value.u1.type_info = sys::IS_INTERNED_STRING_EX;
        c.name = intern(c"PHP_BINARY".as_ptr(), 10, true);
        // ZEND_CONSTANT_SET_FLAGS(&c, CONST_PERSISTENT, module_number)
        c.value.u2.constant_flags = ((module_number as u32) << 16) | 1;
        sys::zend_register_constant(&mut c);
        let pg = (sys::tsrm_get_ls_cache() as *mut u8).add(sys::core_globals_offset) as *mut sys::_php_core_globals;
        // PG(php_binary) is read by proc_open-of-PHP helpers; core_globals_dtor free()s it, so it
        // must come from libc's allocator, not Rust's (mimalloc): strdup.
        if let Ok(c) = CString::new(exe) {
            if !(*pg).php_binary.is_null() {
                libc::free((*pg).php_binary as *mut libc::c_void);
            }
            (*pg).php_binary = libc::strdup(c.as_ptr());
        }
    }
}

impl Drop for Engine {
    fn drop(&mut self) {
        // SAFETY: mirrors php_embed_init; called once on the owning thread.
        unsafe { sys::php_embed_shutdown() }
    }
}
