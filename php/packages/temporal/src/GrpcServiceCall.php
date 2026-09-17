<?php

/**
 * The Ignis half of the client transport (ADR-0040): sdk-php's `WorkflowClient` over the runtime's
 * own HTTP/2 channels instead of ext-grpc.
 *
 * There is no new Rust for this. `ignis_grpc_call()` has been a generic unary call by path since
 * E10 (ADR-0014, an opaque-bytes codec and no codegen), and `Ignis\Grpc\Client` already wraps it
 * with status handling — so starting or signalling a workflow parks the calling fiber exactly like
 * any other reactor op, and works in a build without `--features temporal`.
 */

declare(strict_types=1);

namespace Ignis\Temporal;

use Ignis\Grpc\Client;
use Temporal\Worker\Transport\Core\CoreServiceClient;
use Temporal\Worker\Transport\Core\ServiceCall;

final class GrpcServiceCall implements ServiceCall
{
    private readonly Client $client;

    public function __construct(string $url = 'http://127.0.0.1:7233')
    {
        $this->client = new Client($url);
    }

    public function call(string $path, string $request): string
    {
        return $this->client->unary($path, $request);
    }

    /** The stock `WorkflowClient`, talking to Temporal through the runtime. */
    public static function workflowClient(string $url = 'http://127.0.0.1:7233'): \Temporal\Client\WorkflowClient
    {
        return \Temporal\Client\WorkflowClient::create(CoreServiceClient::for(new self($url)));
    }
}
