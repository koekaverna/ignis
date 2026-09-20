<?php

// ADR-0042's kill criterion: what a scoped property costs against a plain one, and what the swap
// variant costs at the fiber switch it pays on. Run under both shapes:
//   ./target/release/ignis bench/php/scoped_cost.php                      # handlers
//   IGNIS_SCOPED_MODE=swap ./target/release/ignis bench/php/scoped_cost.php
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

final class Holder
{
    public int $a = 0;
    public int $b = 0;
    public int $c = 0;
    public int $d = 0;

    public function bump(): void
    {
        $this->a = $this->a + 1;
    }
}

$reads = (int) (getenv('READS') ?: 200000);
$switches = (int) (getenv('SWITCHES') ?: 20000);
$services = (int) (getenv('SERVICES') ?: 8);

$plain = new Holder();
$scoped = [];
for ($i = 0; $i < $services; $i++) {
    $scoped[] = Ignis\Scope::create(Holder::class);
}

$time = static function (callable $body): float {
    $start = hrtime(true);
    $body();
    return (hrtime(true) - $start) / 1e6;
};

$plainMs = $time(static function () use ($plain, $reads): void {
    for ($i = 0; $i < $reads; $i++) {
        $plain->bump();
    }
});

$first = $scoped[0];
$scopedMs = $time(static function () use ($first, $reads): void {
    for ($i = 0; $i < $reads; $i++) {
        $first->bump();
    }
});

// Fiber switches with the scoped services live, which is what the swap variant pays for.
$switchMs = $time(static function () use ($switches): void {
    $fibers = [];
    for ($i = 0; $i < 2; $i++) {
        $fibers[] = Ignis\async(static function () use ($switches): void {
            for ($n = 0; $n < $switches; $n++) {
                Ignis\sleep(0);
            }
        });
    }
    Ignis\all($fibers);
    Ignis\Loop::run();
});

printf(
    "scoped_cost: mode=%s services=%d plain_ns_per_op=%.1f scoped_ns_per_op=%.1f ratio=%.1f switch_us_per_switch=%.2f\n",
    \ignis_scope_mode(),
    $services,
    $plainMs * 1e6 / $reads,
    $scopedMs * 1e6 / $reads,
    $scopedMs / max($plainMs, 0.0001),
    $switchMs * 1000 / max($switches * 2, 1),
);
