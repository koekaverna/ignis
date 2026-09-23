<?php

declare(strict_types=1);

namespace Ignis;

/**
 * Thrown into a fiber the recovery ladder killed (ADR-0043 §7), either by the engine's kill
 * signal handler or by `Loop::poolBody()` rejecting an unsettled job `Future`.
 */
final class KilledException extends \RuntimeException {}
