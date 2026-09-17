<?php

/**
 * What the client half needs from its host: one unary gRPC call.
 *
 * The same shape as `ActivationSource` and for the same reason — the translation is portable, the
 * transport is not. sdk-php's `WorkflowClient` normally talks to the Temporal service through
 * ext-grpc; a host that already owns an HTTP/2 stack (Ignis does) can answer this instead and the
 * extension is never needed.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

interface ServiceCall
{
    /**
     * @param string $path full gRPC path, e.g. `/temporal.api.workflowservice.v1.WorkflowService/StartWorkflowExecution`
     * @param string $request serialised protobuf request
     * @return string serialised protobuf response
     * @throws \Throwable on a transport or status error; sdk-php turns it into a ServiceClientException
     */
    public function call(string $path, string $request): string;
}
