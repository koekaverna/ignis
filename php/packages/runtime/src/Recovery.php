<?php

declare(strict_types=1);

namespace Ignis;

/**
 * ADR-0043 §8: the recovery settings the PHP loop needs, resolved with the same precedence as
 * `crates/ignis/src/recovery.rs`. Only `fiber_timeout_ms` (L0) and `park_timeout_ms` matter to PHP.
 */
final class Recovery
{
    private const PRODUCTION_FIBER_TIMEOUT_MS = 0;
    private const LOAD_TEST_FIBER_TIMEOUT_MS = 30_000;
    private const TEST_FIBER_TIMEOUT_MS = 5_000;
    private const CHAOS_PARK_TIMEOUT_MS = 30_000;

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
     * `IGNIS_RECOVERY_ROUTES`, the mirror of `recovery::parse_routes()`: comma-separated
     * `prefix=key:value;key:value` entries, longest prefix first.
     * @return list<array{0: string, 1: int|null}>
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

    /** @return array{0: string, 1: int|null}|null */
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

        return [$prefix, self::fiberTimeoutFromKeys(\substr($entry, $equalsPosition + 1))];
    }

    private static function fiberTimeoutFromKeys(string $keys): ?int
    {
        foreach (\explode(';', $keys) as $keyValue) {
            $colonPosition = \strpos($keyValue, ':');
            if ($colonPosition === false) {
                continue;
            }
            $key = \trim(\substr($keyValue, 0, $colonPosition));
            if ($key === 'fiber_timeout_ms') {
                return self::unsignedMilliseconds(\substr($keyValue, $colonPosition + 1));
            }
        }

        return null;
    }

    /**
     * What Rust's `u64` parse accepts and nothing else: digits only, so `-1`, `1.5` and `1e3` are
     * rejected on both sides alike rather than clamped to 0 (off) here and refused there.
     */
    private static function unsignedMilliseconds(string $value): ?int
    {
        $value = \trim($value);
        if ($value === '' || !\ctype_digit($value)) {
            return null;
        }
        $milliseconds = (int) $value;

        return (string) $milliseconds === \ltrim($value, '0') || $milliseconds === 0 && \trim($value, '0') === '' ? $milliseconds : null;
    }

    /** The ceiling on one park: `IGNIS_PARK_TIMEOUT_MS`, else 30 s under `IGNIS_CHAOS`, else 0 (off), as `recovery.rs` resolves it. */
    public static function parkTimeoutMilliseconds(): int
    {
        return self::unsignedMilliseconds(Env::text('IGNIS_PARK_TIMEOUT_MS', ''))
            ?? (Env::flag('IGNIS_CHAOS') ? self::CHAOS_PARK_TIMEOUT_MS : 0);
    }

    /**
     * The L0 ceiling for a request against `$uri`: `IGNIS_FIBER_TIMEOUT_MS`, else the profile
     * default, overridden by the longest matching `IGNIS_RECOVERY_ROUTES` prefix. 0 means off.
     */
    public static function fiberTimeoutFor(string $uri): int
    {
        $profileDefault = self::profileDefault(Env::text('IGNIS_PROFILE', 'production'));
        $fiberTimeoutMilliseconds = self::unsignedMilliseconds(Env::text('IGNIS_FIBER_TIMEOUT_MS', '')) ?? $profileDefault;
        foreach (self::parseRoutes(Env::text('IGNIS_RECOVERY_ROUTES', '')) as [$prefix, $overrideMilliseconds]) {
            if (\str_starts_with($uri, $prefix)) {
                return $overrideMilliseconds ?? $fiberTimeoutMilliseconds;
            }
        }

        return $fiberTimeoutMilliseconds;
    }
}
