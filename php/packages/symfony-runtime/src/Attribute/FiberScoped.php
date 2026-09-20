<?php

declare(strict_types=1);

namespace Ignis\Symfony\Attribute;

/**
 * `#[FiberScoped]` on a service class, autoconfigured by `IgnisBundle` into the `ignis.scoped` tag
 * `FiberScopePass` reads (BACKLOG.md S-SCOPED-CLASS) — the application-code counterpart to the
 * container parameter that lists the framework's own vendor ids.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FiberScoped {}
