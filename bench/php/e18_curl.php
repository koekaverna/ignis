<?php

// E18-B / H32 control: N fibers, each curl_exec against a /sleep?ms=<ms> URL. CURLOPT_WRITEFUNCTION
// records spl_object_id(Fiber::getCurrent()) so we can tell which fiber each callback actually ran
// in. Today (no universal park, ADR-0020) that is a plain blocking curl_exec inside libphp: with
// the default 1 PHP thread every fiber's call serializes, so wall time is N * ms, not ms — that IS
// the control baseline this bench exists to record (H32's falsifier: after E18-I, wall ~= ms).
//
// MUST run with IGNIS_NO_OFFLOAD_ROUTE=1 (the driver, bench/e18.sh, sets it): without it ADR-0016's
// auto-routing (crates/ignis/src/php/route.rs) swaps curl_* to run on an offload worker thread
// instead, which would measure the offload pool, not blocking libcurl.
//
// Usage: ignis bench/php/e18_curl.php <url> [n]   (env fallback: URL, N; default n=100)
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$url = $argv[1] ?? getenv('URL') ?: null;
$n = (int) ($argv[2] ?? getenv('N') ?: 100);
if (!$url) {
    fwrite(STDERR, "usage: e18_curl.php <url> [n]\n");
    exit(2);
}

$ok = 0;
$writefnFibers = [];
$sameFiber = true;

$main = Ignis\async(static function () use ($url, $n, &$ok, &$writefnFibers, &$sameFiber): void {
    $t0 = hrtime(true);
    $fs = [];
    for ($i = 0; $i < $n; $i++) {
        $fs[] = Ignis\async(static function () use ($url, &$ok, &$writefnFibers, &$sameFiber): void {
            $callerFiber = spl_object_id(Fiber::getCurrent());
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($h, string $data) use ($callerFiber, &$writefnFibers, &$sameFiber): int {
                $cbFiber = spl_object_id(Fiber::getCurrent());
                $writefnFibers[$cbFiber] = true;
                if ($cbFiber !== $callerFiber) {
                    $sameFiber = false;
                }
                return strlen($data);
            });
            $result = curl_exec($ch);
            if ($result !== false) {
                $ok++;
            }
        });
    }
    Ignis\all($fs);
    $wall = (hrtime(true) - $t0) / 1e6;
    printf(
        "e18: curl_100x200ms wall_ms=%.0f ok=%d writefn_fibers=%d same_fiber=%s\n",
        $wall,
        $ok,
        count($writefnFibers),
        $sameFiber ? 'yes' : 'no',
    );
});
Ignis\Loop::run();
