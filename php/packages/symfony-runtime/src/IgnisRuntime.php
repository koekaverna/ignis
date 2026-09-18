<?php

/** symfony/runtime adapter: worker mode on Ignis (ADR-0011). Select with APP_RUNTIME=Ignis\Symfony\IgnisRuntime. */
declare(strict_types=1);

namespace Ignis\Symfony;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

final class IgnisRuntime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof HttpKernelInterface && \function_exists('ignis_serve')) {
            return new IgnisWorkerRunner($application, $this->options['ignis_listen'] ?? (getenv('IGNIS_LISTEN') ?: '127.0.0.1:8080'));
        }
        return parent::getRunner($application);
    }
}
