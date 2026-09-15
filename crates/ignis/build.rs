//! Embeds an rpath to the PHP prefix exported by ignis-sys (`links = "php"`)
//! so the binary and its tests find libphp.so without LD_LIBRARY_PATH.
fn main() {
    let prefix = std::env::var("DEP_PHP_PREFIX").expect("ignis-sys must export php_prefix");
    println!("cargo:rustc-link-arg=-Wl,-rpath,{prefix}/lib");
    println!("cargo:rerun-if-env-changed=DEP_PHP_PREFIX");
}
