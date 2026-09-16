# ADR-0012 — Thread death is local; a supervisor respawns worker threads; a watchdog reports stalls

Status: accepted (Cycle 11, 2026-09-16; accepted by V-17 — a fatal killed 1 of 4 workers with hello uninterrupted at 134k req/s, respawn inside the 50 ms tick without an opcache reset, watchdog reported the stalled thread, recovery 95.7%). Decision: the main thread supervises workers 1..N: a worker
whose script ends is deregistered from dispatch (V-15) and respawned (new OS thread, `ts_resource(0)`,
same script), limited to 10 restarts per minute per slot; opcache SHM is never reset. Thread 0 (the main
thread) is not respawnable in this design (php_embed_init owns it) — a fatal there ends the process, so
production runs the script only on workers (`--threads N` with `--supervise` keeps thread 0 idle).
A watchdog task logs threads that have not called `ignis_poll` for > 1 s and exposes the count through
`ignis_stats()`. Pain-map: Swoole 6/7, FrankenPHP 6 → ADDRESSED if V-17 holds. Kill criterion: a
respawned thread that cannot serve (TSRM or opcache state left behind) or a measurable throughput loss
after respawn.
