<?php

// A pooled fiber serving requests one after another. Every arm before this one used *concurrent*
// requests on different fibers, and that is a different path: sequential reuse is where
// `Scope::clear()` has to actually undo something, and where a scoped service either does or does
// not replace `ResetInterface`. Measured before the fix: request 2 read request 1's state.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

final class Witness
{
    /** @var list<string> */
    public array $seen = [];

    public ?string $token = null;
}

$witness = Ignis\Scope::create(Witness::class);

Ignis\serve(static function (Ignis\Http\Request $request) use ($witness): Ignis\Http\Response {
    $tag = $request->query('tag') ?? '?';
    $onEntry = ['seen' => $witness->seen, 'token' => $witness->token];
    $witness->seen[] = $tag;
    $witness->token = $tag;

    return new Ignis\Http\Response(
        json_encode(['tag' => $tag, 'on_entry' => $onEntry]) . "\n",
        200,
        ['content-type' => 'application/json'],
    );
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8226');
