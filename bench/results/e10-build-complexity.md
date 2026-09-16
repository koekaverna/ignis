# E10 build complexity — measured baselines (H21c / V-20)

Measured by the `bencher` agent on this box on 2026-09-16 (UTC). All three columns are
now numbers; nothing below is estimated.

**Box:** 4 vCPU, Linux 6.18.44-fc-v33, GCC 13.3.0, cargo/rustc 1.94.1, go 1.27.0,
PHP 8.5.10 ZTS in `/opt/php85-zts`.

**Contention warning — read this before quoting any number.** The box was *not* idle for
any measurement. The main agent was compiling and running `ignis` soaks throughout.
`uptime` at the start of this session: `03:59 up, load average: 2.19, 2.67, 2.48`
(02:11:32Z). `uptime` at the end: see the bottom of this file. The load average at the
moment of each measurement is given in every row; the grpc C-core build in particular ran
at `nice -n 19 -j2` through a window where the 1-minute load average reached **29.36**.
Every build time here is therefore an *upper* bound for this hardware, not a clean number.
Load generators, servers and builds share the same 4 vCPUs.

## The table

| tool | toolchain | build time here | artifact size | notes / blocked |
|---|---|---|---|---|
| **Ignis (tonic in-process)** | the Rust toolchain already required; nothing else | `ignis` release binary: not re-measured (never run `cargo` on the ignis workspace from this agent). Standalone `examples/rust/grpc-baseline` crate, its own `[workspace]`: **cold 56.9 s** after `cargo clean` (load 5.85 → 8.09); **warm no-op 0.10 s / 0.13 s**; **incremental after `touch src/main.rs` 25.4 s / 22.1 s** (load 7.9 → 10.0) | `target/release/ignis` **29 094 632 B (27.7 MiB)** as of 03:28Z (it was 24 111 760 B / 23.0 MiB at 01:53Z — the main agent rebuilt it mid-session, so V-20's "24.1 MB" is stale). `grpc-baseline` binary **2 594 176 B (2.47 MiB)** | 331 crates in `/home/user/ignis/Cargo.lock`; 52 crates in the baseline crate's own lock. No C toolchain, no submodules, no second package manager. |
| **RoadRunner grpc plugin** | Go 1.27.0 (module demands ≥ 1.26.4; `go install …@latest` is refused by exclude directives, so clone + `go build ./cmd/rr`) | **cold, from clone: 91 s** (`rr-clone.log`, `exit=0 secs=91` — includes the module downloads). **Warm module + build cache, re-link only: 6.38 s** (load 7.08); **fully cached no-op: 0.79 s** (load 6.68). A *cold build cache* re-measure (fresh `GOCACHE`, warm module cache) **could not be completed — it ran the root filesystem out of disk after 67 s** (see below). | `/home/user/cmp/rr` **95 991 151 B (91.5 MiB)**; `/tmp/rr-rebuild` byte-identical at 95 991 151 B | 480 modules in the build (`go list -m all`), 304 distinct modules in `go.sum`, 214 direct `require` lines. Module cache `/root/go/pkg/mod` **4.4 GB**, build cache `/root/.cache/go-build` **4.2 GB** — the 6 s figure is bought with 8.6 GB of cache. Still needs php-cli + `spiral/roadrunner-grpc` + `google/protobuf` + generated PHP classes on top. |
| **ext-grpc** | C++ toolchain + cmake for the grpc C-core (BoringSSL, abseil, protobuf, re2, c-ares, upb, zlib — 18 git submodules), then `phpize`. `pecl.php.net` is blocked here (403), so the extension had to be built from the tree. | **C-core: 4648 s = 77 min 28 s** wall (`nice -n 19`, `cmake --build -j2 --target install`, `exit=0 secs=4648`), 01:58Z → 03:14Z, load average 2.19 → 29.36 → 5.68 across the window. **PHP extension on top: 11.9 s** (`phpize` + `configure` + `make -j2` + hand link; two runs, ~11 s and **11.88 s**, load 7.5). **Total 4660 s ≈ 78 min.** | `grpc-install` **277 MB** (129 static `.a` files, 205 MB of `lib/`, 16 MB / 1120 installed headers). `libgrpc.a` **52 079 074 B (49.7 MiB)**. `grpc.so` statically linked against that C-core: **49 944 384 B (47.6 MiB)** unstripped, **20 407 352 B (19.5 MiB)** stripped. A `grpc.so` linked only against `-lgrpc` is 20 561 232 B but does not load. Source tree with submodules: 1.5 GB. | **It does build and load on PHP 8.5.10 ZTS** — `php -d extension=…/grpc.so -m` prints `grpc`, `php --ri grpc` reports `grpc module version => 1.85.0dev`. But the stock build path does **not** work; see "Three things that had to be worked around" below. |

3066 compiled objects in the C-core build: 2281 `Building CXX object`, 662 `Building C object`,
123 `Building ASM object`, plus 166 link steps. For comparison, the whole Ignis baseline crate
is 52 crates and one 2.5 MiB binary.

## Three things that had to be worked around to get ext-grpc built

These are the honest friction, and they are the reason "build time" understates the cost.

1. **`pecl install grpc` is unavailable here (403 from pecl.php.net)**, so the extension came
   from `/home/user/cmp/grpc/src/php/ext/grpc` in the C-core source tree.
2. **`./configure` fails against a static C-core install.** `config.m4` probes with
   `PHP_CHECK_LIBRARY(grpc, grpc_channel_destroy, …)`, which links a single `-lgrpc`.
   A cmake install of grpc is static by default, so that probe pulls `libgrpc.a` with no
   abseil / BoringSSL / upb behind it and dies:
   `configure: error: wrong grpc lib version or lib not found`
   (the real error in `config.log` is hundreds of `undefined reference to
   'grpc_core::Fork::support_enabled_'`, `operator delete(void*, unsigned long)`,
   `absl::…::Mutex::lock()`). It only configures if you hand it the whole dependency set:
   `LIBS="-Wl,--start-group /home/user/cmp/grpc-install/lib/*.a -Wl,--end-group -lstdc++ -lm"`.
   Also `pkg-config --libs --static grpc` does not work at all — `grpc.pc` `Requires:` re2,
   and the cmake install ships no `re2.pc`.
3. **`make` produces a `grpc.so` that will not load.** The Makefile's link line is
   `$(GRPC_SHARED_LIBADD)` = `-lgrpc -lrt -ldl -lpthread -lstdc++`, so the .so comes out with
   ~1000 undefined symbols and PHP refuses it:
   `undefined symbol: _ZN4absl12lts_2026052618container_internal19GetRefForEmptyClassERNS1_12CommonFieldsE`.
   Overriding `make GRPC_SHARED_LIBADD=…` does not fix it either: **libtool strips
   `-Wl,--start-group`/`--end-group` and de-duplicates repeated archives**, so static link
   order can't be repaired through libtool (verified: 3 passes of `libcrypto.a` in the make
   variable, 1 occurrence in the emitted `cc -shared` line; result was a single remaining
   `undefined symbol: EVP_hpke_aes_128_gcm`, referenced by `libssl.a`, defined in `libcrypto.a`).
   The working artifact was produced by bypassing libtool:
   `cc -shared .libs/*.o -Wl,--start-group <all .a> -Wl,--end-group -lstdc++ -lm -lrt -ldl -lpthread -o modules/grpc.so`.

The compile step itself was clean: all 10 C files (`byte_buffer.c call.c call_credentials.c
channel.c channel_credentials.c completion_queue.c timeval.c server.c server_credentials.c
php_grpc.c`) compiled against the PHP 8.5 headers (`Zend Module Api No: 20250925`) with
`-Wall -Werror -std=c11` and **zero warnings**. ext-grpc's C source is not the problem on
PHP 8.5 ZTS; its C-core is.

## Correction candidate for V-20

V-20 currently says ext-grpc is "**client only**: ext-grpc has no server, so it cannot serve
E10's handlers at all". That is too strong as written. The built extension exports:

```
Grpc\Call, Grpc\Channel, Grpc\Server, Grpc\Timeval,
Grpc\ChannelCredentials, Grpc\CallCredentials, Grpc\ServerCredentials
```

and `Grpc\Server` has `__construct, requestCall, addHttp2Port, addSecureHttp2Port, start`.
So the server *primitives* are compiled in (`server.c` is in `PHP_NEW_EXTENSION`). What is
missing is everything above them: `requestCall` is a blocking single-threaded completion-queue
pull, there is no dispatcher, no `protoc` server-side codegen for PHP, and upstream does not
support it (these entry points exist for the extension's own tests). The accurate claim is
"no supported server: the primitives exist but there is no server runtime or codegen", not
"no server". Recommend the main agent re-word rather than drop the point — the conclusion
(ext-grpc cannot serve E10's handlers) still holds.

## Disk pressure (a real finding)

The root filesystem is at **99% (600–711 MB free of 252 G, 37 G used)** for the whole session.
The attempt to re-measure RoadRunner with a cold build cache
(`GOCACHE=<fresh> go build ./cmd/rr`) filled the disk and failed after 67 s with
`no space left on device` across ~40 packages; the partial cache was already 306 MB. That
experiment was cleaned up. `cargo clean` + cold build of the baseline crate was only possible
because the clean freed 125 MB first. Anything else that needs >500 MB of scratch will fail
here.

---

# Raw command output

## C-core build (started 01:58Z by the main agent, `nice -n 19 cmake --build cmake/build -j2 --target install`)

```
$ tail -3 /home/user/cmp/grpc-core-build.log
-- Installing: /home/user/cmp/grpc-install/lib/pkgconfig/grpc++.pc
-- Installing: /home/user/cmp/grpc-install/lib/pkgconfig/grpc++_unsecure.pc
exit=0 secs=4648

$ grep -c 'Building' /home/user/cmp/grpc-core-build.log
3066
$ grep -c 'Building CXX object' … ; grep -c 'Building C object' … ; grep -c 'Building ASM' … ; grep -c 'Linking ' …
2281
662
123
166

$ du -sh /home/user/cmp/grpc-install
277M	/home/user/cmp/grpc-install
$ du -sh /home/user/cmp/grpc-install/lib
205M	/home/user/cmp/grpc-install/lib
$ ls -la /home/user/cmp/grpc-install/lib/libgrpc.a
-rw-r--r-- 1 root root 52079074 Sep 16 03:03 /home/user/cmp/grpc-install/lib/libgrpc.a
$ ls /home/user/cmp/grpc-install/lib/*.a | wc -l
129
$ find /home/user/cmp/grpc-install/include -type f | wc -l
1120
$ du -sh /home/user/cmp/grpc/            # source tree incl. 18 submodules
1.5G	/home/user/cmp/grpc

$ uptime   # at completion, 03:14:52Z
 03:14:52 up  5:02,  0 user,  load average: 5.68, 5.82, 8.20
```

Load average sampled during the build (each line is a 9-minute poll of the log):

```
02:11:32Z  24%  load average: 2.19, 2.67, 2.48
02:21:18Z  62%  load average: 7.92, 5.75, 3.83
02:30:23Z  69%  load average: 24.15, 16.88, 9.68
02:39:32Z  72%  load average: 29.36, 25.90, 17.34
02:48:38Z  73%  load average: 23.11, 24.29, 20.54
02:57:43Z  83%  load average: 4.09, 7.56, 13.54
03:06:48Z  91%  load average: 4.59, 5.61, 9.83
03:14:52Z 100%  load average: 5.68, 5.82, 8.20   exit=0 secs=4648
```

## PHP extension — first (failing) configure, stock recipe

```
$ cd /home/user/cmp/grpc/src/php/ext/grpc && /opt/php85-zts/bin/phpize
Configuring for:
PHP Version:             8.5
PHP Api Version:         20250925
Zend Module Api No:      20250925
Zend Extension Api No:   420250925

$ PKG_CONFIG_PATH=/home/user/cmp/grpc-install/lib/pkgconfig \
  ./configure --with-php-config=/opt/php85-zts/bin/php-config \
              --enable-grpc=/home/user/cmp/grpc-install
…
checking if PHP is built with thread safety (ZTS)... yes
checking whether to enable grpc support... yes, shared
checking for grpc_channel_destroy in -lgrpc... no
configure: error: wrong grpc lib version or lib not found
real	0m1.097s
```

`config.log`, the actual reason:

```
configure:4950: cc -o conftest … conftest.c -lgrpc -lrt -ldl -lpthread -lpthread
/usr/bin/ld: libgrpc.a(channel.cc.o): undefined reference to `operator delete(void*, unsigned long)'
/usr/bin/ld: channel.cc:(.text+0x85b): undefined reference to `grpc_core::Fork::support_enabled_'
/usr/bin/ld: channel.cc:(.text+0x13b5): undefined reference to `absl::lts_20260526::Mutex::lock()'
… (hundreds more)
```

```
$ PKG_CONFIG_PATH=… pkg-config --libs --static grpc
Package re2 was not found in the pkg-config search path.
Package 're2', required by 'grpc', not found
```

## PHP extension — configure that works

```
$ ALLA=$(ls /home/user/cmp/grpc-install/lib/*.a | tr '\n' ' ')
$ export LIBS="-Wl,--start-group $ALLA -Wl,--end-group -lstdc++ -lm"
$ ./configure --with-php-config=/opt/php85-zts/bin/php-config --enable-grpc=/home/user/cmp/grpc-install
…
configure: creating Makefile
config.status: creating config.h
real	0m2.627s     exit=0
```

## PHP extension — `make` output is unloadable

```
$ make -j2                       # 10 files, -Wall -Werror -std=c11, zero warnings
real	0m3.715s     exit=0
$ ls -la modules/grpc.so
-rwxr-xr-x 1 root root 20561232 Sep 16 03:16 modules/grpc.so
$ /opt/php85-zts/bin/php -d extension=…/modules/grpc.so -m | grep -i grpc
Warning: PHP Startup: Unable to load dynamic library '…/grpc.so'
 (…: undefined symbol: _ZN4absl12lts_2026052618container_internal19GetRefForEmptyClassERNS1_12CommonFieldsE)
$ nm -D -u modules/grpc.so | wc -l
1000
```

Overriding the make variable does not help (libtool de-duplicates and drops `--start-group`):

```
$ make -j2 GRPC_SHARED_LIBADD="<all .a, 3 ordered passes> -lstdc++ -lm -lrt -ldl -lpthread"
real	0m6.640s     exit=0
-rwxr-xr-x 1 root root 48797456 Sep 16 03:18 modules/grpc.so
… undefined symbol: EVP_hpke_aes_128_gcm
$ grep -- '-o .libs/grpc.so' ext-make4.log | tr ' ' '\n' | grep -c 'libcrypto.a'
1                      # three passes went in, one came out
$ nm -A --defined-only libcrypto.a | grep EVP_hpke_aes_128_gcm   -> defined in libcrypto.a
$ nm -A -u          libssl.a    | grep EVP_hpke_aes_128_gcm      -> referenced by libssl.a
```

## PHP extension — the link that works, and verification

```
$ cc -shared .libs/*.o -Wl,--start-group <all .a minus grpc++/grpcpp/protoc/plugin_support/
      grpc_unsecure/grpc_authorization_provider/protobuf-lite> -Wl,--end-group \
      -lstdc++ -lm -lrt -ldl -lpthread -Wl,-soname,grpc.so -o modules/grpc.so
real	0m1.331s     exit=0

$ ls -la modules/grpc.so
-rwxr-xr-x 1 root root 49944384 Sep 16 03:19 modules/grpc.so
$ strip → 20407352 B

$ /opt/php85-zts/bin/php -d extension=/home/user/cmp/grpc/src/php/ext/grpc/modules/grpc.so -m | grep -i grpc
grpc

$ /opt/php85-zts/bin/php -d extension=…/grpc.so --ri grpc
grpc support => enabled
grpc module version => 1.85.0dev
grpc.enable_fork_support => 0 => 0
grpc.poll_strategy => no value => no value
grpc.grpc_verbosity => no value => no value
grpc.grpc_trace => no value => no value

$ php -r '$e=new ReflectionExtension("grpc"); echo implode(", ", array_keys($e->getClasses()));'
Grpc\Call, Grpc\Channel, Grpc\Server, Grpc\Timeval, Grpc\ChannelCredentials,
Grpc\CallCredentials, Grpc\ServerCredentials

$ php -r '$r=new ReflectionClass("Grpc\\Server"); …'
__construct, requestCall, addHttp2Port, addSecureHttp2Port, start
```

Full clean end-to-end extension build (`phpize --clean` → `phpize` → `configure` → `make -j2`
→ hand link), two runs:

```
run 1: 03:19:01Z → 03:19:12Z  (~11 s)   load average: 7.88, 6.22, 7.74
run 2: exit=0 secs=11.880464658          load average: 7.49, 6.24, 7.71
both produce modules/grpc.so = 49944384 B and `php -m` prints grpc
```

Extension source revision: `/home/user/cmp/grpc` at `a4aa9bc`, `PHP_GRPC_VERSION "1.85.0dev"`.

## RoadRunner

```
$ go version
go version go1.27.0 linux/amd64
$ git -C /home/user/cmp/roadrunner log -1 --format='%h'
0cae87d  (grafted, master)

$ ls -la /home/user/cmp/rr
-rwxr-xr-x 1 root root 95991151 Sep 16 01:48 /home/user/cmp/rr

$ tail -1 /home/user/cmp/rr-clone.log        # original cold clone+build
exit=0 secs=91

# re-measure, warm module cache + warm build cache
$ uptime →  03:19:46 up  5:07, load average: 7.08, 6.25, 7.68
$ time go build -o /tmp/rr-rebuild ./cmd/rr
real	0m6.382s
user	0m4.925s
sys	0m1.115s
$ ls -la /tmp/rr-rebuild
-rwxr-xr-x 1 root root 95991151 Sep 16 03:19 /tmp/rr-rebuild

# second run, everything cached
$ uptime →  03:20:02 up  5:07, load average: 6.68, 6.21, 7.64
$ time go build -o /tmp/rr-rebuild ./cmd/rr
real	0m0.786s
user	0m0.840s
sys	0m0.247s

# third attempt: cold build cache, warm module cache — FAILED, out of disk
$ rm -rf $SC/gocache && mkdir $SC/gocache
$ time GOCACHE=$SC/gocache go build -o /tmp/rr-rebuild-cold ./cmd/rr
# google.golang.org/api/internal/third_party/uritemplates
compile: writing output: write $WORK/b637/_pkg_.a: no space left on device
… ~40 more packages …
real	1m7.192s
exit=1          (306 MB of partial cache produced before ENOSPC)

$ go list -m all | wc -l
480
$ awk '{print $1}' go.sum | sort -u | wc -l
304
$ du -sh /root/go/pkg/mod /root/.cache/go-build
4.4G	/root/go/pkg/mod
4.2G	/root/.cache/go-build
```

## Ignis / tonic

No `cargo` was run on the ignis workspace (bencher rule).

```
$ ls -la /home/user/ignis/target/release/ignis      # 02:11Z, start of session
-rwxr-xr-x 2 root root 24111760 Sep 16 01:53 /home/user/ignis/target/release/ignis
$ ls -la /home/user/ignis/target/release/ignis      # 03:28Z, after the main agent rebuilt
-rwxr-xr-x 2 root root 29094632 Sep 16 03:28 /home/user/ignis/target/release/ignis

$ grep -c '^name = ' /home/user/ignis/Cargo.lock
331
$ du -sh /home/user/ignis/target
6.1G
```

Standalone baseline crate `/home/user/ignis/examples/rust/grpc-baseline` (own `[workspace]`,
`cargo 1.94.1 / rustc 1.94.1`, `lto = "thin"`):

```
$ cargo clean
     Removed 396 files, 125.0MiB total
$ uptime →  03:26:56 up  5:14, load average: 5.85, 6.02, 7.14
$ time cargo build --release
    Finished `release` profile [optimized] target(s) in 56.85s
real	0m56.885s
user	0m58.057s
sys	0m4.126s
$ uptime →  03:27:53 up  5:15, load average: 8.09, 6.68, 7.30
$ ls -la target/release/grpc-baseline
-rwxr-xr-x 2 root root 2594176 Sep 16 03:27 target/release/grpc-baseline

# warm, no-op
$ time cargo build --release   →  Finished in 0.07s   real 0m0.098s
$ time cargo build --release   →  Finished in 0.09s   real 0m0.134s

# warm, incremental after `touch src/main.rs`
$ time cargo build --release   →  Finished in 25.37s  real 0m25.398s
$ time cargo build --release   →  Finished in 22.12s  real 0m22.144s
$ uptime →  03:28:49 up  5:16, load average: 9.99, 7.42, 7.52

$ grep -c '^name = ' Cargo.lock
52
$ du -sh target
122M
```

Note: V-20 records "the baseline crate builds from scratch in 23.5 s". That was measured on a
quieter box; the same `cargo clean && cargo build --release` took **56.9 s** here at load ~6–8.
The 22–25 s figure reproduces as the *incremental* rebuild (deps cached, crate recompiled),
which is what most edit cycles actually cost.

## uptime

```
start  02:11:32Z   up  3:59,  0 user,  load average: 2.19, 2.67, 2.48
end    03:31:04Z   up  5:18,  0 user,  load average: 3.72, 5.84, 6.91
```
