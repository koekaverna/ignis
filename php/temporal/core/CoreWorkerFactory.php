<?php

/**
 * `WorkerFactory` with the codec swapped. `createCodec()` is private and picks Json/Proto from
 * `$_SERVER['RR_CODEC']`, but `$codec` is a protected property, so a subclass can install its own
 * after construction — which is the whole reason this transport needs no fork of sdk-php.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

use Temporal\DataConverter\DataConverter;
use Temporal\WorkerFactory;

final class CoreWorkerFactory extends WorkerFactory
{
    private CoreCodec $coreCodec;

    public static function forSource(ActivationSource $source): self
    {
        /** @var self $factory */
        $factory = self::create(DataConverter::createDefault());
        $factory->coreCodec = new CoreCodec($factory->converter, $source->taskQueue(), $source->namespace());
        $factory->codec = $factory->coreCodec;

        return $factory;
    }

    public function host(ActivationSource $source, string $kind = ActivationSource::WORKFLOW): CoreHost
    {
        return new CoreHost($source, $this->coreCodec, $kind);
    }
}
