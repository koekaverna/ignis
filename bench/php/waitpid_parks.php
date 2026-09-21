<?php

// V-112: proc_close() must not hold the OS thread for the child's whole life.
//
// exec()/shell_exec() already parked: they read the child's stdout through a pipe, which is a FIFO
// and therefore parkable, and by the time libphp reaps the child it has already died. proc_open()
// with no descriptors has no pipe, so libphp went straight to waitpid and blocked -- three
// concurrent 300 ms children took 915 ms. waitpid is interposed now and waits on a pidfd.
//
// Prints one parsable line per shape plus the correctness checks; the gate reads both.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

$concurrent = static function (string $label, callable $one): void {
    $started = hrtime(true);
    Ignis\all([Ignis\async($one), Ignis\async($one), Ignis\async($one)]);
    printf("waitpid %s=%.0f\n", $label, (hrtime(true) - $started) / 1e6);
};

$concurrent('exec_ms', static fn() => exec('sleep 0.3'));
$concurrent('proc_close_ms', static function (): void {
    $process = proc_open('sleep 0.3', [], $pipes);
    proc_close($process);
});
$concurrent('reference_ms', static fn() => Ignis\sleep(300));

// Parking must not change what the call answers: the status is the whole point of waiting.
Ignis\async(static function (): void {
    $wrong = 0;
    $seven = proc_open('exit 7', [], $pipes);
    $wrong += proc_close($seven) === 7 ? 0 : 1;
    $zero = proc_open('exit 0', [], $pipes);
    $wrong += proc_close($zero) === 0 ? 0 : 1;

    $output = [];
    $code = 0;
    exec('printf hello; exit 3', $output, $code);
    $wrong += ($output === ['hello'] && $code === 3) ? 0 : 1;

    // A child that died before anyone waited: the pidfd is readable at once and the status still lands.
    $dead = proc_open('true', [], $pipes);
    usleep(50_000);
    $wrong += proc_close($dead) === 0 ? 0 : 1;

    printf("waitpid answers_wrong=%d\n", $wrong);
})->await();
