<?php

declare(strict_types=1);

namespace Ignis\Tests\Symfony;

use Ignis\Scope;
use Ignis\Symfony\FiberRequestStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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

    /** The scope slot is a generic bag, so anything other than a list of Request is a bug, not a `mixed` to trust. */
    public function testANonArrayScopeValueIsRejected(): void
    {
        Scope::set('symfony.request_stack', 'not-an-array');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('holds something other than an array');
        (new FiberRequestStack())->getCurrentRequest();
    }

    public function testAScopeArrayHoldingSomethingOtherThanARequestIsRejected(): void
    {
        Scope::set('symfony.request_stack', ['not-a-request']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('holds something other than a Request');
        (new FiberRequestStack())->getCurrentRequest();
    }

    /**
     * The hole this class had until 2026-09-18: `getSession()` was inherited, so it read
     * `RequestStack`'s own private array — always empty here — and threw even with a session on the
     * current request. `AbstractController::addFlash()` is the caller that made it visible.
     */
    public function testTheSessionComesFromThisFibersCurrentRequest(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $stack = new FiberRequestStack();
        $stack->push($request);

        self::assertSame($session, $stack->getSession());
    }

    public function testWithoutARequestTheSessionIsReportedAsMissing(): void
    {
        $this->expectException(SessionNotFoundException::class);

        (new FiberRequestStack())->getSession();
    }

    public function testARequestWithoutASessionIsReportedAsMissing(): void
    {
        $stack = new FiberRequestStack();
        $stack->push(new Request());

        $this->expectException(SessionNotFoundException::class);
        $stack->getSession();
    }

    /** Seeded requests must land in this fiber's stack, not in the parent's array where nothing reads them. */
    public function testRequestsGivenToTheConstructorAreVisible(): void
    {
        $first = new Request();
        $second = new Request();

        $stack = new FiberRequestStack([$first, $second]);

        self::assertSame($first, $stack->getMainRequest());
        self::assertSame($second, $stack->getCurrentRequest());
    }

    /** What the owner asked: a pooled fiber must not inherit the last request's stack (V-67). */
    public function testClearingTheScopeEmptiesTheStackForTheNextRequest(): void
    {
        $stack = new FiberRequestStack();
        $stack->push(new Request());          // a request that never popped — an exception mid-handler
        self::assertNotNull($stack->getCurrentRequest());

        Scope::clear();                        // what Loop::releaseRequest does in its finally

        self::assertNull($stack->getCurrentRequest());
        self::assertNull($stack->getMainRequest());
    }
}
