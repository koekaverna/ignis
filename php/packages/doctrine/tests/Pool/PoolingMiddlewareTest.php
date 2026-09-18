<?php

declare(strict_types=1);

namespace Ignis\Tests\Doctrine\Pool;

use Doctrine\DBAL\Driver;
use Ignis\Doctrine\Pool\PoolingDriver;
use Ignis\Doctrine\Pool\PoolingMiddleware;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The switch between the two connection modes, and the session reset each driver gets. */
#[CoversClass(PoolingMiddleware::class)]
#[AllowMockObjectsWithoutExpectations]
final class PoolingMiddlewareTest extends TestCase
{
    public function testWithoutALimitTheDriverIsHandedBackUntouched(): void
    {
        $driver = $this->createMock(Driver::class);

        self::assertSame($driver, (new PoolingMiddleware(limit: 0))->wrap($driver), 'per-fiber mode must cost nothing');
    }

    public function testWithALimitTheDriverLeasesFromAPool(): void
    {
        self::assertInstanceOf(PoolingDriver::class, (new PoolingMiddleware(limit: 4))->wrap($this->createMock(Driver::class)));
    }

    public function testOneParameterSetGetsOnePool(): void
    {
        $middleware = new PoolingMiddleware(limit: 4, waitMilliseconds: 0, warmCount: 0);
        $driver = $this->createMock(Driver::class);

        $first = $middleware->poolFor($driver, ['driver' => 'pdo_pgsql', 'dbname' => 'a']);

        self::assertSame($first, $middleware->poolFor($driver, ['driver' => 'pdo_pgsql', 'dbname' => 'a']));
        self::assertNotSame($first, $middleware->poolFor($driver, ['driver' => 'pdo_pgsql', 'dbname' => 'b']));
        self::assertSame(['limit' => 4, 'open' => 0, 'idle' => 0, 'waiting' => 0], $middleware->stats()[array_key_first($middleware->stats())]);
    }

    public function testThePoolWarmsItselfWhenItIsCreated(): void
    {
        $driver = $this->createMock(Driver::class);
        $driver->expects($this->exactly(2))->method('connect')->willReturnCallback(fn() => $this->createMock(Driver\Connection::class));

        $middleware = new PoolingMiddleware(limit: 6, waitMilliseconds: 0, warmCount: 2);

        self::assertSame(2, $middleware->poolFor($driver, ['driver' => 'pdo_pgsql'])->stats()['open']);
    }
}
