# `ignis/temporal`

Runs the official `temporalio/sdk-php` with Temporal's own sdk-core in this process, workflows and
activities as fibers (ADR-0040).

## What it does

The SDK is normally hosted by RoadRunner, which speaks to sdk-core over a Go bridge and a process
pool. Here sdk-core is a Rust crate linked into the runtime, and the SDK talks to it through two
seams it already has — `WorkerFactory::run(?HostConnectionInterface)` and its codec. Workflows run
as **one** workflow fiber (workflow code never really waits, so one is enough and one is required)
plus a pool of activity fibers: RoadRunner's shape with fibers instead of processes.

Nothing is forked from the SDK. Your workflow and activity classes are the ones you already have.

## Install

```
composer require ignis/temporal:@dev temporalio/sdk-php
```

`ignis/temporal` pulls in `ignis/temporal-core-transport` (the host-agnostic half — an
`ActivationSource` and a codec over sdk-core's wire format, written to be offered upstream) and
`ignis/grpc`, which is how the SDK's own `WorkflowClient` reaches the server to start, signal and
query workflows.

## Run a worker

```php
require '/opt/ignis/php/packages/runtime/src/ignis.php';
require '/path/to/your/vendor/autoload.php';          // sdk-php and your workflow classes

$source = Ignis\Temporal\CoreSource::connect(
    getenv('TEMPORAL_URL') ?: 'http://127.0.0.1:7233',
    getenv('TEMPORAL_NAMESPACE') ?: 'default',
    getenv('TEMPORAL_TASK_QUEUE') ?: 'ignis',
);

Ignis\Temporal\serve($source, static function (Temporal\Worker\WorkerInterface $worker): void {
    $worker->registerWorkflowTypes(App\MyWorkflow::class);
    $worker->registerActivityImplementations(new App\MyActivity());
}, (int) (getenv('IGNIS_ACTIVITY_FIBERS') ?: 8));
```

`php/packages/temporal/bin/worker.php` is exactly this file, ready to run:

```
SDKPHP_VENDOR=/path/to/vendor/autoload.php ignis php/packages/temporal/bin/worker.php
```

## Configure

| Variable | Default | What it sets |
|---|---|---|
| `TEMPORAL_URL` | `http://127.0.0.1:7233` | the Temporal server |
| `TEMPORAL_NAMESPACE` | `default` | the namespace |
| `TEMPORAL_TASK_QUEUE` | `ignis` | the task queue this worker polls |
| `IGNIS_ACTIVITY_FIBERS` | `8` | activity fibers, i.e. activities in flight on this thread |
| `SDKPHP_VENDOR` | the package's own vendor | where sdk-php and your classes are autoloaded from |

**The runtime must be built with the feature**: `cargo build --release -p ignis --features temporal`,
because sdk-core is a large dependency and the default binary does not carry it. Threads, the fiber
budget and the listener remain the runtime's ([configuration](../reference/configuration.md)).

## What is guaranteed

E9 runs a workflow with activities against a real Temporal server, then **replays** its history and
asserts the same decisions — with a negative control (`DEMO_MUTATE=1`) that must fail, because a
replay harness that cannot fail proves nothing. The sticky cache is on, so a workflow stays a
suspended fiber between activations rather than being rebuilt by replay: five activations for the
demo instead of fourteen.

## Limits

Signals, queries and workflow cancellation are not covered yet (`R-10`). The replay gate
(`bench/e9-temporal.sh`, `bin/replay.php`) runs on this package: the runtime fetches a run's
history and `CoreSource::replay()` drives the same workflow code through it.
