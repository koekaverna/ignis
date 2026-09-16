# Ignis — working agreement
- The full brief is in BRIEF.md. Re-read it, STATUS.md and the tail of JOURNAL.md after any context reset before doing anything else.
- Commit at every stage transition of the loop, not only per hypothesis; `wip(cycle-N/stage): …` mid-stage is fine. Push after every commit.
- Never leave more than 30 minutes of work uncommitted.
- Work on branch `night-1`. Do not rewrite history.
- Never ask for permission or confirmation. Decide, log in DECISIONS.md, continue.
- Numbers or it didn't happen. Every claim in STATUS.md links to a VALIDATION.md entry.

## Disk (owner rule, 2026-09-16)

- Before any build run `df -h /`; if less than 15% is free, stop and clean first.
- Comparison build trees (php-fpm, FrankenPHP, RoadRunner, ext-grpc) live under `/tmp/cmp/` and are deleted the moment their numbers are in VALIDATION.md; keep only the binaries `bench/compare.sh` and `bench/e10-compare.sh` need (`/opt/frankenphp-bin`, `/opt/php85-fpm/sbin/php-fpm`, `/tmp/cmp/rr` + `/tmp/cmp/rr-app`).
