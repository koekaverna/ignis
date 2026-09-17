# temporal core transport

Runs the official [temporalio/sdk-php](https://github.com/temporalio/sdk-php) on Temporal's own
**sdk-core** instead of RoadRunner.

PHP is the only Temporal SDK that does not sit on sdk-core — .NET, Ruby and Python all do, which is
what `temporalio-sdk-core-c-bridge` exists for. sdk-php's tie to RoadRunner turns out to be a
default argument rather than a design: `WorkerFactory::run()` takes a `HostConnectionInterface`, and
`$codec` is a protected property. This package supplies both, so an **unmodified** sdk-php worker —
attributes, `Workflow::newActivityStub()`, `yield`, `Workflow::timer()` — is driven by sdk-core
activations.

## What a host has to provide

One interface, two methods:

```php
interface ActivationSource
{
    public function poll(string $kind): ?string;              // JSON WorkflowActivation / ActivityTask
    public function complete(string $kind, string $json): void;
    public function taskQueue(): string;
    public function namespace(): string;
}
```

Anything that can produce sdk-core activations works: an embedded core (this package was written for
[Ignis](https://github.com/koekaverna/ignis), which links sdk-core into its binary), a PHP
extension, sdk-core's C bridge, or a recorded file in a test.

```php
$factory = CoreWorkerFactory::forSource($source);
$worker  = $factory->newWorker($source->taskQueue());
$worker->registerWorkflowTypes(GreetWorkflow::class);
$worker->registerActivityImplementations(new GreetActivity());

$factory->run($factory->host($source, ActivationSource::WORKFLOW));
```

One host serves one kind of task, because `WorkerFactory` accumulates the responses of a dispatch in
its own state: two batches must never overlap inside one factory. That is also RoadRunner's model —
a workflow worker and activity workers are separate there. Concurrency is a pool of factories, one
per activity in flight.

## What it translates

| | |
|---|---|
| activities, timers, completion and failure | yes |
| signals | yes |
| **updates**, including the validator handler | yes — the response carries core's `protocol_instance_id`, which sdk-php never sees, so the transport keeps the mapping |
| **local activities** (`#[LocalActivityInterface]`) | yes — core delivers them on the activity stream with `is_local`, which selects sdk-php's local route |
| **queries** | yes — matched first-in-first-out, because every sdk-php response to a process-aware route carries the *run* id and not a query id (the same thing RoadRunner's protocol does with the same frames) |
| **activity heartbeats** | yes, through `RPCConnectionInterface`; a host opts in with `HeartbeatSink` |
| cancellation of timers, activities, local activities, child workflows | translated, **not yet tested end to end** |
| child workflows | translated, **not yet tested end to end** |
| `SideEffect`, `GetVersion` (patches), `ContinueAsNew`, Nexus, external-workflow signal/cancel | no |

`CoreCodec::encode()` throws by name for anything outside the translated set rather than dropping
it — a dropped command is a workflow that hangs until its task timeout.

## Test

`tests/conformance.php` feeds a recorded script of sdk-core activations to a stock sdk-php worker and
asserts the completions. It needs no Temporal server and no particular host:

```
composer install
php tests/conformance.php
```

## The client

`WorkflowClient` normally reaches Temporal through **ext-grpc**. It does not have to:
`BaseClient::invoke()` funnels all ~95 service methods through the pipeline that
`withInterceptorPipeline()` installs publicly, so one interceptor replaces the whole gRPC client.

```php
$client = WorkflowClient::create(CoreServiceClient::for($yourUnaryCall));
$client->start($client->newUntypedWorkflowStub('GreetWorkflow', $options), 'Ada');
```

`ServiceCall` is the port — one unary call, path in, protobuf bytes out. Two details make it work
without the extension: `ServiceClient::create()` refuses when `ext-grpc` is missing but the
constructor does not, and `Connection` needs a `\Grpc\BaseStub` — which comes from the `grpc/grpc`
*composer* package, so `DetachedStub` simply subclasses it with a constructor that opens nothing.

Not handled: per-call metadata and deadlines from `ContextInterface` are ignored, so API-key
authentication and TLS have to live in the host's own channel.

## The wire

**protojson — core's own documents.** The host hands over a `WorkflowActivation` exactly as core
produced it and takes back a `WorkflowActivationCompletion` exactly as core expects it. There is no
intermediate schema, so every field core has is reachable (retry policies, cancellation types,
headers, memo, search attributes) and a host needs no per-feature code. Oneofs are their flattened
field name, durations are `"5s"`, timestamps RFC3339, bytes base64.

## Notes

Payloads never round-trip through protobuf wire bytes — `Payload` objects are built with setters, so
the `DataConverter` still owns encoding while the 53 µs serialize/parse per payload (pure-PHP
`google/protobuf`) is gone.

MIT.
