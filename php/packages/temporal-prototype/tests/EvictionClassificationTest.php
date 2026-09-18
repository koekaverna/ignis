<?php

declare(strict_types=1);

namespace Ignis\Tests\Temporal;

use Ignis\Temporal\Worker;
use PHPUnit\Framework\TestCase;

/**
 * `bench/e9-temporal.sh`'s negative control exists to fail when a workflow is replayed against a
 * mutated history, and it decides that from this classification. It had stopped: the list said
 * 'Nondeterminism' and the wire said `NONDETERMINISM` from the moment V-65 put core's own protojson
 * on the boundary, so the control printed REPLAY_OK while sdk-core evicted in the same log. The
 * cheap half of the fix is spelling-insensitivity; this test is the half that keeps it fixed.
 */
final class EvictionClassificationTest extends TestCase
{
    /**
     * @param int|string|null $reason
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function testAnEvictionIsAnErrorOnlyWhenTheWorkflowWasRejected(mixed $reason, bool $isError): void
    {
        $classify = new \ReflectionMethod(Worker::class, 'isEvictionAnError');

        self::assertSame($isError, $classify->invoke(null, $reason));
    }

    /** @return iterable<string, array{0: mixed, 1: bool}> */
    public static function reasons(): iterable
    {
        yield 'protojson, as the wire spells it' => ['NONDETERMINISM', true];
        yield 'the spelling the list used to have' => ['Nondeterminism', true];
        yield 'underscored' => ['NON_DETERMINISM', true];
        yield 'lang failure' => ['LANG_FAIL', true];
        yield 'fatal' => ['FATAL', true];
        yield 'the prost tag' => [3, true];

        // Cache pressure is routine. Counting it would redden a healthy run, which is the opposite
        // failure and just as bad: a gate nobody trusts is a gate nobody reads.
        yield 'cache pressure is not a rejection' => ['CACHE_FULL', false];
        yield 'unspecified' => ['Unspecified', false];
        yield 'tag zero' => [0, false];
        yield 'absent' => [null, false];
    }
}
