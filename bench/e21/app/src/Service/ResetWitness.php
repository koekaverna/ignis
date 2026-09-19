<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * A resettable service that says whether it was reset while somebody was still using it.
 *
 * Tagged `kernel.reset` by hand in `Kernel::configureContainer()` — the point is to be in
 * `services_resetter`'s list, which is what `Kernel::boot()` empties at the start of the next
 * request. Nothing else about this service matters.
 */
final class ResetWitness implements ResetInterface
{
    private ?string $value = null;

    public function remember(string $value): void
    {
        $this->value = $value;
    }

    public function recall(): ?string
    {
        return $this->value;
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
