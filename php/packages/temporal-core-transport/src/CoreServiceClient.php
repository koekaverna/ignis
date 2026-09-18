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

use Google\Protobuf\Internal\Message;
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
            ->withInterceptorPipeline(self::pipelineOf([new self($call)]));
    }

    /**
     * `Pipeline::prepare()` infers its interceptor type from what it is handed, and `BaseClient`
     * wants a pipeline of `GrpcClientInterceptor` rather than one of this class — so the element
     * type is stated here instead of being inferred at the call.
     *
     * `Pipeline::prepare()` itself always returns `self<T, mixed>` — its second template parameter
     * is never inferred from the interceptor type — so that is the only return this method can
     * honestly promise too.
     *
     * @param  list<GrpcClientInterceptor>             $interceptors
     * @return Pipeline<GrpcClientInterceptor, mixed>
     */
    private static function pipelineOf(array $interceptors): Pipeline
    {
        return Pipeline::prepare($interceptors);
    }

    public function interceptCall(string $method, object $arg, ContextInterface $ctx, callable $next): object
    {
        $request = $this->requestOf($arg, $method);
        $response = $this->responseFor($request::class, $method);
        $response->mergeFromString($this->call->call(self::PATH . $method, $request->serializeToString()));

        return $response;
    }

    /**
     * `GrpcClientInterceptor` types the argument `object`, but `ServiceClient`'s ~95 generated
     * methods all hand `invoke()` a protobuf message, and the host is given bytes — so anything
     * else cannot be serialised at all and says so here rather than at `serializeToString()`.
     */
    private function requestOf(object $arg, string $method): Message
    {
        if (!$arg instanceof Message) {
            throw new \LogicException(\sprintf(
                'the request of "%s" is a %s, which is not a protobuf message; this transport can only send protobuf',
                $method,
                $arg::class,
            ));
        }

        return $arg;
    }

    /**
     * Every WorkflowService method pairs `<Name>Request` with `<Name>Response`, so the reply type
     * follows from the request type. A method that ever breaks the convention fails by name here
     * rather than returning something plausible.
     */
    private function responseFor(string $requestClass, string $method): Message
    {
        $responseClass = \str_ends_with($requestClass, 'Request')
            ? \substr($requestClass, 0, -7) . 'Response'
            : null;

        if ($responseClass === null || !\is_subclass_of($responseClass, Message::class)) {
            throw new \LogicException(\sprintf(
                'cannot infer the response type of "%s" from %s; this transport needs an explicit mapping for it',
                $method,
                $requestClass,
            ));
        }

        return new $responseClass();
    }
}
