<?php

// ADR-0042: the container's normal path — a scoped service is built lazily, inside the request that
// first asks for it, not at boot. What its constructor wrote must be visible to every other scope,
// and what a request writes must not be. This arm found two real defects: the constructor's values
// were trapped in the building fiber's scope (no seal), and `ignis_scope_seal` was reading `handle`
// out of a zval because "o" yields a zval*, not a zend_object*.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

final class LazyService
{
    private ?string $seen = null;

    public function __construct(public readonly string $dependency) {}

    public function see(string $value): void
    {
        $this->seen = $value;
    }

    public function seen(): ?string
    {
        return $this->seen;
    }
}

$service = null;
$result = ['a_dependency' => null, 'b_dependency' => null, 'b_seen' => 'unset', 'a_seen' => null];

$first = Ignis\async(static function () use (&$service, &$result): void {
    $service = Ignis\Scope::create(LazyService::class, 'injected');
    $result['a_dependency'] = $service->dependency;
    $service->see('A');
    Ignis\sleep(30);
    $result['a_seen'] = $service->seen();
});
$second = Ignis\async(static function () use (&$service, &$result): void {
    Ignis\sleep(10);

    try {
        $result['b_dependency'] = $service->dependency;
        $result['b_seen'] = $service->seen();
    } catch (\Throwable $failure) {
        $result['b_dependency'] = 'THREW: ' . $failure->getMessage();
    }
});
Ignis\all([$first, $second]);
Ignis\Loop::run();

printf(
    "scoped_lazy_build: mode=%s a_dependency=%s b_dependency=%s b_seen=%s a_seen=%s\n",
    \ignis_scope_mode(),
    var_export($result['a_dependency'], true),
    var_export($result['b_dependency'], true),
    var_export($result['b_seen'], true),
    var_export($result['a_seen'], true),
);
