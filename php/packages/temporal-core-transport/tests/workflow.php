<?php

/**
 * The demo workflow for the core transport — deliberately **stock temporalio/sdk-php**: attributes,
 * an activity stub, `yield`, `Workflow::timer()`. Nothing here knows it is running on Ignis, which
 * is the entire claim of ADR-0040. Shared by the conformance test, the worker and the replay gate,
 * so they cannot drift apart. `DEMO_MUTATE=1` drops the timer: the replay negative control must
 * then fail with a nondeterminism eviction (E9, V-19).
 */

declare(strict_types=1);

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
class GreetWorkflow
{
    /** @return \Generator<mixed, mixed, string, string> */
    #[WorkflowMethod]
    public function handle(string $name): \Generator
    {
        $stub = Workflow::newActivityStub(
            GreetActivity::class,
            ActivityOptions::new()->withStartToCloseTimeout(5),
        );
        $greeting = yield $stub->greet($name);
        if (\getenv('DEMO_MUTATE') === false) {
            yield Workflow::timer(1);
        }

        return \strtoupper($greeting);
    }
}

#[ActivityInterface]
class GreetActivity
{
    #[ActivityMethod]
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}

/**
 * The second fixture covers what a real workflow actually reaches for beyond activities and timers:
 * an update with a validator, a **local** activity, a query, and a heartbeat from a plain activity.
 * Still stock sdk-php — the point is that none of it knows about the transport.
 */
#[WorkflowInterface]
class FeatureWorkflow
{
    private string $state = 'new';
    private ?string $result = null;

    /** @return \Generator<mixed, mixed, mixed, string|null> */
    #[WorkflowMethod]
    public function handle(): \Generator
    {
        yield Workflow::await(fn(): bool => $this->result !== null);

        return $this->result;
    }

    #[Workflow\QueryMethod('state')]
    public function state(): string
    {
        return $this->state;
    }

    /** @return \Generator<mixed, mixed, string, string> */
    #[Workflow\UpdateMethod('submit')]
    public function submit(string $value): \Generator
    {
        $projection = Workflow::newActivityStub(
            ProjectionActivity::class,
            \Temporal\Activity\LocalActivityOptions::new()->withStartToCloseTimeout(5),
        );
        $started = yield $projection->jobStarted($value);
        $this->state = $started;
        $this->result = "ok:{$value}";

        return $this->result;
    }

    #[Workflow\UpdateValidatorMethod('submit')]
    public function validateSubmit(string $value): void
    {
        $value === '' and throw new \InvalidArgumentException('value must not be empty');
    }
}

#[\Temporal\Activity\LocalActivityInterface(prefix: 'projection.')]
class ProjectionActivity
{
    #[ActivityMethod]
    public function jobStarted(string $value): string
    {
        return "started:{$value}";
    }
}

#[ActivityInterface]
class HeartbeatActivity
{
    #[ActivityMethod]
    public function work(string $value): string
    {
        \Temporal\Activity::getCurrentContext()->heartbeat(['at' => $value]);

        return "worked:{$value}";
    }
}
