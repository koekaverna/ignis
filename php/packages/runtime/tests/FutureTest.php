<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Future;
use Ignis\Loop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Only the settling half is covered here: `await()` needs the reactor, and that is what the E-suites
 * measure against the real binary.
 */
#[CoversClass(Future::class)]
final class FutureTest extends TestCase
{
    protected function setUp(): void
    {
        Loop::$unobserved = [];
    }

    public function testStartsUnsettled(): void
    {
        self::assertFalse((new Future())->isDone());
    }

    public function testResolveSettlesOnce(): void
    {
        $f = new Future();
        $f->resolve(42);
        self::assertTrue($f->isDone());

        $this->expectException(\LogicException::class);
        $f->resolve(43);
    }

    public function testRejectAlsoSettles(): void
    {
        $f = new Future();
        $f->reject(new \RuntimeException('no'));
        self::assertTrue($f->isDone());

        $this->expectException(\LogicException::class);
        $f->reject(new \RuntimeException('again'));
    }

    /** A rejection nobody is waiting for must be visible to the loop rather than swallowed. */
    public function testARejectionWithNoWaiterIsRecorded(): void
    {
        $e = new \RuntimeException('unobserved');
        (new Future())->reject($e);

        self::assertSame([$e], Loop::$unobserved);
    }

    public function testResolvingRecordsNothing(): void
    {
        (new Future())->resolve('fine');
        self::assertSame([], Loop::$unobserved);
    }
}
