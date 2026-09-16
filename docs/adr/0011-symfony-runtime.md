# ADR-0011 — Symfony via a custom `symfony/runtime` class; RequestStack fiber-scoped by service override

Status: accepted (Cycle 10, 2026-09-16). Decision: `Ignis\Symfony\IgnisRuntime extends SymfonyRuntime`
returns `IgnisWorkerRunner` for `HttpKernelInterface`; the runner calls `Ignis\serve()` with a handler that
builds `Request::createFromGlobals()` (per-fiber superglobals) with the body, runs `handle()`/`terminate()`,
and maps the Symfony Response to `Ignis\Http\Response`. `Ignis\Symfony\FiberRequestStack` keeps one stack
per fiber via `Ignis\Scope`; it replaces the `RequestStack` service class in `config/services.yaml`.
Entry: `php/symfony/worker.php` sets `SCRIPT_FILENAME` and `APP_RUNTIME` and requires the untouched
`public/index.php`. Rejected: patching http-foundation; output-buffer capture of `send()` (per-thread OG).
Pain-map: PHP-FPM 7 (bootstrap per request) ADDRESSED if V-16 holds; Swoole 8 (ecosystem) for Symfony.
Kill criterion: any framework service that keeps request state outside RequestStack (e.g. session
storage) leaking across interleaved requests → needs the fiber-scoped container generalised.
