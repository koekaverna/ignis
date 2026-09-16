<?php
// E18-D (ADR-0037 §4(b), research 32): proves whether a blocking-in-fiber syscall that the park
// policy table does NOT cover actually blocks the PHP thread, versus a control call the table
// DOES cover, which parks and frees the thread for the scheduler. No network, no database, no
// root; bounded by two 200 ms kernel timeouts (~2 s ceiling including process start).
//
// Run with the policy narrowed to ONLY `usleep`, so `recv` — normally covered by the default SEED
// too (park.rs's SEED includes `libphp:recv`) — falls back to `block` for this run only,
// reproducing "a syscall the table does not cover" without a second library/extension:
//
//   IGNIS_PARK=libphp:usleep LD_LIBRARY_PATH=/opt/php85-zts/lib \
//     ./target/release/ignis bench/php/e18_selfcheck.php
//
// Two fibers, both started before either is awaited:
//   - "blocks": a Unix socketpair with SO_RCVTIMEO=200ms and nothing ever written to it, so
//     socket_recv() times out in the kernel after ~200 ms. `recv` is not on the narrowed policy,
//     so ignis_park_recv (crates/ignis/src/php/park.rs) never offers it to the reactor — the raw
//     blocking recv() runs and, because a plain script is one PHP thread (main.rs doc comment,
//     "runs one script on the main thread"), nothing else on the process can make progress while
//     it does.
//   - "parks": usleep(200ms), which the narrowed policy still allows — Op::Sleep, fiber suspends,
//     thread free.
//
// Expected output TODAY (no detector built — this run IS the "before" measurement research 32 is
// written against): both lines print only their own elapsed_ms (~200 each); total_wall_ms is
// ~400 (serialized: the "blocks" fiber holds the only thread for its full 200 ms before the
// scheduler ever reaches "parks", regardless of which was started first — a real kernel block does
// not yield). No warning is printed and nothing is counted anywhere; that silence is exactly the
// gap research 32 §B specifies a fix for.
//
// Expected output ONCE the detector (research 32 §B) is built, same invocation, default
// IGNIS_BLOCKED_IN_FIBER_MS (50): a WARN line naming lib=libphp func=socket_recv reason=policy_block
// ms>=150 (the 200 ms recv exceeds the 50 ms default N), and
// ignis_blocked_in_fiber_seconds{lib="libphp",func="socket_recv",reason="policy_block"} observes
// one sample ~0.2s. The "parks" fiber's usleep produces neither: it took the parked branch, not
// the timed one — see research 32 §B's "where timing is taken".
declare(strict_types=1);
require __DIR__ . '/../../php/ignis.php';

$ms = 200;
$fs = [];

$fs[] = Ignis\async(static function () use ($ms): void {
    $t0 = hrtime(true);
    socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
    [$a, $b] = $pair;
    socket_set_option($a, SOL_SOCKET, SO_RCVTIMEO, ['sec' => intdiv($ms, 1000), 'usec' => ($ms % 1000) * 1000]);
    $r = socket_recv($a, $buf, 16, 0);
    $elapsed = intdiv((int) hrtime(true) - $t0, 1_000_000);
    printf("e18_selfcheck: case=blocks(recv, off policy) result=%s elapsed_ms=%d\n", var_export($r, true), $elapsed);
});

$fs[] = Ignis\async(static function () use ($ms): void {
    $t0 = hrtime(true);
    usleep($ms * 1000);
    $elapsed = intdiv((int) hrtime(true) - $t0, 1_000_000);
    printf("e18_selfcheck: case=parks(usleep, on policy) elapsed_ms=%d\n", $elapsed);
});

$t0 = hrtime(true);
Ignis\all($fs);
$total = intdiv((int) hrtime(true) - $t0, 1_000_000);
printf(
    "e18_selfcheck: total_wall_ms=%d (serialized ~= %d if 'blocks' really blocked the thread; ~= %d if both ran concurrently)\n",
    $total,
    2 * $ms,
    $ms
);
