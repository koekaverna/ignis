# GOALS

Ranked. Targets are floors; when met they get raised here with a reason.

## Expectations (from the brief, plus E9/E10 added by the owner mid-night)

| id | target | status |
|---|---|---|
| E1 | 10,000 concurrent requests each doing `Ignis\sleep(1000)` on ONE thread finish in < 1.2 s wall | OPEN (H2) |
| E2 | `Ignis\all()` of three 200 ms calls returns in < 230 ms; per-fiber overhead < 100 µs | OPEN (H3) |
| E3 | RSS flat (±2%) over 1,000,000 requests in worker mode | OPEN |
| E4 | Hello-world throughput ≥ FrankenPHP worker mode on the same box, p99 lower | OPEN |
| E5 | 8 threads ≥ 6.5× single-thread throughput on CPU-bound work (this box has 4 vCPU: target is scaled to 4 threads ≥ 3.25×, see note) | OPEN |
| E6 | php_stream hook: unmodified `file_get_contents('http://…')` and PDO suspend the fiber | OPEN |
| E7 | Revolt-compatible driver over the Ignis reactor runs AMPHP examples unchanged | OPEN |
| E8 | symfony/runtime adapter boots symfony/skeleton in worker mode; RequestStack fiber-scoped | OPEN |
| E9 | Temporal: link temporal sdk-core (Rust) in-process; workflow on Fibers with deterministic replay; one workflow with two activities and a timer passes a replay test. Research first: how the Python SDK bridges core ↔ asyncio | OPEN |
| E10 | gRPC: tonic server + client on the shared hyper/h2 stack; unary and server-streaming handlers in PHP; client call suspends the fiber. Compare against RoadRunner grpc plugin and ext-grpc on latency and build complexity | OPEN |

Note on E5: the machine has 4 vCPUs (`nproc`), so "8 threads ≥ 6.5×" cannot
be measured as written. The proportional target (≥ 81% scaling efficiency)
is used: 4 threads ≥ 3.25× single-thread.

## Ranking (Cycle 0)

1. E1, E2 — the thesis. Nothing else matters until these are CONFIRMED or REFUTED.
2. E4 — hello-world over hyper; needed anyway as the transport for everything else.
3. E5 — multi-thread ZTS workers.
4. E3 — worker mode leak test.
5. E6 — stream hooks (Swoole route).
6. E7 — Revolt driver (cheap once the reactor exists, see ADR-0001).
7. E10 — gRPC on the same hyper/h2 stack (transport is shared with E4).
8. E8 — Symfony adapter.
9. E9 — Temporal sdk-core (largest unknown; research-first).

## Retired / cut

(nothing yet)
