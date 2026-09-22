<?php

declare(strict_types=1);

namespace Ignis\Tests\Testing;

use Ignis\Testing\DetectsBlocking;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `DetectsBlocking`'s `#[Before]`/`#[After]` hooks need the binary's detector to find anything:
 * this suite is the no-op contract a test case gets under a plain `php` CLI, without which
 * PHPStan reports the trait as unused (research 50 S-3, C-2).
 */
#[CoversClass(DetectsBlocking::class)]
final class DetectsBlockingTest extends TestCase
{
    use DetectsBlocking;

    public function testTheHooksAreNoOpsWithoutTheDetector(): void
    {
        self::assertFalse(\function_exists('ignis_blocking_sequence'), 'this suite runs under the plain php CLI, with no detector to assert against');

        $this->ignisStartBlockingWatch();
        $this->ignisAssertNoBlockingCalls();

        self::assertTrue(true, 'neither hook threw or failed the test');
    }
}
