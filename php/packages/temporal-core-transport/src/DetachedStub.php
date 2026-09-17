<?php

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

/**
 * A `\Grpc\BaseStub` that opens nothing. It exists only to satisfy `Connection`'s typed property;
 * every call is answered by the interceptor above, so none of this is ever reached.
 */
final class DetachedStub extends \Grpc\BaseStub
{
    public function __construct()
    {
        // deliberately does not call the parent: no channel, no extension, no connection
    }

    public function getConnectivityState($try_to_connect = false)
    {
        return 2;   // READY — nothing to connect
    }

    public function close() {}
}
