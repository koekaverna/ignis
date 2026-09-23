<?php

declare(strict_types=1);

namespace Ignis\Tests\Testing;

use Ignis\Testing\BlockingAssertions;
use Ignis\Testing\BlockingAudit;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * `BlockingAssertions` needs the binary's detector (`ignis_blocking_sequence()`/`_records()`) to
 * do anything beyond running the callable: this suite is the no-op contract a test case gets.
 */
#[CoversTrait(BlockingAssertions::class)]
#[CoversTrait(BlockingAudit::class)]
final class BlockingAssertionsTest extends TestCase
{
    use BlockingAssertions;

    public function testAssertNoBlockingCallsStillRunsAndReturnsTheCallablesResultWithoutTheDetector(): void
    {
        self::assertFalse(\function_exists('ignis_blocking_sequence'), 'this suite runs under the plain php CLI, with no detector to assert against');

        $result = $this->assertNoBlockingCalls(static fn(): int => 42);

        self::assertSame(42, $result);
    }

    public function testIgnisBlockingRecordsSinceIsEmptyWithoutTheDetector(): void
    {
        self::assertSame([], $this->ignisBlockingRecordsSince(0));
    }
}
