//! Embeds an rpath to the PHP prefix exported by ignis-sys (`links = "php"`)
//! so the binary and its tests find libphp.so without LD_LIBRARY_PATH.
fn main() {
    let prefix = std::env::var("DEP_PHP_PREFIX").expect("ignis-sys must export php_prefix");
    println!("cargo:rustc-link-arg=-Wl,-rpath,{prefix}/lib");
    println!("cargo:rerun-if-env-changed=DEP_PHP_PREFIX");
    println!("cargo:rustc-check-cfg=cfg(php_async_abi)");
    if std::env::var("DEP_PHP_HAS_ASYNC_ABI").as_deref() == Ok("1") {
        println!("cargo:rustc-cfg=php_async_abi");
    }
    // E18 (ADR-0020): the interposed libc symbols live in csrc/park.c, one object file so the
    // linker pulls every symbol with the first one std references; each is forced in (-u) and
    // exported from the executable (--export-dynamic-symbol) so libraries loaded later bind to it.
    if std::env::var_os("CARGO_FEATURE_UNIVERSAL_PARK").is_some() {
        println!("cargo:rerun-if-changed=csrc/park.c");
        cc::Build::new().file("csrc/park.c").opt_level(2).flag("-fno-builtin").compile("ignispark");
        // Stage 1, then stage 2 (ADR-0037 cycle 2) from "accept" on.
        // NEVER add `fcntl` here: research 30 group (d) found the one lock-held blocking call
        // in libphp is `fcntl(F_SETLKW)` inside opcache's `zend_shared_alloc_lock()`, taken
        // with the TSRM mutex `zts_lock` held, on every cache-miss compile. Parking there
        // deadlocks the thread. It is safe today only because the symbol is not interposed.
        for s in [
            "read",
            "write",
            "recv",
            "send",
            "recvfrom",
            "sendto",
            "poll",
            "connect",
            "nanosleep",
            "usleep",
            "sleep",
            "accept",
            "flock",
            "accept4",
            "select",
            "ppoll",
            "__poll_chk",
            "recvmsg",
            "sendmsg",
            "readv",
            "writev",
        ] {
            println!("cargo:rustc-link-arg=-Wl,-u,{s}");
            println!("cargo:rustc-link-arg=-Wl,--export-dynamic-symbol={s}");
        }
    }

    // ADR-0040: temporalio-protos declares `links`, so its build script's `cargo:descriptor_path`
    // reaches us as DEP_TEMPORALIO_PROTOS_DESCRIPTOR_PATH. Handing the path on to the crate lets
    // the Temporal boundary decode protojson against the real descriptor pool instead of prost's
    // serde derive, which is not protojson.
    if let Ok(path) = std::env::var("DEP_TEMPORALIO_PROTOS_DESCRIPTOR_PATH") {
        println!("cargo:rustc-env=IGNIS_TEMPORAL_DESCRIPTORS={path}");
        println!("cargo:rerun-if-changed={path}");
    }
}
