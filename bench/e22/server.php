<?php
// The ignis arm: the same dump, served by the runtime.
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

Ignis\serve(
    static fn (): Ignis\Http\Response => Ignis\Http\Response::json(['post' => $_POST]),
    getenv('IGNIS_LISTEN') ?: '127.0.0.1:8198',
);
