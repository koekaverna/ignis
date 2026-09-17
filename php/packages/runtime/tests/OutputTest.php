<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The contended case needs a running loop and is measured under the real binary
 * (`bench/php/output_isolation.php`, with a control that must leak). What is here is everything the
 * single-fiber semantics have to guarantee — above all that the lock is released even when the
 * emitter throws, because a leaked lock parks every later response for ever.
 */
#[CoversClass(Output::class)]
final class OutputTest extends TestCase
{
    public function testReturnsWhatWasEchoed(): void
    {
        self::assertSame('hello', Output::capture(static function (): void {
            echo 'hel';
            echo 'lo';
        }));
    }

    public function testCapturesNothingWhenNothingIsEchoed(): void
    {
        self::assertSame('', Output::capture(static function (): void {}));
    }

    public function testNobodyHoldsItAfterwards(): void
    {
        Output::capture(static function (): void {
            echo 'x';
        });
        self::assertFalse(Output::isHeld());
    }

    public function testAThrowingEmitterStillReleasesTheLock(): void
    {
        $level = \ob_get_level();   // not zero: the test runner has buffers of its own

        try {
            Output::capture(static function (): void {
                echo 'partial';
                throw new \RuntimeException('boom');
            });
            self::fail('the exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertFalse(Output::isHeld(), 'a leaked lock parks every later response for ever');
        self::assertSame($level, \ob_get_level(), 'and a leaked buffer swallows everything after it');
        self::assertSame('after', Output::capture(static function (): void {
            echo 'after';
        }));
    }

    /** A fiber cannot interleave with itself, so nesting must not wait for the lock it holds. */
    public function testNestedCaptureInTheSameFiberDoesNotDeadlock(): void
    {
        $out = Output::capture(static function (): void {
            echo 'outer(';
            $inner = Output::capture(static function (): void {
                echo 'inner';
            });
            echo $inner, ')';
        });

        self::assertSame('outer(inner)', $out);
    }

    public function testOutputIsNotAlsoWrittenThrough(): void
    {
        $level = \ob_get_level();
        Output::capture(static function (): void {
            echo 'swallowed';
        });
        self::assertSame($level, \ob_get_level());
    }
}
