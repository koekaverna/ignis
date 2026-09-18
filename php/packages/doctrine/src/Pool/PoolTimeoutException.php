<?php

declare(strict_types=1);

namespace Ignis\Doctrine\Pool;

/**
 * Every connection in the pool is leased and none came free in time.
 *
 * A bounded wait that ends in an exception is the point of a pool: the alternative is a request
 * parked forever behind whatever is holding the connections, which looks like a hung server rather
 * than a saturated one.
 */
final class PoolTimeoutException extends \RuntimeException {}
