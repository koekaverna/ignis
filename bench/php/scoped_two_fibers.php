<?php

// ADR-0042 acceptance step 1: a standalone class, two interleaved fibers, each sees its own value.
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

final class CartContext
{
    private ?string $tag = null;
    public function __construct(public readonly string $shared) {}
    public function tag(): ?string
    {
        return $this->tag;
    }
    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }
}

$service = Ignis\Scope::create(CartContext::class, 'built-once');

$out = [];
$a = Ignis\async(static function () use ($service, &$out): void {
    $service->setTag('A');
    Ignis\sleep(20);                       // let B run in between
    $out['A sees'] = $service->tag();
});
$b = Ignis\async(static function () use ($service, &$out): void {
    Ignis\sleep(10);
    $service->setTag('B');
    $out['B sees'] = $service->tag();
});
Ignis\all([$a, $b]);
$inside = null;
Ignis\async(static function () use ($service, &$inside): void {
    $inside = $service->shared;
});
Ignis\Loop::run();

printf(
    "scoped_two_fibers: a=%s b=%s constructor_value_inside_a_fiber=%s instance_of=%s\n",
    var_export($out['A sees'] ?? null, true),
    var_export($out['B sees'] ?? null, true),
    var_export($inside, true),
    var_export($service instanceof CartContext, true),
);
