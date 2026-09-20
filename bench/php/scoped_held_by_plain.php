<?php

// The rule the whole design rests on, as two measurements rather than a sentence: hold the scoped
// *object* and every request reads its own state through it; hold a *value* taken out of it and the
// holder is pinned to whichever request wrote last. The second is S-SINGLETON-CAPTURE and it is
// silent — which is why it gets an arm rather than a paragraph.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

final class ScopedCart
{
    public ?string $tag = null;
}

final class PlainHolder
{
    public ?string $capturedValue = null;

    public function __construct(public readonly ScopedCart $cart) {}

    public function capture(): void
    {
        $this->capturedValue = $this->cart->tag;
    }
}

$cart = Ignis\Scope::create(ScopedCart::class);
$holder = new PlainHolder($cart);   // a plain `new`: one instance for the process
$result = [];

$first = Ignis\async(static function () use ($holder, &$result): void {
    $holder->cart->tag = 'A';
    $holder->capture();
    Ignis\sleep(30);
    $result['a_through_holder'] = $holder->cart->tag;
    $result['a_captured'] = $holder->capturedValue;
});
$second = Ignis\async(static function () use ($holder, &$result): void {
    Ignis\sleep(10);
    $holder->cart->tag = 'B';
    $holder->capture();
    $result['b_through_holder'] = $holder->cart->tag;
});
Ignis\all([$first, $second]);
Ignis\Loop::run();

printf(
    "scoped_held_by_plain: a_through_holder=%s b_through_holder=%s a_captured=%s\n",
    var_export($result['a_through_holder'], true),
    var_export($result['b_through_holder'], true),
    var_export($result['a_captured'], true),
);
