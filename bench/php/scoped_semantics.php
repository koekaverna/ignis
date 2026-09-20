<?php

// ADR-0042's remaining named tests, in one arm: inheritance both ways, the pointer path
// (`$this->list[] =`, `$this->count++`), and clone/serialize/reflection. The mechanism leaves every
// standard handler alone, so the answer to all of these should be "whatever PHP already does" —
// this exists to prove that rather than assume it.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

class Base
{
    public int $inherited = 0;
}

class Child extends Base
{
    public int $own = 0;
    /** @var list<string> */
    public array $list = [];
    public int $count = 0;
}

$scopedChild = Ignis\Scope::create(Child::class);
$plainChild = new Child();
$result = [];

$first = Ignis\async(static function () use ($scopedChild, $plainChild, &$result): void {
    $scopedChild->inherited = 1;
    $scopedChild->own = 1;
    $scopedChild->list[] = 'A';        // get_property_ptr_ptr path
    $scopedChild->count++;             // and the read-modify-write path
    $plainChild->inherited = 1;
    Ignis\sleep(30);
    $result['a_inherited'] = $scopedChild->inherited;
    $result['a_own'] = $scopedChild->own;
    $result['a_list'] = implode(',', $scopedChild->list);
    $result['a_count'] = $scopedChild->count;
    $result['a_plain'] = $plainChild->inherited;   // a plain instance of a scoped class is untouched
});
$second = Ignis\async(static function () use ($scopedChild, $plainChild, &$result): void {
    Ignis\sleep(10);
    $scopedChild->inherited = 2;
    $scopedChild->own = 2;
    $scopedChild->list[] = 'B';
    $scopedChild->count++;
    $scopedChild->count++;
    $plainChild->inherited = 2;
    $result['b_list'] = implode(',', $scopedChild->list);
    $result['b_count'] = $scopedChild->count;
});
Ignis\all([$first, $second]);
Ignis\Loop::run();

$copy = clone $scopedChild;
$copy->own = 99;
$reflected = (new ReflectionProperty(Child::class, 'own'))->getValue($scopedChild);
$serialised = str_contains(serialize($scopedChild), 'own');

printf(
    "scoped_semantics: a_inherited=%d a_own=%d a_list=%s a_count=%d b_list=%s b_count=%d plain_untouched=%s clone_independent=%s reflection=%d serialize_sees_props=%s\n",
    $result['a_inherited'],
    $result['a_own'],
    $result['a_list'],
    $result['a_count'],
    $result['b_list'],
    $result['b_count'],
    var_export($result['a_plain'] === 2, true),
    var_export($scopedChild->own !== 99, true),
    $reflected,
    var_export($serialised, true),
);
