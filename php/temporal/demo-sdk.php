<?php

/**
 * The demo workflow for the core transport — deliberately **stock temporalio/sdk-php**: attributes,
 * an activity stub, `yield`, `Workflow::timer()`. Nothing here knows it is running on Ignis, which
 * is the entire claim of ADR-0040. Used by both `core/selftest.php` and `sdk-worker.php`, so the
 * test and the example cannot drift apart.
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
    #[WorkflowMethod]
    public function handle(string $name)
    {
        $stub = Workflow::newActivityStub(
            GreetActivity::class,
            ActivityOptions::new()->withStartToCloseTimeout(5),
        );
        $greeting = yield $stub->greet($name);
        yield Workflow::timer(1);

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
