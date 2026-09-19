<?php

// The job A-LEAKS-RUST (b) is about: one that kills its worker before `done()` can be sent.
// `exit()` is not catchable, so `WorkerRuntime::run`'s own try/catch never sees it and the whole
// worker loop unwinds with the job still marked running — the exact shape a PHP fatal produces.
declare(strict_types=1);

function offload_job_that_kills_its_worker(int $unused): array
{
    exit(7);
}

function offload_job_that_returns(int $value): array
{
    return ['value' => $value];
}
