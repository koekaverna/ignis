<?php

declare(strict_types=1);

namespace Ignis\Tests\Temporal;

use Ignis\Temporal\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Worker` trusts a `WorkflowActivation`/`ActivityTask` document only after these checks: every
 * one here is a document sdk-core could genuinely send that this prototype cannot act on, and each
 * used to be a `mixed` indexed as if its shape were guaranteed (PHPStan level 9, S4-MIXED).
 */
#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    private static function reflect(string $method): \ReflectionMethod
    {
        return new \ReflectionMethod(Worker::class, $method);
    }

    // ---- resultOf: a reactor completion is a string result or {message: ...}, nothing else ----

    public function testAnErrorCompletionWithAStringMessageBecomesThatException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporal broke');

        self::reflect('resultOf')->invoke(null, ['message' => 'temporal broke']);
    }

    public function testAnErrorCompletionWithoutAStringMessageFallsBackRatherThanCrashing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporal error');

        self::reflect('resultOf')->invoke(null, ['message' => 42]);
    }

    #[DataProvider('completionsThatAreNeitherAResultNorAnError')]
    public function testACompletionThatIsNeitherAStringNorAnErrorArrayIsRejected(mixed $completion): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporal op returned neither a string result nor an error');

        self::reflect('resultOf')->invoke(null, $completion);
    }

    /** @return iterable<string, array{mixed}> */
    public static function completionsThatAreNeitherAResultNorAnError(): iterable
    {
        yield 'null' => [null];
        yield 'an int' => [7];
        yield 'a bool' => [true];
    }

    // ---- workerId: the connect/replay {"worker": id} document ----

    #[DataProvider('malformedWorkerIdDocuments')]
    public function testAMalformedWorkerIdDocumentIsRejected(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporal connect did not return a "worker" id');

        self::reflect('workerId')->invoke(null, $json);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedWorkerIdDocuments(): iterable
    {
        yield 'not an object' => ['[]'];
        yield 'missing worker' => ['{}'];
        yield 'worker is not an int' => ['{"worker":"seven"}'];
    }

    public function testAWellFormedWorkerIdDocumentIsAccepted(): void
    {
        self::assertSame(7, self::reflect('workerId')->invoke(null, '{"worker":7}'));
    }

    // ---- decodeActivation: the poll's WorkflowActivation envelope ----

    #[DataProvider('malformedActivationDocuments')]
    public function testAMalformedActivationEnvelopeIsRejected(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed WorkflowActivation: "runId" or "jobs" missing');

        self::reflect('decodeActivation')->invoke(null, $json);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedActivationDocuments(): iterable
    {
        yield 'not an object' => ['[]'];
        yield 'missing runId' => ['{"jobs":[]}'];
        yield 'runId is not a string' => ['{"runId":1,"jobs":[]}'];
        yield 'missing jobs' => ['{"runId":"run-1"}'];
        yield 'jobs is not a list' => ['{"runId":"run-1","jobs":{"0":{}, "2":{}}}'];
    }

    // ---- decodeActivityTask: the poll_activity ActivityTask envelope ----

    #[DataProvider('malformedActivityTaskDocuments')]
    public function testAMalformedActivityTaskEnvelopeIsRejected(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed ActivityTask: "taskToken" missing');

        self::reflect('decodeActivityTask')->invoke(null, $json);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedActivityTaskDocuments(): iterable
    {
        yield 'not an object' => ['[]'];
        yield 'missing taskToken' => ['{}'];
        yield 'taskToken is not a string' => ['{"taskToken":1}'];
    }

    // ---- requireActivityType: an activity task's `start` job ----

    #[DataProvider('startsWithoutAnActivityType')]
    public function testAStartWithoutAStringActivityTypeIsRejected(mixed $start): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed ActivityTask: "start.activityType" is not a string');

        self::reflect('requireActivityType')->invoke(null, $start);
    }

    /** @return iterable<string, array{mixed}> */
    public static function startsWithoutAnActivityType(): iterable
    {
        yield 'not an object' => ['not-an-object'];
        yield 'missing activityType' => [[]];
        yield 'activityType is not a string' => [['activityType' => 7]];
    }

    // ---- handleActivation: one job at a time, off the same activation ----

    #[DataProvider('malformedJobs')]
    public function testAMalformedJobFailsTheActivation(mixed $job, string $expectedMessage): void
    {
        $worker = new Worker(1, 'ignis', [], []);
        $act = ['runId' => 'run-1', 'jobs' => [$job]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        self::reflect('handleActivation')->invoke($worker, $act);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedJobs(): iterable
    {
        yield 'the job is not an object' => ['not-an-object', 'malformed WorkflowActivation job: not an object'];
        yield 'the job body is not an object' => [['initializeWorkflow' => 'not-an-object'], 'malformed WorkflowActivation job "initializeWorkflow": not an object'];
        yield 'initializeWorkflow.workflowType is not a string' => [['initializeWorkflow' => ['workflowType' => 7]], 'malformed initializeWorkflow: "workflowType" is not a string'];
        yield 'fireTimer.seq is not an int' => [['fireTimer' => ['seq' => 'one']], 'malformed fireTimer: "seq" is not an int'];
        yield 'resolveActivity.seq is not an int' => [['resolveActivity' => ['seq' => 'one']], 'malformed resolveActivity: "seq" is not an int'];
        yield 'resolveActivity.result is not an object' => [['resolveActivity' => ['seq' => 1, 'result' => 'nope']], 'malformed resolveActivity: "result" is not an object'];
        yield 'resolveActivity.result.completed.result is not an object' => [
            ['resolveActivity' => ['seq' => 1, 'result' => ['completed' => ['result' => 'nope']]]],
            'malformed resolveActivity: "result.completed.result" is not an object',
        ];
    }
}
