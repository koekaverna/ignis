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
        Scope::clear();
    }

    public function testMainContextKeepsItsOwnValues(): void
    {
        self::assertNull(Scope::get('k'));
        self::assertSame('fallback', Scope::get('k', 'fallback'));

        Scope::set('k', 'v');
        self::assertSame('v', Scope::get('k'));
    }

    public function testTwoFibersDoNotSeeEachOther(): void
    {
        $seen = [];
        $fibers = [];
        foreach (['a', 'b'] as $tag) {
            $f = new \Fiber(static function () use ($tag, &$seen): void {
                Scope::set('tag', $tag);
                \Fiber::suspend();                 // let the other fiber run and set its own
                $seen[$tag] = Scope::get('tag');
            });
            $f->start();
            $fibers[] = $f;
        }
        foreach ($fibers as $f) {
            $f->resume();
        }

        self::assertSame(['a' => 'a', 'b' => 'b'], $seen);
    }

    public function testAFiberDoesNotSeeTheMainContext(): void
    {
        Scope::set('k', 'main');

        $f = new \Fiber(static fn (): mixed => Scope::get('k'));
        $f->start();

        self::assertNull($f->getReturn());
        self::assertSame('main', Scope::get('k'));
    }

    /** The V-67 defect: a pooled fiber must not carry its values into the next request. */
    public function testClearDropsEverythingThisFiberHolds(): void
    {
        $f = new \Fiber(static function (): array {
            Scope::set('a', 1);
            Scope::set('b', 2);
            Scope::clear();

            return [Scope::get('a'), Scope::get('b', 'gone')];
        });
        $f->start();

        self::assertSame([null, 'gone'], $f->getReturn());
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
}
