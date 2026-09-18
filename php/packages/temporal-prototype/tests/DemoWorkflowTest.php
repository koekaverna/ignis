<?php

declare(strict_types=1);

namespace Ignis\Tests\Temporal;

use Ignis\Temporal\Payloads;
use Ignis\Temporal\Worker;
use PHPUnit\Framework\TestCase;

/**
 * The `Demo` workflow (`src/demo.php`) trusts an activity's decoded result to be a string only
 * after checking it: the result travels as a JSON payload off the wire (protojson `resolveActivity`),
 * so a peer reporting something other than what the real `shout` activity would have returned must
 * fail the workflow, not crash the worker.
 */
final class DemoWorkflowTest extends TestCase
{
    /** DEMO_MUTATE=1 skips the timer, so greet -> shout is the whole run. */
    protected function setUp(): void
    {
        putenv('DEMO_MUTATE=1');
    }

    protected function tearDown(): void
    {
        putenv('DEMO_MUTATE');
    }

    public function testAnActivityResultThatIsNotAStringFailsTheWorkflowInsteadOfCrashingTheWorker(): void
    {
        $app = require __DIR__ . '/../src/demo.php';
        $worker = new Worker(1, 'ignis', $app['workflows'], $app['activities']);
        $handleActivation = new \ReflectionMethod(Worker::class, 'handleActivation');

        $started = $handleActivation->invoke($worker, [
            'runId' => 'run-1',
            'jobs' => [['initializeWorkflow' => ['workflowType' => 'Demo', 'arguments' => [Payloads::encode('world')]]]],
        ]);
        self::assertSame('greet', self::firstScheduledActivity($started));

        $greeted = $handleActivation->invoke($worker, [
            'runId' => 'run-1',
            'jobs' => [['resolveActivity' => ['seq' => 1, 'result' => ['completed' => ['result' => Payloads::encode('hello world')]]]]],
        ]);
        self::assertSame('shout', self::firstScheduledActivity($greeted));

        $shouted = $handleActivation->invoke($worker, [
            'runId' => 'run-1',
            'jobs' => [['resolveActivity' => ['seq' => 2, 'result' => ['completed' => ['result' => Payloads::encode(42)]]]]],
        ]);

        self::assertStringContainsString('activity "shout" must return a string, got int', self::failureMessage($shouted));
    }

    private static function firstScheduledActivity(mixed $completion): string
    {
        $command = self::commandsOf($completion)[0] ?? null;
        $activityType = is_array($command) && is_array($command['scheduleActivity'] ?? null) ? $command['scheduleActivity']['activityType'] ?? null : null;
        if (!is_string($activityType)) {
            throw new \RuntimeException('completion has no scheduleActivity command');
        }
        return $activityType;
    }

    private static function failureMessage(mixed $completion): string
    {
        $command = self::commandsOf($completion)[0] ?? null;
        $failure = is_array($command) && is_array($command['failWorkflowExecution'] ?? null) ? $command['failWorkflowExecution']['failure'] ?? null : null;
        $message = is_array($failure) ? $failure['message'] ?? null : null;
        if (!is_string($message)) {
            throw new \RuntimeException('completion has no failWorkflowExecution command');
        }
        return $message;
    }

    /** @return list<mixed> */
    private static function commandsOf(mixed $completion): array
    {
        $commands = is_array($completion) && is_array($completion['successful'] ?? null) ? $completion['successful']['commands'] ?? null : null;
        if (!is_array($commands)) {
            throw new \RuntimeException('completion has no successful.commands');
        }
        return array_values($commands);
    }
}
