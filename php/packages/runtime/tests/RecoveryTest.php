<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\Recovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Recovery` needs neither the fake reactor nor the ignis binary: `parseRoutes()` and
 * `profileDefault()` are pure functions of a string, and `fiberTimeoutFor()` only reads the
 * environment `Env` already reads everywhere else in the runtime (ADR-0043 §8, research 50 S-15).
 */
#[CoversClass(Recovery::class)]
final class RecoveryTest extends TestCase
{
    private const VARIABLES = ['IGNIS_PROFILE', 'IGNIS_FIBER_TIMEOUT_MS', 'IGNIS_RECOVERY_ROUTES'];

    /** @var array<string, string|false> */
    private array $before = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->before[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function profileDefaults(): iterable
    {
        yield 'production is the default for an unrecognised name' => ['production', 0];
        yield 'unset falls back to production'                     => ['', 0];
        yield 'load-test'                                           => ['load-test', 30_000];
        yield 'load_test spelled with an underscore'                => ['load_test', 30_000];
        yield 'loadtest with no separator'                          => ['loadtest', 30_000];
        yield 'test'                                                 => ['test', 5_000];
        yield 'padded with whitespace'                              => [' test  ', 5_000];
        yield 'an unknown profile name'                             => ['made-up', 0];
    }

    #[DataProvider('profileDefaults')]
    public function testProfileDefaultResolvesTheFiberTimeoutProductDefault(string $profile, int $expected): void
    {
        self::assertSame($expected, Recovery::profileDefault($profile));
    }

    public function testParseRoutesKeepsEveryRouteAndReadsOnlyFiberTimeoutMs(): void
    {
        $routes = Recovery::parseRoutes('/export/=fiber_timeout_ms:120000;stall_kill_ms:0,/=busy_warn_ms:5');

        self::assertSame(
            [['/export/', 120_000], ['/', null]],
            $routes,
            'busy_warn_ms and stall_kill_ms are Rust-only, but the route itself still takes part in the longest-prefix match',
        );
    }

    public function testParseRoutesRejectsANegativeOrFractionalTimeoutLikeRustDoes(): void
    {
        self::assertSame([['/export/', null]], Recovery::parseRoutes('/export/=fiber_timeout_ms:-1'));
        self::assertSame([['/export/', null]], Recovery::parseRoutes('/export/=fiber_timeout_ms:1.5'));
        self::assertSame([['/export/', null]], Recovery::parseRoutes('/export/=fiber_timeout_ms:1e3'));
        self::assertSame([['/export/', 7]], Recovery::parseRoutes('/export/=fiber_timeout_ms: 007 '));
    }

    public function testParseRoutesOrdersTheLongestPrefixFirst(): void
    {
        $routes = Recovery::parseRoutes('/=fiber_timeout_ms:1,/export/=fiber_timeout_ms:2,/export/slow/=fiber_timeout_ms:3');

        self::assertSame(
            [['/export/slow/', 3], ['/export/', 2], ['/', 1]],
            $routes,
            'the longest matching prefix must be tried first',
        );
    }

    public function testParseRoutesIgnoresAnEntryWithNoEqualsSign(): void
    {
        self::assertSame([], Recovery::parseRoutes('not-a-route-entry'));
    }

    public function testParseRoutesIgnoresAnEntryWithAnEmptyPrefix(): void
    {
        self::assertSame([], Recovery::parseRoutes('=fiber_timeout_ms:100'));
    }

    public function testParseRoutesKeepsTheRouteButNotAKeyWithANonNumericValue(): void
    {
        self::assertSame([['/export/', null]], Recovery::parseRoutes('/export/=fiber_timeout_ms:soon'));
    }

    public function testParseRoutesAcceptsAnExplicitZeroToTurnTheTimeoutOff(): void
    {
        self::assertSame([['/export/', 0]], Recovery::parseRoutes('/export/=fiber_timeout_ms:0'));
    }

    public function testParseRoutesOfAnEmptyStringIsEmpty(): void
    {
        self::assertSame([], Recovery::parseRoutes(''));
    }

    public function testFiberTimeoutForFallsBackToTheProfileDefaultWithNoOverride(): void
    {
        putenv('IGNIS_PROFILE=load-test');
        putenv('IGNIS_FIBER_TIMEOUT_MS');
        putenv('IGNIS_RECOVERY_ROUTES');

        self::assertSame(30_000, Recovery::fiberTimeoutFor('/anything'));
    }

    public function testFiberTimeoutForPrefersAnExplicitEnvironmentVariableOverTheProfile(): void
    {
        putenv('IGNIS_PROFILE=load-test');
        putenv('IGNIS_FIBER_TIMEOUT_MS=1234');
        putenv('IGNIS_RECOVERY_ROUTES');

        self::assertSame(1234, Recovery::fiberTimeoutFor('/anything'));
    }

    public function testFiberTimeoutForPrefersTheLongestMatchingRouteOverTheGlobalValue(): void
    {
        putenv('IGNIS_PROFILE=production');
        putenv('IGNIS_FIBER_TIMEOUT_MS=1234');
        putenv('IGNIS_RECOVERY_ROUTES=/export/=fiber_timeout_ms:120000;stall_kill_ms:0,/=busy_warn_ms:5');

        self::assertSame(120_000, Recovery::fiberTimeoutFor('/export/report.csv'));
        self::assertSame(1234, Recovery::fiberTimeoutFor('/other'), 'a route with no fiber_timeout_ms key does not override anything');
    }

    public function testFiberTimeoutForLetsALongerPrefixWithoutATimeoutInheritTheGlobalValue(): void
    {
        putenv('IGNIS_PROFILE=production');
        putenv('IGNIS_FIBER_TIMEOUT_MS=1234');
        putenv('IGNIS_RECOVERY_ROUTES=/=fiber_timeout_ms:30,/export/=stall_kill_ms:0');

        self::assertSame(1234, Recovery::fiberTimeoutFor('/export/report'), 'Rust picks /export/ first and inherits the global timeout; so must PHP');
        self::assertSame(30, Recovery::fiberTimeoutFor('/other'));
    }

    public function testFiberTimeoutForIgnoresANegativeGlobalValueLikeRustDoes(): void
    {
        putenv('IGNIS_PROFILE=load-test');
        putenv('IGNIS_FIBER_TIMEOUT_MS=-1');
        putenv('IGNIS_RECOVERY_ROUTES');

        self::assertSame(30_000, Recovery::fiberTimeoutFor('/anything'), 'a value Rust refuses must not turn the PHP timeout off');
    }
}
