//! Generates bindings for the PHP embed SAPI from the ZTS build headers.
//!
//! Environment:
//! - `PHP_CONFIG` (default `/opt/php85-zts/bin/php-config`) locates the build.
//! - `IGNIS_LIBCLANG_PATH` is forwarded to bindgen's `LIBCLANG_PATH` if set.
use std::env;
use std::path::PathBuf;
use std::process::Command;

fn php_config(arg: &str) -> String {
    let pc = env::var("PHP_CONFIG").unwrap_or_else(|_| "/opt/php85-zts/bin/php-config".into());
    let out = Command::new(&pc)
        .arg(arg)
        .output()
        .unwrap_or_else(|e| panic!("cannot run {pc} {arg}: {e}. Build PHP first: scripts/build-php.sh"));
    assert!(out.status.success(), "{pc} {arg} failed");
    String::from_utf8(out.stdout).unwrap().trim().to_string()
}

fn main() {
    println!("cargo:rerun-if-env-changed=PHP_CONFIG");
    println!("cargo:rerun-if-changed=wrapper.h");
    if let Ok(p) = env::var("IGNIS_LIBCLANG_PATH") {
        // SAFETY: build script runs single-threaded before bindgen starts.
        unsafe { env::set_var("LIBCLANG_PATH", p) };
    }

    let includes = php_config("--includes"); // "-I/opt/php85-zts/include/php -I.../main ..."
    let prefix = php_config("--prefix");
    // Backend (b) detection: the async scheduler ABI header of the true-async fork.
    println!("cargo:rustc-check-cfg=cfg(php_async_abi)");
    let has_async = std::path::Path::new(&format!("{prefix}/include/php/Zend/zend_async_API.h")).exists();
    if has_async {
        println!("cargo:rustc-cfg=php_async_abi");
    }
    println!("cargo:has_async_abi={}", if has_async { "1" } else { "0" });
    let libdir = format!("{prefix}/lib");
    let version = php_config("--version");
    println!("cargo:rustc-link-search=native={libdir}");
    println!("cargo:rustc-link-lib=dylib=php");
    println!("cargo:rustc-link-arg=-Wl,-rpath,{libdir}");
    println!("cargo:version={version}");
    println!("cargo:prefix={prefix}");

    let mut builder = bindgen::Builder::default()
        .header("wrapper.h")
        .clang_args(includes.split_whitespace())
        .clang_arg("-DZTS=1")
        .allowlist_function("(test_scheduler_set_idle_hook|_php_stream.*|php|zend|sapi|ts|tsrm|_zend|_emalloc|_efree|_safe_emalloc|_estrndup|_ecalloc|_erealloc|add_|array_|object_|zval_|_zval|_call_user|convert_to).*")
        .allowlist_type("(_?zend|_?zval|_?php|sapi|_?ts|_?zif|_?HashTable|_?Bucket).*")
        .allowlist_var("(socket_ce|file_globals_id|std_object_handlers|zend_string_init_interned|php_import_environment_variables|SAPI_OPTION_NO_CHDIR|zend_async_globals_offset|zend_async_[a-z_]*_fn|executor_globals_offset|executor_globals_id|compiler_globals_offset|core_globals_offset|sapi_globals_offset|php_embed_module|zend_ce_.*|IS_.*|ZEND_.*|E_.*|PHP_.*|MODULE_.*|IGNIS_.*|GC_.*|Z_.*|USING_ZTS|TSRM.*|_ZEND.*|_ZSTR.*)")
        .allowlist_type("max_align_t")
        .derive_default(true)
        .derive_debug(false)
        .layout_tests(true)
        .generate_comments(false)
        .prepend_enum_name(false)
        .size_t_is_usize(true)
        .parse_callbacks(Box::new(bindgen::CargoCallbacks::new()));

    // PHP headers use `bool` in C, which clang maps to `_Bool` = Rust `bool`.
    builder = builder.clang_arg("-std=gnu11");

    let bindings = builder.generate().expect("bindgen failed on php headers");
    let out = PathBuf::from(env::var("OUT_DIR").unwrap());
    bindings.write_to_file(out.join("bindings.rs")).expect("write bindings");
}
