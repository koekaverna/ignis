<?php

declare(strict_types=1);

namespace Ignis\Tests\Symfony;

use Ignis\Scope;
use Ignis\Symfony\FiberRequestStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * ADR-0011: Symfony's `RequestStack` is a container singleton holding one array, and under Ignis
 * several requests run on one thread at once. This subclass keeps the stack in `Ignis\Scope`, so
 * the interleaved requests never see each other's `Request`.
 */
#[CoversClass(FiberRequestStack::class)]
final class FiberRequestStackTest extends TestCase
{
    protected function setUp(): void
    {
        Scope::clear();
    }

    protected function tearDown(): void
    {
        Scope::clear();
    }

    public function testAnEmptyStackAnswersNullEverywhere(): void
    {
        $stack = new FiberRequestStack();

        self::assertNull($stack->pop());
        self::assertNull($stack->getCurrentRequest());
        self::assertNull($stack->getMainRequest());
        self::assertNull($stack->getParentRequest());
    }

    public function testTheMainTheParentAndTheCurrentRequestAreThePositionsInTheStack(): void
    {
        $stack = new FiberRequestStack();
        $main = Request::create('/main');
        $sub = Request::create('/sub');
        $subSub = Request::create('/sub/sub');

        $stack->push($main);
        self::assertSame($main, $stack->getCurrentRequest());
        self::assertSame($main, $stack->getMainRequest());
        self::assertNull($stack->getParentRequest(), 'the outermost request has no parent');

        $stack->push($sub);
        self::assertSame($sub, $stack->getCurrentRequest());
        self::assertSame($main, $stack->getMainRequest());
        self::assertSame($main, $stack->getParentRequest());

        $stack->push($subSub);
        self::assertSame($subSub, $stack->getCurrentRequest());
        self::assertSame($main, $stack->getMainRequest(), 'the main request stays the bottom of the stack however deep the forwards go');
        self::assertSame($sub, $stack->getParentRequest());
    }

    public function testPopReturnsTheRequestItRemoves(): void
    {
        $stack = new FiberRequestStack();
        $main = Request::create('/main');
        $sub = Request::create('/sub');
        $stack->push($main);
        $stack->push($sub);

        self::assertSame($sub, $stack->pop());
        self::assertSame($main, $stack->getCurrentRequest());
        self::assertSame($main, $stack->pop());
        self::assertNull($stack->pop());
    }

    /** The whole point of the class: one object, one stack per fiber (ADR-0006). */
    public function testTwoFibersSharingTheStackObjectDoNotSeeEachOthersRequest(): void
    {
        $stack = new FiberRequestStack();
        $mine = Request::create('/main-fiber');
        $theirs = Request::create('/other-fiber');

        $stack->push($mine);

        $other = new \Fiber(static function () use ($stack, $theirs): ?Request {
            $before = $stack->getCurrentRequest();
            $stack->push($theirs);
            \Fiber::suspend();

            return $before;
        });
        $other->start();

        self::assertSame($mine, $stack->getCurrentRequest(), "the other fiber's push is invisible here");

        $other->resume();
        self::assertNull($other->getReturn(), 'and this fiber\'s request was invisible there');
    }
}
