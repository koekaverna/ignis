<?php

/**
 * sdk-php's `WorkflowClient` without ext-grpc.
 *
 * `BaseClient::invoke()` funnels **every** one of the service's ~95 methods through
 * `$invokePipeline`, which `withInterceptorPipeline()` installs publicly. An interceptor that
 * answers instead of calling `$next` therefore replaces the whole gRPC client with one method —
 * the same shape as the worker side, where one `HostConnectionInterface` replaces RoadRunner.
 *
 * Two details make it work in a build with no gRPC extension:
 *
 *   - `ServiceClient::create()` refuses outright when `ext-grpc` is missing, but the constructor
 *     takes a stub factory, so it is the way in.
 *   - `Connection` builds the stub eagerly and its property is typed `\Grpc\BaseStub`. That class
 *     comes from the `grpc/grpc` composer package (not the extension), so it can simply be
 *     subclassed with a constructor that opens no channel. `DetachedStub` is never used for
 *     anything: the interceptor answers before a request could reach it.
 *
 * Not handled, and stated rather than faked: per-call metadata and deadlines from
 * `ContextInterface` are ignored, so API-key authentication and TLS options do not reach the host.
 * For Temporal Cloud the host's own channel has to carry them.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Interceptor\GrpcClientInterceptor;
use Temporal\Internal\Interceptor\Pipeline;

final class CoreServiceClient implements GrpcClientInterceptor
{
    public const PATH = '/temporal.api.workflowservice.v1.WorkflowService/';

    public function __construct(private readonly ServiceCall $call) {}

    /** A `ServiceClient` whose every call goes to `$call` instead of a gRPC channel. */
    public static function for(ServiceCall $call): ServiceClientInterface
    {
        return (new ServiceClient(static fn(): \Grpc\BaseStub => new DetachedStub()))
            ->withInterceptorPipeline(Pipeline::prepare([new self($call)]));
    }

    public function interceptCall(string $method, object $arg, ContextInterface $ctx, callable $next): object
    {
        $response = $this->responseFor($arg::class, $method);
        $response->mergeFromString($this->call->call(self::PATH . $method, $arg->serializeToString()));

        return $response;
    }

    /**
     * Every WorkflowService method pairs `<Name>Request` with `<Name>Response`, so the reply type
     * follows from the request type. A method that ever breaks the convention fails by name here
     * rather than returning something plausible.
     */
    private function responseFor(string $requestClass, string $method): object
    {
        $responseClass = \str_ends_with($requestClass, 'Request')
            ? \substr($requestClass, 0, -7) . 'Response'
            : null;

        if ($responseClass === null || !\class_exists($responseClass)) {
            throw new \LogicException(\sprintf(
                'cannot infer the response type of "%s" from %s; this transport needs an explicit mapping for it',
                $method,
                $requestClass,
            ));
        }

        return new $responseClass();
    }
}
