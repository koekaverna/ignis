# DECISIONS

Small decisions taken without asking (the big ones are ADRs in docs/adr/). Newest at the bottom.

- 2026-09-15T22:50Z — **Branches.** The session harness designates `claude/ignis-php-fibers-tokio-644209` as the development branch; the owner's addendum (CLAUDE.md) says `night-1`. Both are honoured: every commit is pushed to both branches (`scripts/push.sh`), history is never rewritten on either. The addendum arrived mid-Cycle 1 (Cycle 0 was already complete), so it is applied from this point; the Cycle 0 name-collision check is done now and recorded in STATUS.md.
- 2026-09-15T22:50Z — **Bench tooling.** `ab` is dropped for concurrency tests: its "time taken" includes its own serial connection setup (V-5). `wrk --latency` is the load generator for everything.
- 2026-09-15T22:50Z — **Per-hypothesis commits.** The http transport and the fiber pool landed in one commit because they share `php/ignis.php`; both hypotheses (H5, H6) are named in the message. Future work keeps one hypothesis per commit where the files allow it.
