<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `Scope` is what makes a service per request rather than per process, so these are the properties
 * the security token (V-68) and Doctrine's EntityManager (V-69) rest on.
 */
#[CoversClass(Scope::class)]
final class ScopeTest extends TestCase
{
    protected function setUp(): void
    {
        FakeReactor::reset();
        Scope::clear();
    }

    public function testMainContextKeepsItsOwnValues(): void
    {
        self::assertNull(Scope::get('k'));
        self::assertSame('fallback', Scope::get('k', 'fallback'));

        Scope::set('k', 'v');
        self::assertSame('v', Scope::get('k'));
    }

    /** Each fiber sets its tag, yields once so the other can run, then reads its own tag back. */
    public function testTwoFibersDoNotSeeEachOther(): void
    {
        $seen = [];
        $fibers = [];
        foreach (['a', 'b'] as $tag) {
            $fiber = new \Fiber(static function () use ($tag, &$seen): void {
                Scope::set('tag', $tag);
                \Fiber::suspend();
                $seen[$tag] = Scope::get('tag');
            });
            $fiber->start();
            $fibers[] = $fiber;
        }
        foreach ($fibers as $fiber) {
            $fiber->resume();
        }

        self::assertSame(['a' => 'a', 'b' => 'b'], $seen);
    }

    public function testAFiberDoesNotSeeTheMainContext(): void
    {
        Scope::set('k', 'main');

        $fiber = new \Fiber(static fn(): mixed => Scope::get('k'));
        $fiber->start();

        self::assertNull($fiber->getReturn());
        self::assertSame('main', Scope::get('k'));
    }

    /** The V-67 defect: a pooled fiber must not carry its values into the next request. */
    public function testClearDropsEverythingThisFiberHolds(): void
    {
        $fiber = new \Fiber(static function (): array {
            Scope::set('a', 1);
            Scope::set('b', 2);
            Scope::clear();

            return [Scope::get('a'), Scope::get('b', 'gone')];
        });
        $fiber->start();

        self::assertSame([null, 'gone'], $fiber->getReturn());
    }

    public function testClearInOneFiberLeavesAnotherAlone(): void
    {
        $other = new \Fiber(static function (): mixed {
            Scope::set('k', 'keep');
            \Fiber::suspend();

            return Scope::get('k');
        });
        $other->start();

        $clearer = new \Fiber(static function (): void {
            Scope::set('k', 'drop');
            Scope::clear();
        });
        $clearer->start();

        $other->resume();
        self::assertSame('keep', $other->getReturn());
    }

    /**
     * The S-SCOPED-CLASS invariant (DECISIONS.md 2026-09-20): allocate must run before construct,
     * not the other way round. `ignis_scope_allocate()` is faked by `tests/fake-reactor.php`
     * (`ReflectionClass::newInstanceWithoutConstructor()`), which cannot reproduce per-scope property
     * storage — that half is engine territory this package does not own — so this only proves the
     * call order `Scope::create()` itself is responsible for, via the two fakes' shared event log.
     */
    public function testCreateAllocatesBeforeConstructing(): void
    {
        $class = ScopeCreateOrderSubject::class;

        $instance = Scope::create($class, 'seed');

        self::assertInstanceOf($class, $instance);
        self::assertSame('seed', $instance->value);
        self::assertSame(['allocate:' . $class, 'construct:' . $class . ':seed'], FakeReactor::scopeEvents());
    }

    public function testClearAlsoClearsTheScopedObjectRows(): void
    {
        $before = FakeReactor::scopeRowsCleared();

        Scope::clear();

        self::assertSame($before + 1, FakeReactor::scopeRowsCleared());
    }
}

/** Records its own construction into the shared fake event log, so a test can order it against allocate. */
final class ScopeCreateOrderSubject
{
    public function __construct(public readonly string $value)
    {
        FakeReactor::recordScopeEvent('construct:' . self::class . ':' . $value);
    }
}
