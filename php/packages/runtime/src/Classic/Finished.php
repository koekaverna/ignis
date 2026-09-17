<?php

declare(strict_types=1);

namespace Ignis\Classic;

use Ignis\Http\Request;
use Ignis\Http\Response;

/** Thrown by finish() to end the current script; caught by the runner. */
final class Finished extends \RuntimeException {}
