<?php

declare(strict_types=1);

namespace Ignis\Tests\Offload;

use Ignis\Loop;
use Ignis\Offload\CallbackRef;
use Ignis\Offload\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fake-offload.php';

/**
 * The caller side of an offload job: the closures `Client::call()` lifts out of the arguments, and
 * how long it keeps them. A call parks on one op, so a plain `Fiber` started and resumed by the
 * test is the whole scheduler this needs — the pool behind the op is E16's against the real binary.
 */
#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        FakeOffload::reset();
        self::set('pending', []);
        self::set('nextCallbackId', 1);
        self::skipLoopBoot();
    }

    protected function tearDown(): void
    {
        self::set('pending', []);
        self::resetLoop();
    }

    public function testEveryClosureAmongTheArgumentsBecomesACallbackRef(): void
    {
        $onRow = static fn(string $row): string => $row;
        $onEnd = static fn(): null => null;

        $sent = self::extract(['SELECT 1', ['on' => ['row' => $onRow, 'end' => $onEnd]], 7], $callbacks);

        self::assertSame('SELECT 1', $sent[0]);
        self::assertSame(7, $sent[2]);
        self::assertIsArray($sent[1]);
        self::assertIsArray($sent[1]['on']);
        self::assertInstanceOf(CallbackRef::class, $sent[1]['on']['row'], 'a closure cannot be serialized, so it travels as a handle');
        self::assertInstanceOf(CallbackRef::class, $sent[1]['on']['end']);
        self::assertSame([1, 2], [$sent[1]['on']['row']->id, $sent[1]['on']['end']->id], 'ids are handed out in traversal order');
        self::assertSame([1 => $onRow, 2 => $onEnd], $callbacks, 'and the caller keeps the real closures for the run of this job');
        self::assertEquals($sent, unserialize(serialize($sent)), 'whatever comes back has to survive the round trip to the worker');
    }

    public function testArgumentsWithNoClosureInThemAreHandedOverUnchanged(): void
    {
        $arguments = ['a', 1, null, true, ['nested' => ['deep' => 'value']]];

        self::assertSame($arguments, self::extract($arguments, $callbacks));
        self::assertSame([], $callbacks);
    }

    /**
     * A closure handed to a worker has to stay reachable exactly as long as the job that may call
     * it. `Client::$pending` is the map the callback handler reads, so it is where holding on too
     * long would be invisible — one closure, plus everything it captured, per offload call, for the
     * life of the process, against a runtime whose claim (E3/V-10) is flat RSS.
     */
    public function testAFinishedCallKeepsNoneOfTheClosuresItLiftedOut(): void
    {
        $payload = str_repeat('x', 1024);
        $job = new \Fiber(static fn(): mixed => Client::call('work', [static fn(): string => $payload]));
        $job->start();

        $pending = self::get('pending');
        self::assertIsArray($pending);
        self::assertCount(1, $pending, 'the worker may call back for as long as the job runs');

        $job->resume(serialize(['ok' => 'done']));

        self::assertSame('done', $job->getReturn());
        self::assertSame([], self::get('pending'), 'and the closure goes the moment the answer is in');
    }

    public function testAFailedCallDropsItsClosuresToo(): void
    {
        $job = new \Fiber(static fn(): mixed => Client::call('work', [static fn(): string => 'row']));
        $job->start();

        try {
            $job->throw(new \RuntimeException('client went away'));
            self::fail('the throw has to come out of Client::call()');
        } catch (\RuntimeException $e) {
            self::assertSame('client went away', $e->getMessage());
        }

        self::assertSame([], self::get('pending'));
    }

    public function testACallWithNoWorkerPoolBehindItSaysWhatToDo(): void
    {
        FakeOffload::$noPool = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: no pool (start ignis with --offload N)');

        Client::call('work', []);
    }

    public function testStatsComeStraightFromTheRuntime(): void
    {
        self::assertSame(['workers' => 2, 'this' => 0], Client::stats());
    }

    public function testAnAnswerThatIsNeitherAnErrorNorAStringIsRejected(): void
    {
        $job = new \Fiber(static fn(): mixed => Client::call('work', []));
        $job->start();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: malformed completion payload');
        $job->resume(42);
    }

    public function testAnErrorCompletionWithNoMessageStillNamesItselfAnError(): void
    {
        $job = new \Fiber(static fn(): mixed => Client::call('work', []));
        $job->start();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: unknown error');
        $job->resume(['kind' => 'error']);
    }

    public function testAResultThatDoesNotUnserializeToAnArrayIsRejected(): void
    {
        $job = new \Fiber(static fn(): mixed => Client::call('work', []));
        $job->start();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: malformed result payload');
        $job->resume(serialize('not an envelope'));
    }

    public function testARemoteErrorThatIsNotAThreeElementArrayIsRejected(): void
    {
        $job = new \Fiber(static fn(): mixed => Client::call('work', []));
        $job->start();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: malformed error payload');
        $job->resume(serialize(['err' => 'boom']));
    }

    public function testARemoteErrorWhoseClassIsNotAStringIsRejected(): void
    {
        $job = new \Fiber(static fn(): mixed => Client::call('work', []));
        $job->start();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: malformed error payload');
        $job->resume(serialize(['err' => [42, 'bad row', 7]]));
    }

    public function testACallbackEnvelopeWithNoIntJobOrSequenceThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: malformed callback envelope');

        self::runCallback(['job' => 'not-an-id', 'seq' => 1, 'cb' => 1, 'args' => serialize([])]);
    }

    public function testACallbackPayloadWithAMalformedCallbackIdAnswersWithAnError(): void
    {
        self::runCallback(['job' => 1, 'seq' => 2, 'cb' => 'not-an-id', 'args' => serialize([])]);

        self::assertSame('offload: malformed callback payload', self::lastCallbackErrorMessage());
    }

    public function testCallbackArgumentsThatDoNotUnserializeToAnArrayAnswerWithAnError(): void
    {
        self::set('pending', [5 => static fn(): null => null]);

        self::runCallback(['job' => 1, 'seq' => 2, 'cb' => 5, 'args' => serialize('not-a-list')]);

        self::assertSame('offload: malformed callback arguments', self::lastCallbackErrorMessage());
    }

    private static function lastCallbackErrorMessage(): string
    {
        $result = unserialize(FakeOffload::$callbackResults[0]['result']);
        if (!\is_array($result) || !\is_array($result['err'] ?? null) || !\is_string($result['err'][1] ?? null)) {
            self::fail('the callback result did not carry the expected error envelope');
        }
        return $result['err'][1];
    }

    /** @param array<string, mixed> $payload */
    private static function runCallback(array $payload): void
    {
        (new \ReflectionMethod(Client::class, 'runCallback'))->invoke(null, $payload);
    }

    /** @param list<mixed> $arguments */
    /**
     * The job as it would be sent to a worker. Narrowed here because every caller indexes it.
     *
     * @param  array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     */
    private static function extract(array $arguments, mixed &$callbacks): array
    {
        $callbacks = [];
        $sent = (new \ReflectionMethod(Client::class, 'extractCallbacks'))->invokeArgs(null, [$arguments, &$callbacks]);
        self::assertIsArray($sent);

        return $sent;
    }

    private static function set(string $property, mixed $value): void
    {
        (new \ReflectionProperty(Client::class, $property))->setValue(null, $value);
    }

    /**
     * `Client::call()` parks on `Ignis\Loop`, which is static state in another package. Marking it
     * booted keeps `Loop::boot()` — and the process-wide `gc_disable()` in its GC init — out of
     * this suite; the declared defaults go back afterwards, so the next test file starts where it
     * would have without this one.
     */
    private static function skipLoopBoot(): void
    {
        (new \ReflectionProperty(Loop::class, 'booted'))->setValue(null, true);
    }

    private static function resetLoop(): void
    {
        $loop = new \ReflectionClass(Loop::class);
        foreach ($loop->getDefaultProperties() as $name => $default) {
            $loop->getProperty($name)->setValue(null, $default);
        }
    }

    private static function get(string $property): mixed
    {
        return (new \ReflectionProperty(Client::class, $property))->getValue();
    }
}
