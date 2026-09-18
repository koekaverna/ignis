<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\CancelledException;
use Ignis\Http\Request;
use Ignis\Http\Response;
use Ignis\Http\StreamedResponse;
use Ignis\Loop;
use Ignis\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/LoopTestCase.php';

/**
 * `Ignis\Loop` — 645 lines of scheduler that had no unit test. Everything here runs on
 * `tests/fake-reactor.php`: a simulated clock, an injectable completion queue and a recorded
 * response map. The E-suites against the real binary remain the contract; this covers the
 * bookkeeping that has no reactor in it at all — the fiber pool, admission control (ADR-0019),
 * deadlines, cancellation (ADR-0009) and the response-dispatch contract.
 */
#[CoversClass(Loop::class)]
final class LoopTest extends LoopTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Scope::clear();   // {main} has no fiber, so Scope falls back to one static array
    }

    // ---- fiber pool ------------------------------------------------------------------------

    public function testAParkedFiberIsReusedInsteadOfCreated(): void
    {
        Loop::spawn(static fn(): int => 1);
        Loop::run();

        self::assertSame(1, Loop::$fibersCreated);
        self::assertSame(1, Loop::idleFibers());

        Loop::spawn(static fn(): int => 2);
        Loop::run();

        self::assertSame(1, Loop::$fibersCreated, 'the parked fiber took the second job; this reuse is V-4, the biggest win of the project');
        self::assertSame(1, Loop::idleFibers());
        self::assertSame(2, Loop::$resumes, 'one start, one resume');
    }

    public function testConcurrentJobsEachGetAFiberAndAllOfThemComeBackIdle(): void
    {
        $futures = [];
        for ($i = 0; $i < 3; $i++) {
            $futures[] = Loop::spawn(static function () use ($i): string {
                \Ignis\sleep(100);

                return 'job' . $i;
            });
        }
        Loop::run();

        self::assertSame(3, Loop::$fibersCreated);
        self::assertSame(3, Loop::idleFibers());
        self::assertSame(100, FakeReactor::clockMilliseconds(), 'three 100 ms sleeps settle together, not one after another');
        self::assertSame(['job0', 'job1', 'job2'], array_map(static fn($f) => $f->await(), $futures));

        Loop::spawn(static fn(): null => null);
        Loop::run();
        self::assertSame(3, Loop::$fibersCreated, 'a fourth job reuses one of the three');
    }

    public function testARejectedJobStillReturnsItsFiberToThePool(): void
    {
        Loop::spawn(static function (): never {
            throw new \RuntimeException('nope');
        });

        try {
            Loop::run();
            self::fail('runUntil() must report a rejection nobody awaited');
        } catch (\RuntimeException $e) {
            self::assertSame('nope', $e->getMessage());
        }

        self::assertSame(1, Loop::idleFibers(), 'poolBody() catches, so the fiber survives its job');
        self::assertSame(1, Loop::$fibersCreated);
    }

    // ---- admission control (ADR-0019) ------------------------------------------------------

    public function testRequestsAreAdmittedUpToTheBudgetAndThenQueuedAsData(): void
    {
        self::budget(2);
        self::set('requestHandler', static fn(Request $r): Response => Response::text('x'));

        self::call('dispatchRequest', 1, self::rawRequest());
        self::call('dispatchRequest', 2, self::rawRequest());
        self::call('dispatchRequest', 3, self::rawRequest());

        self::assertSame(2, Loop::$inflightRequests);
        self::assertSame(1, self::queued(), 'the third waits as the raw array, never as a fiber (V-37)');
        self::assertSame(2, Loop::$fibersCreated, 'and no fiber was made for it');
        self::assertSame([], FakeReactor::responses());
    }

    public function testOverTheQueueDepthARequestIsShedWith503(): void
    {
        self::budget(1, 1);
        self::set('requestHandler', static fn(Request $r): Response => Response::text('x'));

        self::call('dispatchRequest', 1, self::rawRequest());   // admitted
        self::call('dispatchRequest', 2, self::rawRequest());   // queued
        self::call('dispatchRequest', 3, self::rawRequest());   // shed

        self::assertSame(1, Loop::$rejected);
        self::assertSame(
            [['id' => 3, 'status' => 503, 'headers' => ['retry-after' => '1'], 'body' => "503 busy\n"]],
            FakeReactor::responses(),
            'shedding answers immediately: a client that is told to come back costs nothing to hold',
        );
    }

    public function testAnExemptPrefixBypassesTheBudget(): void
    {
        self::budget(1);
        self::set('budgetExempt', ['/_ignis/']);
        self::set('requestHandler', static fn(Request $r): Response => Response::text('x'));

        self::call('dispatchRequest', 1, self::rawRequest('/work'));
        self::call('dispatchRequest', 2, self::rawRequest('/_ignis/metrics'));

        self::assertSame(2, Loop::$inflightRequests, 'a saturated server is exactly when its metrics matter');
        self::assertSame(0, self::queued());
    }

    public function testTheBudgetTheDepthAndTheExemptionsComeFromTheEnvironment(): void
    {
        $before = [
            'IGNIS_FIBER_BUDGET' => getenv('IGNIS_FIBER_BUDGET'),
            'IGNIS_QUEUE_DEPTH' => getenv('IGNIS_QUEUE_DEPTH'),
            'IGNIS_BUDGET_EXEMPT' => getenv('IGNIS_BUDGET_EXEMPT'),
        ];

        try {
            putenv('IGNIS_FIBER_BUDGET=3');
            putenv('IGNIS_QUEUE_DEPTH=4');
            putenv('IGNIS_BUDGET_EXEMPT= /_ignis/ , /health ,');
            self::call('budgetInit');

            self::assertSame(3, Loop::$fiberBudget);
            self::assertSame(4, Loop::$queueDepth);
            self::assertSame(['/_ignis/', '/health'], self::get('budgetExempt'), 'trimmed, and the trailing empty entry dropped');

            putenv('IGNIS_FIBER_BUDGET=-7');
            self::call('budgetInit');
            self::assertSame(0, Loop::$fiberBudget, 'a negative budget clamps to unlimited, not to a permanent 503');
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    public function testTheQueueIsFifoAndCompactsOnceItEmpties(): void
    {
        $seen = [];
        self::budget(1);
        self::set('requestHandler', static function (Request $r) use (&$seen): Response {
            $seen[] = $r->id;

            return Response::text((string) $r->id);
        });

        foreach ([11, 12, 13] as $id) {
            FakeReactor::inject($id, self::rawRequest('/r' . $id));
        }
        self::drive();

        self::assertSame([11, 12, 13], $seen, 'first in, first served');
        self::assertSame([11, 12, 13], array_column(FakeReactor::responses(), 'id'));
        self::assertSame([], self::get('requestQueue'), 'the FIFO is compacted, not left growing by index');
        self::assertSame(0, self::get('queueHead'));
    }

    public function testTheQueueCountersAreWhatMetricsReport(): void
    {
        self::budget(1);
        self::set('requestHandler', static fn(Request $r): Response => Response::text('x'));

        foreach ([11, 12, 13] as $id) {
            FakeReactor::inject($id, self::rawRequest());
        }
        self::drive();

        self::assertSame(2, Loop::$queuedPeak, 'two were waiting at once');
        self::assertSame(2, Loop::$admittedAfterQueue);
        self::assertSame(0, Loop::$rejected);
        self::assertSame(3, Loop::$handled);
        self::assertSame(0, Loop::$inflightRequests);
        self::assertSame(
            ['budget' => 1, 'queue_depth' => 0, 'inflight' => 0, 'queued' => 0, 'queued_peak' => 2, 'queued_admitted' => 2, 'rejected' => 0],
            Loop::budgetStats(),
        );
    }

    public function testARequestCancelledWhileStillQueuedIsNeverAdmitted(): void
    {
        self::budget(1);
        self::set('requestHandler', static fn(Request $r): Response => Response::text('x'));

        self::call('dispatchRequest', 1, self::rawRequest());
        self::call('dispatchRequest', 2, self::rawRequest());
        self::assertSame(1, self::queued());

        self::call('cancelRequest', 2, new CancelledException('client disconnected'), 1500);
        self::assertSame([2 => true], self::get('queueCancelled'));

        Loop::$inflightRequests = 0;
        self::call('drainQueue');

        self::assertSame(0, Loop::$admittedAfterQueue, 'no fiber is ever spent on a client that already left');
        self::assertSame(0, Loop::$inflightRequests);
        self::assertSame([], self::get('queueCancelled'));
        self::assertSame([], FakeReactor::responses());
        self::assertSame(1500, Loop::$cancelAgeUsMax);
    }

    // ---- deadlines (E11) -------------------------------------------------------------------

    public function testDeadlineOutsideARequestIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Ignis\deadline() must be called inside a request');

        Loop::deadline(5);
    }

    public function testDeadlineRegistersATimerAgainstTheCurrentRequest(): void
    {
        Scope::set('ignis.request', 42);

        try {
            Loop::deadline(30);
        } finally {
            Scope::set('ignis.request', null);
        }

        self::assertSame([1 => 42], self::get('deadlines'), 'op id 1 => request 42');
        self::assertSame(1, \ignis_inflight());
    }

    public function testAnExpiredDeadlineAnswers504AndCountsAsACancellation(): void
    {
        self::set('requestHandler', static function (Request $r): Response {
            \Ignis\deadline(10);
            \Ignis\sleep(1000);

            return Response::text("the handler finished anyway\n");
        });
        FakeReactor::inject(5, self::rawRequest());
        self::drive();

        self::assertSame(
            [['id' => 5, 'status' => 504, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => "504 deadline exceeded\n"]],
            FakeReactor::responses(),
        );
        self::assertSame(1, Loop::$cancelled);
        self::assertSame([], self::get('deadlines'), 'the timer is forgotten once it fires');
    }

    // ---- cancellation (ADR-0009) -----------------------------------------------------------

    /**
     * Children first, in reverse spawn order, and the request fiber last — research 08 states the
     * intent ("cancel walks children first") and the mechanism gives the reason: unwinding the
     * parent answers 499 and returns its fiber to the pool, which `drainQueue()` may hand to the
     * next request while a child of the cancelled one is still running its `finally`.
     */
    public function testCancellationWalksTheChildrenBeforeTheRequestFiber(): void
    {
        $order = [];
        // Ops the fake reactor will never complete, so the loop stops with everything still parked
        // and the cancellation arrives in a poll of its own — which is how a disconnect really lands.
        self::set('requestHandler', static function (Request $r) use (&$order): Response {
            foreach ([1, 2] as $n) {
                \Ignis\async(static function () use (&$order, $n): void {
                    try {
                        Loop::awaitOp(9000 + $n);
                    } catch (CancelledException) {
                        $order[] = 'child' . $n;
                    }
                });
            }

            try {
                Loop::awaitOp(9100);
            } catch (CancelledException $e) {
                $order[] = 'parent';

                throw $e;
            }

            return Response::text("never\n");
        });

        FakeReactor::inject(8, self::rawRequest());
        self::drive();
        self::assertSame([], $order, 'everything is parked and nothing has been cancelled yet');

        FakeReactor::inject(8, ['kind' => 'cancel', 'age_us' => 200]);
        self::drive();

        self::assertSame(['child2', 'child1', 'parent'], $order);
        self::assertSame([['id' => 8, 'status' => 499, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => "499 cancelled\n"]], FakeReactor::responses());
        self::assertSame(1, Loop::$cancelled);
    }

    public function testThrowIntoDropsTheFiberFromTheWaitMap(): void
    {
        $fiber = new \Fiber(static function (): string {
            try {
                Loop::awaitOp(7);
            } catch (CancelledException) {
                return 'cancelled';
            }

            return 'resumed';
        });
        $fiber->start();

        self::assertArrayHasKey(7, self::get('waiting'));
        self::assertNotSame([], self::get('parkedOn'));

        self::call('throwInto', $fiber, new CancelledException('bye'));

        self::assertSame([], self::get('waiting'), 'a completion for op 7 must not resume a fiber that is already gone');
        self::assertSame([], self::get('parkedOn'));
        self::assertTrue($fiber->isTerminated());
        self::assertSame('cancelled', $fiber->getReturn());
    }

    public function testThrowAndAbsorbSwallowsTheInjectedExceptionAndNothingElse(): void
    {
        $injected = new CancelledException('client disconnected');

        $unguarded = new \Fiber(static function (): void {
            \Fiber::suspend();   // no try/catch: Fiber::throw() re-throws straight back at the caller
        });
        $unguarded->start();
        self::call('throwAndAbsorb', $unguarded, $injected);

        self::assertSame([], Loop::$unobserved, 'unguarded user code is the normal case and must not kill the worker thread (A3 soak: 6 dead threads in 10.1M requests)');

        $fromFinally = new \RuntimeException('a finally that throws while unwinding');
        $messy = new \Fiber(static function () use ($fromFinally): void {
            try {
                \Fiber::suspend();
            } finally {
                throw $fromFinally;
            }
        });
        $messy->start();
        self::call('throwAndAbsorb', $messy, $injected);

        self::assertSame([$fromFinally], Loop::$unobserved, 'a genuine user error is kept for the unobserved report');
        Loop::$unobserved = [];
    }

    // ---- response dispatch -----------------------------------------------------------------

    public function testAResponseGoesOutThroughIgnisRespond(): void
    {
        self::serveOnce(3, static fn(Request $r): Response => Response::json(['ok' => true], 201));

        self::assertSame(
            [['id' => 3, 'status' => 201, 'headers' => ['content-type' => 'application/json'], 'body' => '{"ok":true}']],
            FakeReactor::responses(),
        );
        self::assertSame(1, Loop::$handled);
    }

    public function testNullMeansAnsweredElsewhereAndNothingIsSent(): void
    {
        self::serveOnce(3, static fn(Request $r): ?Response => null);

        self::assertSame([], FakeReactor::responses(), 'gRPC (E10) answers through its own channel');
        self::assertSame(1, Loop::$handled, 'and the slot is still released');
        self::assertSame(0, Loop::$inflightRequests);
    }

    public function testAnythingElseTheHandlerReturnsIsA500WithTheContractMessage(): void
    {
        self::serveOnce(3, static fn(Request $r): string => 'a bare string');

        self::assertSame(
            [['id' => 3, 'status' => 500, 'headers' => ['content-type' => 'text/plain'], 'body' => "handler must return Ignis\\Http\\Response or null\n"]],
            FakeReactor::responses(),
        );
    }

    public function testAThrowingHandlerBecomesA500NamingTheExceptionClass(): void
    {
        self::serveOnce(3, static function (Request $r): Response {
            throw new \DomainException('bad input');
        });

        $response = FakeReactor::responses()[0];
        self::assertSame(500, $response['status']);
        self::assertSame("500 DomainException: bad input\n", $response['body']);
    }

    /** V-67/V-68: a fiber outlives the request that used it, so "per fiber" would mean "for ever". */
    public function testTheFibersScopeIsClearedBetweenTwoRequestsOnTheSameFiber(): void
    {
        $seen = [];
        self::set('requestHandler', static function (Request $r) use (&$seen): Response {
            $seen[$r->id] = Scope::get('leaked');
            Scope::set('leaked', 'from request ' . $r->id);

            return Response::text('ok');
        });

        FakeReactor::inject(1, self::rawRequest());
        self::drive();
        FakeReactor::inject(2, self::rawRequest());
        self::drive();

        self::assertSame(1, Loop::$fibersCreated, 'the second request really did run on the first one\'s fiber');
        self::assertSame([1 => null, 2 => null], $seen);
    }

    /**
     * `answer()` runs after `runHandler()` has returned, outside every `catch` it has, and the
     * Future `admitRequest()` spawns is discarded — so a failure there used to answer nobody and log
     * nothing, and came back later as an unobserved rejection that `runUntil()` rethrew, killing the
     * whole loop instead of the one request. A `StreamedResponse` is how it happens for real: the
     * stub `ignis_stream_bind()` throws exactly where a failing bind does.
     */
    public function testAFailureWhileAnsweringBecomesA500AndTheLoopKeepsServing(): void
    {
        self::set('requestHandler', static fn(Request $r): Response => $r->path() === '/stream'
            ? new StreamedResponse(static function (): void {})
            : Response::text('the next request'));

        $logged = self::withErrorLog(static function (): void {
            FakeReactor::inject(9, self::rawRequest('/stream'));
            self::drive();
            FakeReactor::inject(10, self::rawRequest('/plain'));
            self::drive();
        });

        $responses = FakeReactor::responses();
        self::assertSame(500, $responses[0]['status'], 'the client is answered, not dropped');
        self::assertStringContainsString('ignis_stream_bind', $responses[0]['body']);
        self::assertStringContainsString('ignis_stream_bind', $logged, 'and the failure is on the record');
        self::assertSame([], Loop::$unobserved, 'nothing is left to blow the loop up at its next stop');
        self::assertSame(
            ['id' => 10, 'status' => 200, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => 'the next request'],
            $responses[1],
            'the loop survived the one that failed',
        );
        self::assertSame(0, Loop::$inflightRequests);
        self::assertSame(2, Loop::$handled);
    }

    /**
     * DEFECT found while fixing the one above: `reportUnobserved()` rethrew the first rejection and
     * assigned `[]` over the rest, so a batch of failing fibers was reported as one and the others
     * left no trace at all. Only one throwable can come out of `runUntil()`; the others belong in
     * the log, because a crash is usually explained by what failed beside it.
     */
    public function testEveryUnobservedRejectionIsReportedAndOnlyOneCanBeRethrown(): void
    {
        $logged = self::withErrorLog(static function (): void {
            foreach (['first', 'second', 'third'] as $message) {
                Loop::spawn(static function () use ($message): never {
                    throw new \RuntimeException($message);
                });
            }

            try {
                Loop::run();
                self::fail('runUntil() must report a rejection nobody awaited');
            } catch (\RuntimeException $e) {
                self::assertSame('first', $e->getMessage());
            }
        });

        self::assertStringContainsString('second', $logged);
        self::assertStringContainsString('third', $logged);
        self::assertSame([], Loop::$unobserved);
    }

    // ---- helpers ---------------------------------------------------------------------------

    /**
     * `Loop::logFailure()` uses `error_log()`, which the embed SAPI sends to stderr — unreadable
     * from inside the process, so the test points it at a file for the duration.
     */
    private static function withErrorLog(callable $body): string
    {
        $path = sys_get_temp_dir() . '/ignis-loop-' . uniqid() . '.log';
        $before = ini_get('error_log');
        ini_set('error_log', $path);

        try {
            $body();
        } finally {
            ini_set('error_log', $before === false ? '' : $before);
        }
        $logged = @file_get_contents($path);
        @unlink($path);

        return $logged === false ? '' : $logged;
    }

    private static function budget(int $fibers, int $queueDepth = 0): void
    {
        Loop::$fiberBudget = $fibers;
        Loop::$queueDepth = $queueDepth;
    }

    private static function queued(): int
    {
        return \count(self::get('requestQueue')) - self::get('queueHead');
    }

    private static function serveOnce(int $id, callable $handler): void
    {
        self::set('requestHandler', $handler);
        FakeReactor::inject($id, self::rawRequest());
        self::drive();
    }
}
