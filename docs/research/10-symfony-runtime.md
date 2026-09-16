# Research 10 — symfony/runtime in worker mode on Ignis

Date: 2026-09-16 (Cycle 10). Sources (installed from source, symfony/skeleton 7.x + symfony/runtime):
`vendor/autoload_runtime.php`, `vendor/symfony/runtime/SymfonyRuntime.php::getRunner`,
`Runner/Symfony/HttpKernelRunner.php`, `Runner/FrankenPhpWorkerRunner` (referenced by getRunner),
`symfony/http-foundation/RequestStack.php`, V-11 (per-fiber `$_SERVER`).

## Facts
- `public/index.php` returns a closure; `autoload_runtime.php` loads it via `$_SERVER['SCRIPT_FILENAME']`
  (returns early if that is empty — the embed SAPI does not set it), instantiates
  `$_SERVER['APP_RUNTIME'] ?? SymfonyRuntime`, resolves the closure's arguments, and `exit()`s with
  `getRunner($kernel)->run()`. So a custom runtime class + `SCRIPT_FILENAME` are the only two knobs; the
  skeleton's own files stay unchanged.
- `SymfonyRuntime::getRunner()` already branches on `FRANKENPHP_WORKER` to return a worker runner: the
  worker-mode seam is designed in. An Ignis runtime overrides `getRunner()` the same way.
- `HttpKernelRunner::run()` = `$kernel->handle($request)`, `$response->send()`, `$kernel->terminate()`.
  In Ignis the response is a value: we build `Ignis\Http\Response` from status/headers/content and never
  call `send()` (no output buffers, no `header()`).
- `Request::createFromGlobals()` reads `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE`; with V-11 those are
  per-fiber, so the standard factory works for interleaved requests.
- `RequestStack` is one shared service: `push()` in request A, then A suspends, B pushes, A resumes →
  `getCurrentRequest()` returns B's. A fiber-scoped subclass (stack in `Ignis\Scope`) fixes it; wiring
  = override the `RequestStack` service class in `config/services.yaml` (one app config line).
- Kernel boot once: the runner boots the kernel on the first request and keeps it; per-request cost is
  `handle()` + `terminate()`. `kernel.reset` services are reset by Symfony's `services_resetter` when
  `handle()` runs again (framework-bundle does this in the worker runners via `Kernel::boot()`? No: the
  FrankenPHP runner calls `$kernel->handle()` repeatedly and relies on `kernel.reset`). Same here.
- This PHP build lacks `iconv` (skeleton's composer requires ext-iconv; `--ignore-platform-reqs`);
  symfony/string has a polyfill path only for mbstring, so any code path hitting `iconv()` fails: noted.
