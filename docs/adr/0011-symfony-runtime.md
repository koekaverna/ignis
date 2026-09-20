# ADR-0011 — Symfony via a custom `symfony/runtime` class; RequestStack fiber-scoped by service override

Status: accepted (Cycle 10, 2026-09-16; accepted by V-16 and its addendum — 0/100 `RequestStack` mismatches with sessions on, 7.2k req/s on 1 thread / 25.2k on 4). Decision: `Ignis\Symfony\IgnisRuntime extends SymfonyRuntime`
returns `IgnisWorkerRunner` for `HttpKernelInterface`; the runner calls `Ignis\serve()` with a handler that
builds `Request::createFromGlobals()` (per-fiber superglobals) with the body, runs `handle()`/`terminate()`,
and maps the Symfony Response to `Ignis\Http\Response`. `Ignis\Symfony\FiberRequestStack` keeps one stack
per fiber via `Ignis\Scope`; it replaces the `RequestStack` service class in `config/services.yaml`.
Entry: the skeleton's own untouched `public/index.php` (M3-2/M3-3, V-40: the old hardcoded worker
shim is retired — the `ignis/runtime` composer package installs `extra.runtime.class` instead).
Rejected: patching http-foundation; output-buffer capture of `send()` (per-thread OG).
Pain-map: PHP-FPM 7 (bootstrap per request) ADDRESSED if V-16 holds; Swoole 8 (ecosystem) for Symfony.
Kill criterion: any framework service that keeps request state outside RequestStack (e.g. session
storage) leaking across interleaved requests → needs the fiber-scoped container generalised.

## Amendment 2026-09-20 — the façades are gone; the container marks the definition (ADR-0042)

This ADR was written when the only way to give a framework singleton per-request state was to
replace it with a hand-written façade reading `Ignis\Scope`. ADR-0042 removed the need:
`FiberScopePass` no longer replaces anything, it **marks** definitions — `setFactory([Scope::class,
'create'])`, the way `lazy` is marked — and Symfony's own classes then resolve their declared
properties per fiber.

`Ignis\Symfony\FiberRequestStack` (89 lines) and `Ignis\Symfony\FiberTokenStorage` (53 lines) are
**deleted**. V-100 measured the vendor `RequestStack` with nothing but the mark against the façade
it replaced: 0 of 3 leaks either way, on the E21 fixture with overlapping requests. The token
storage needed no arm of its own — the pass had always marked `security.token_storage` by id, so
once it stopped replacing the class the vendor one was already what E21's V-68 probe was passing
through.

What this amendment does **not** change: `services_resetter` is still taken out of the request path
for the reason recorded above, and `Request::$formats` is still a thread-wide static that no
instance-level mechanism can scope (`S-REQUEST-FORMATS`).

