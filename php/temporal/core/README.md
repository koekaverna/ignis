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

## What it does not translate yet

Queries, updates, cancellation, child workflows, local activities, heartbeats, `SideEffect`,
`GetVersion`. `CoreCodec::encode()` throws by name for anything outside the translated set rather
than dropping it — a dropped command is a workflow that hangs until its task timeout.

## Test

`tests/conformance.php` feeds a recorded script of sdk-core activations to a stock sdk-php worker and
asserts the completions. It needs no Temporal server and no particular host:

```
composer install
php tests/conformance.php
```

## Notes

Payloads never round-trip through protobuf wire bytes — `Payload` objects are built with setters, so
the `DataConverter` still owns encoding while the 53 µs serialize/parse per payload (pure-PHP
`google/protobuf`) is gone.

MIT.
