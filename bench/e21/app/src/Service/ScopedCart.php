<?php
declare(strict_types=1);

namespace App\Service;

/**
 * An ordinary application service with ordinary per-request state — nothing about this class knows
 * it will be scoped. That is the point of ADR-0042: the container marks the definition, the class
 * stays a plain class, and the same code is correct either way.
 *
 * Unmarked it is a container singleton, so two overlapping requests share `$tag` and the second
 * overwrites the first: that is the control this probe needs, and it must leak.
 */
final class ScopedCart
{
    private ?string $tag = null;

    public function __construct(private readonly string $builtWith) {}

    public function remember(string $tag): void
    {
        $this->tag = $tag;
    }

    public function recall(): ?string
    {
        return $this->tag;
    }

    /** A constructor value: process-wide by the rule, and every scope must still read it. */
    public function builtWith(): string
    {
        return $this->builtWith;
    }
}
