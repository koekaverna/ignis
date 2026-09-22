<?php

declare(strict_types=1);

namespace Ignis;

/**
 * Thrown into a fiber the recovery ladder killed (ADR-0043 §7): the engine's own kill signal
 * handler throws this by name when `kill = "exception"`, and `Loop::poolBody()` rejects an
 * unsettled job `Future` with one when its fiber was force-closed under the default `kill =
 * "graceful"` mode, so a parent awaiting it wakes up instead of hanging.
 */
final class KilledException extends \RuntimeException {}
