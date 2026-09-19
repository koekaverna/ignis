<?php

// A-LEAKS-RUST (b): a worker that dies mid-job used to leave its JOBS entry behind and the
// caller's reserved op raised for ever, so the calling fiber waited on something nothing would
// ever complete. Run: IGNIS_OFFLOAD_PRELUDE=bench/php/offload_worker_dies_prelude.php \
//   ./target/release/ignis --offload 1 bench/php/offload_worker_dies.php
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/offload/src/ignis-offload.php';

Ignis\async(static function (): void {
    $before = ignis_inflight();
    $failure = 'none';

    try {
        Ignis\offload('offload_job_that_kills_its_worker', 0);
    } catch (\Throwable $e) {
        $failure = $e->getMessage();
    }

    $after = ignis_inflight();
    printf(
        "offload_worker_dies: inflight_before=%d inflight_after=%d failed=%s\n",
        $before,
        $after,
        var_export($failure !== 'none', true),
    );
    printf("offload_worker_dies: reason=%s\n", $failure);
});

Ignis\Loop::run();
