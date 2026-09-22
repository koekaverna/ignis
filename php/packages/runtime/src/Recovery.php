<?php

declare(strict_types=1);

namespace Ignis;

/**
 * ADR-0043 §8: the recovery settings the PHP loop needs, resolved with the same precedence as
 * `crates/ignis/src/recovery.rs` — an explicit `IGNIS_*` variable beats the profile default, the
 * profile beats the product default. Only `fiber_timeout_ms` (L0) matters to PHP: everything else
 * in `[recovery]` and `[recovery.routes.*]` is enforced by the Rust ticker.
 */
final class Recovery
{
    private const PRODUCTION_FIBER_TIMEOUT_MS = 0;
    private const LOAD_TEST_FIBER_TIMEOUT_MS = 30_000;
    private const TEST_FIBER_TIMEOUT_MS = 5_000;

    /** The `fiber_timeout_ms` product default for a profile name, unrecognised names falling back to production's. */
    public static function profileDefault(string $profile): int
    {
        return match (\trim($profile)) {
            'load-test', 'load_test', 'loadtest', 'load' => self::LOAD_TEST_FIBER_TIMEOUT_MS,
            'test' => self::TEST_FIBER_TIMEOUT_MS,
            default => self::PRODUCTION_FIBER_TIMEOUT_MS,
        };
    }

    /**
     * `IGNIS_RECOVERY_ROUTES`, the mirror of `recovery::parse_routes()`: comma separated
     * `prefix=key:value;key:value` entries, longest prefix first so the caller can take the first
     * match. Only the `fiber_timeout_ms` key is kept, because that is all PHP enforces.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function parseRoutes(string $text): array
    {
        $routes = [];
        foreach (\explode(',', $text) as $entry) {
            $route = self::routeFiberTimeoutFromEntry($entry);
            if ($route !== null) {
                $routes[] = $route;
            }
        }
        \usort($routes, static fn(array $left, array $right): int => \strlen($right[0]) <=> \strlen($left[0]));

        return $routes;
    }

    /** @return array{0: string, 1: int}|null */
    private static function routeFiberTimeoutFromEntry(string $entry): ?array
    {
        $equalsPosition = \strpos($entry, '=');
        if ($equalsPosition === false) {
            return null;
        }
        $prefix = \trim(\substr($entry, 0, $equalsPosition));
        if ($prefix === '') {
            return null;
        }
        $fiberTimeoutMilliseconds = self::fiberTimeoutFromKeys(\substr($entry, $equalsPosition + 1));

        return $fiberTimeoutMilliseconds === null ? null : [$prefix, $fiberTimeoutMilliseconds];
    }

    private static function fiberTimeoutFromKeys(string $keys): ?int
    {
        foreach (\explode(';', $keys) as $keyValue) {
            $colonPosition = \strpos($keyValue, ':');
            if ($colonPosition === false) {
                continue;
            }
            $key = \trim(\substr($keyValue, 0, $colonPosition));
            $value = \trim(\substr($keyValue, $colonPosition + 1));
            if ($key === 'fiber_timeout_ms' && \is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * The L0 ceiling for a request against `$uri`: an explicit `IGNIS_FIBER_TIMEOUT_MS`, else the
     * active profile's default, then the longest matching `IGNIS_RECOVERY_ROUTES` prefix overrides
     * either one. 0 means off.
     */
    public static function fiberTimeoutFor(string $uri): int
    {
        $profileDefault = self::profileDefault(Env::text('IGNIS_PROFILE', 'production'));
        $fiberTimeoutMilliseconds = Env::integer('IGNIS_FIBER_TIMEOUT_MS', $profileDefault);
        foreach (self::parseRoutes(Env::text('IGNIS_RECOVERY_ROUTES', '')) as [$prefix, $overrideMilliseconds]) {
            if (\str_starts_with($uri, $prefix)) {
                return $overrideMilliseconds;
            }
        }

        return $fiberTimeoutMilliseconds;
    }
}
