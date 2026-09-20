<?php

declare(strict_types=1);

namespace Ignis;

/**
 * One answer to "what does this environment variable say", because `Loop` had four.
 *
 * `ignis.toml` is parsed on the Rust side and exported as environment variables (`config.rs`'s
 * `default_env`), so reading the environment here is the documented transport and not a shortcut.
 * What was a shortcut is how it was read: five call sites, each with its own idea of what "on"
 * means — `$value !== false && $value !== '' && $value !== '0'` for chaos and for watching,
 * `$value === false || (...)` for loop GC, bare `is_numeric` with a floor for the budget, and a
 * fourth shape for the exempt list. A knob whose truthiness is spelled out at its call site is a
 * knob nobody can test and everybody can get subtly wrong.
 */
final class Env
{
    /**
     * Set, non-empty and not `"0"` is on; unset leaves $default, which is how a knob that ships
     * enabled (`IGNIS_LOOP_GC`) differs from one that ships off (`IGNIS_CHAOS`).
     */
    public static function flag(string $name, bool $default = false): bool
    {
        $value = getenv($name);

        return $value === false ? $default : ($value !== '' && $value !== '0');
    }

    /** A whole number, floored at $minimum. Anything unset or non-numeric leaves $default alone. */
    public static function integer(string $name, int $default, int $minimum = 0): int
    {
        $value = getenv($name);

        return $value !== false && is_numeric($value) ? max($minimum, (int) $value) : $default;
    }

    /** Clamped into [$minimum, $maximum]. Anything unset or non-numeric leaves $default alone. */
    public static function number(string $name, float $default, float $minimum, float $maximum): float
    {
        $value = getenv($name);

        return $value !== false && is_numeric($value) ? max($minimum, min($maximum, (float) $value)) : $default;
    }

    /**
     * Comma separated, trimmed, empty entries dropped. Unset or empty leaves $default alone.
     *
     * @param  list<string> $default
     * @return list<string>
     */
    public static function commaList(string $name, array $default): array
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /** The raw value, or $default when it is unset. Empty is a value, not an absence. */
    public static function text(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
