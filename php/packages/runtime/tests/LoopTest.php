<?php

declare(strict_types=1);

namespace Ignis\Tests;

use Ignis\CancelledException;
use Ignis\Http\Request;
use Ignis\Http\Response;
use Ignis\Http\StreamedResponse;
use Ignis\KilledException;
use Ignis\Loop;
use Ignis\Scope;
use PHPUnit\Framework\Attributes\CoversClass;

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
    /** {main} has no fiber, so Scope falls back to one static array — clear it between tests too. */
    protected function setUp(): void
    {
        parent::setUp();
        Scope::clear();
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
        self::assertSame(['job0', 'job1', 'job2'], array_map(static fn($future) => $future->await(), $futures));

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

    /**
     * `Fiber::suspend()` erases the resume value's type to `mixed`, so the only place left to
     * enforce "a pool fiber is only ever resumed with a Job" is a runtime check on the value itself.
     */
    public function testAPoolFiberResumedWithSomethingOtherThanAJobIsRejected(): void
    {
        Loop::spawn(static fn(): int => 1);
        Loop::run();

        $idle = self::get('idle');
        if (!\is_array($idle) || !isset($idle[0]) || !$idle[0] instanceof \Fiber) {
            self::fail('expected one idle fiber after the first job finished');
        }
        self::set('idle', []);
        self::set('ready', [[$idle[0], 'not-a-job']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a pool fiber was resumed with something other than a Job');
        Loop::run();
    }

    // ---- admission control (ADR-0019) ------------------------------------------------------

    public function testRequestsAreAdmittedUpToTheBudgetAndThenQueuedAsData(): void
    {
        self::budget(2);
        self::set('requestHandler', static fn(Request $request): Response => Response::text('x'));

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
        self::set('requestHandler', static fn(Request $request): Response => Response::text('x'));

        $admittedRequestId = 1;
        $queuedRequestId = 2;
        $shedRequestId = 3;
        self::call('dispatchRequest', $admittedRequestId, self::rawRequest());
        self::call('dispatchRequest', $queuedRequestId, self::rawRequest());
        self::call('dispatchRequest', $shedRequestId, self::rawRequest());

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
        self::set('requestHandler', static fn(Request $request): Response => Response::text('x'));

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
        self::set('requestHandler', static function (Request $request) use (&$seen): Response {
            $seen[] = $request->id;

            return Response::text((string) $request->id);
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
        self::set('requestHandler', static fn(Request $request): Response => Response::text('x'));

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
        self::set('requestHandler', static fn(Request $request): Response => Response::text('x'));

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

    /** `Ignis\Scope` is a generic bag, so a request id that is not an int is a bug, not a `mixed` PHPStan has to trust. */
    public function testDeadlineRejectsARequestScopeThatIsNotAnInt(): void
    {
        Scope::set('ignis.request', 'not-an-int');

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('the ignis.request scope holds something other than an int');
            Loop::deadline(5);
        } finally {
            Scope::set('ignis.request', null);
        }
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
        self::set('requestHandler', static function (Request $request): Response {
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

    public function testADeadlineIsCalledOffWhenTheRequestAnswersInTime(): void
    {
        self::set('requestHandler', static fn(Request $request): Response => (static function (): Response {
            \Ignis\deadline(30_000);

            return Response::text("in time\n");
        })());
        FakeReactor::inject(5, self::rawRequest());
        self::drive();

        self::assertSame([], self::get('deadlines'), 'the timer goes with the request it belonged to');
        self::assertSame([], self::get('deadlineOf'));
        self::assertSame(0, \ignis_inflight(), 'a 30 s timer still in flight holds a graceful drain for 30 s');
        self::assertSame(0, Loop::$cancelled, 'an answered request is not a cancellation');
    }

    public function testASecondDeadlineReplacesTheFirstRatherThanArmingTwo(): void
    {
        Scope::set('ignis.request', 42);

        try {
            Loop::deadline(30);
            Loop::deadline(40);
        } finally {
            Scope::set('ignis.request', null);
        }

        self::assertSame(1, \count((array) self::get('deadlines')), 'one wall-clock deadline per request is the contract');
        self::assertSame(1, \ignis_inflight());
    }

    /**
     * A pooled fiber goes back to `$idle` the moment its job settles and the next request may take
     * it. While it also stayed in `$children`, a disconnect on the request that spawned it threw
     * `CancelledException` into whatever the *next* request was doing on that fiber.
     */
    public function testAFinishedChildLeavesItsRequestWhileThatRequestIsStillInFlight(): void
    {
        self::set('requestHandler', static function (Request $request): Response {
            \Ignis\async(static fn(): int => 1);
            Loop::awaitOp(9100);

            return Response::text("unreachable\n");
        });
        FakeReactor::inject(5, self::rawRequest());
        self::drive();

        self::assertSame([5 => []], self::get('children'), 'the settled child is out of its parent\'s list');
        self::assertSame(1, Loop::idleFibers(), 'and it is back in the pool, which is what made this dangerous');
    }

    // ---- cancellation (ADR-0009) -----------------------------------------------------------

    /**
     * Children first, in reverse spawn order, and the request fiber last — research 08 states the
     * intent ("cancel walks children first") and the mechanism gives the reason: unwinding the
     * parent answers 499 and returns its fiber to the pool, which `drainQueue()` may hand to the
     * next request while a child of the cancelled one is still running its `finally`.
     *
     * The ops below are ones the fake reactor will never complete, so the loop stops with
     * everything still parked and the cancellation arrives in a poll of its own — which is how a
     * disconnect really lands.
     */
    public function testCancellationWalksTheChildrenBeforeTheRequestFiber(): void
    {
        $order = [];
        self::set('requestHandler', static function (Request $request) use (&$order): Response {
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

        $waiting = self::get('waiting');
        if (!\is_array($waiting)) {
            self::fail('Loop::$waiting is not an array');
        }
        self::assertArrayHasKey(7, $waiting);
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

        $unguarded = self::unguardedFiber();
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

    // ---- unawaited reactor completions, off the wire (S4-MIXED) ----------------------------

    public function testAnUnawaitedRequestCompletionWithANonStringMethodIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('a malformed request completion');
        self::call('dispatchUnawaited', 1, ['method' => 7, 'uri' => '/', 'headers' => [], 'body' => '']);
    }

    public function testAnUnawaitedRequestCompletionWithAMalformedHeaderIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('a malformed request completion header');
        self::call('dispatchUnawaited', 1, ['method' => 'GET', 'uri' => '/', 'headers' => ['x' => 7], 'body' => '']);
    }

    public function testAnUnawaitedCancelCompletionMissingAgeUsIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('a cancel completion is missing an int age_us');
        self::call('dispatchUnawaited', 1, ['kind' => 'cancel']);
    }

    /**
     * A-SWALLOWED-RUST: a fire-and-forget op nobody awaits can fail at the reactor level, and that
     * failure reaches `dispatchUnawaited()` tagged `kind => 'error'` — neither of the tags it
     * dispatches on. Pinning that it is dropped, not just undocumented: nothing throws, nothing is
     * cancelled and no request is dispatched for it.
     */
    public function testAnUnawaitedCompletionMatchingNeitherTagIsSilentlyDropped(): void
    {
        self::assertNull(self::call('dispatchUnawaited', 1, ['kind' => 'error', 'message' => 'op failed']));

        self::assertSame(0, Loop::$cancelled);
        self::assertSame([], FakeReactor::responses());
    }

    // ---- response dispatch -----------------------------------------------------------------

    public function testAResponseGoesOutThroughIgnisRespond(): void
    {
        self::serveOnce(3, static fn(Request $request): Response => Response::json(['ok' => true], 201));

        self::assertSame(
            [['id' => 3, 'status' => 201, 'headers' => ['content-type' => 'application/json'], 'body' => '{"ok":true}']],
            FakeReactor::responses(),
        );
        self::assertSame(1, Loop::$handled);
    }

    public function testNullMeansAnsweredElsewhereAndNothingIsSent(): void
    {
        self::serveOnce(3, static fn(Request $request): ?Response => null);

        self::assertSame([], FakeReactor::responses(), 'gRPC (E10) answers through its own channel');
        self::assertSame(1, Loop::$handled, 'and the slot is still released');
        self::assertSame(0, Loop::$inflightRequests);
    }

    public function testAnythingElseTheHandlerReturnsIsA500WithTheContractMessage(): void
    {
        self::serveOnce(3, static fn(Request $request): string => 'a bare string');

        self::assertSame(
            [['id' => 3, 'status' => 500, 'headers' => ['content-type' => 'text/plain'], 'body' => "handler must return Ignis\\Http\\Response or null\n"]],
            FakeReactor::responses(),
        );
    }

    public function testAThrowingHandlerBecomesA500NamingTheExceptionClass(): void
    {
        self::serveOnce(3, static function (Request $request): Response {
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
        self::set('requestHandler', static function (Request $request) use (&$seen): Response {
            $seen[$request->id] = Scope::get('leaked');
            Scope::set('leaked', 'from request ' . $request->id);

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
        self::set('requestHandler', static fn(Request $request): Response => $request->path() === '/stream'
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

    // ---- ADR-0043 §7, L0: the fiber timeout ------------------------------------------------

    /** IGNIS_FIBER_TIMEOUT_MS bounds a request that never yields to the app's own Ignis\deadline(). */
    public function testAFiberTimeoutAnswers504NamingItselfWhenNoAppDeadlineWasSet(): void
    {
        self::withEnv(['IGNIS_FIBER_TIMEOUT_MS' => '30', 'IGNIS_PROFILE' => false, 'IGNIS_RECOVERY_ROUTES' => false], function (): void {
            self::set('requestHandler', static function (Request $request): Response {
                Loop::awaitOp(9200);

                return Response::text("the handler finished anyway\n");
            });
            FakeReactor::inject(5, self::rawRequest('/slow'));
            self::drive();
        });

        self::assertSame(
            [['id' => 5, 'status' => 504, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => "504 fiber timeout after 30 ms\n"]],
            FakeReactor::responses(),
        );
        self::assertSame(30, FakeReactor::clockMilliseconds(), 'the fiber timeout fired, not any longer default');
    }

    public function testAFiberTimeoutOfZeroIsOff(): void
    {
        self::withEnv(['IGNIS_FIBER_TIMEOUT_MS' => '0', 'IGNIS_PROFILE' => false, 'IGNIS_RECOVERY_ROUTES' => false], function (): void {
            self::set('requestHandler', static function (Request $request): Response {
                Loop::awaitOp(9250);

                return Response::text("unreachable\n");
            });
            FakeReactor::inject(1, self::rawRequest());
            self::drive();

            self::assertSame([], self::get('deadlines'), 'production ships fiber_timeout_ms=0: no timer is armed at all');
            self::assertSame([], FakeReactor::responses(), 'nothing has fired to answer the still-parked request');
        });
    }

    /** An app-level Ignis\deadline() call replaces the L0 timer rather than racing it. */
    public function testAnAppDeadlineReplacesTheFiberTimeoutInsteadOfAddingASecondOne(): void
    {
        self::withEnv(['IGNIS_FIBER_TIMEOUT_MS' => '30', 'IGNIS_PROFILE' => false, 'IGNIS_RECOVERY_ROUTES' => false], function (): void {
            self::set('requestHandler', static function (Request $request): Response {
                \Ignis\deadline(500);
                Loop::awaitOp(9300);

                return Response::text("in time\n");
            });
            FakeReactor::inject(5, self::rawRequest('/slow'));
            self::drive();
        });

        self::assertSame(
            [['id' => 5, 'status' => 504, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => "504 deadline exceeded\n"]],
            FakeReactor::responses(),
            'the app\'s own 500 ms deadline fired, not the 30 ms fiber timeout',
        );
        self::assertSame(500, FakeReactor::clockMilliseconds());
        self::assertSame([], self::get('fiberTimeoutMilliseconds'), 'the app deadline is not tagged as an L0 timeout');
    }

    /** IGNIS_RECOVERY_ROUTES: the longest matching prefix overrides the global fiber timeout. */
    public function testARecoveryRouteOverridesTheFiberTimeoutForItsPrefix(): void
    {
        self::withEnv([
            'IGNIS_FIBER_TIMEOUT_MS' => '1000',
            'IGNIS_PROFILE' => false,
            'IGNIS_RECOVERY_ROUTES' => '/export/=fiber_timeout_ms:30;stall_kill_ms:0,/=busy_warn_ms:5',
        ], function (): void {
            self::set('requestHandler', static function (Request $request): Response {
                Loop::awaitOp(9310);

                return Response::text("unreachable\n");
            });
            FakeReactor::inject(6, self::rawRequest('/export/report.csv'));
            self::drive();
        });

        self::assertSame(30, FakeReactor::clockMilliseconds(), 'the route\'s 30 ms overrides the global 1000 ms');
        self::assertSame(
            [['id' => 6, 'status' => 504, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => "504 fiber timeout after 30 ms\n"]],
            FakeReactor::responses(),
        );
    }

    // ---- ADR-0043 §7, L2: a swallowed cancellation is force-closed -------------------------

    /**
     * `swallow-cancel.php`'s shape (research 50 S-6): a fiber catches its cancellation and parks
     * again instead of unwinding. The default policy force-closes it at that next park: its own
     * `finally` runs, the loop answers 504, and the fiber is actually freed — proving the reference
     * cycle `poolBody()`'s own `$self` creates was collected, not merely dereferenced.
     */
    public function testASwallowedCancellationIsForceClosedAtItsNextPark(): void
    {
        $finallyRan = false;
        self::set('requestHandler', static function (Request $request) use (&$finallyRan): Response {
            try {
                Loop::awaitOp(9401);
            } catch (CancelledException) {
                try {
                    Loop::awaitOp(9402);
                } finally {
                    $finallyRan = true;
                }
            }

            return Response::text("unreachable\n");
        });
        FakeReactor::inject(7, self::rawRequest());
        self::drive();

        FakeReactor::inject(7, ['kind' => 'cancel', 'age_us' => 500]);
        self::drive();

        self::assertTrue($finallyRan, 'the handler\'s own finally ran during the forced unwind');
        self::assertSame([], self::get('waiting'), 'nothing is left parked for a fiber that no longer exists');
        self::assertSame([], self::get('killPending'));
        self::assertSame(
            [['id' => 7, 'status' => 504, 'headers' => ['content-type' => 'text/plain'], 'body' => "504 fiber killed\n"]],
            FakeReactor::responses(),
        );
        self::assertArrayNotHasKey(7, (array) self::get('requestFibers'));
    }

    /** IGNIS_ON_SWALLOWED_CANCEL=log: the fiber is left alone, one warn line, and it is forgotten. */
    public function testOnSwallowedCancelLogLeavesTheFiberRunningWithOneWarnLine(): void
    {
        self::set('forceCloseOnSwallowedCancel', false);
        $finallyRan = false;
        self::set('requestHandler', static function (Request $request) use (&$finallyRan): Response {
            try {
                Loop::awaitOp(9701);
            } catch (CancelledException) {
                try {
                    Loop::awaitOp(9702);
                } finally {
                    $finallyRan = true;
                }
            }

            return Response::text("unreachable\n");
        });
        FakeReactor::inject(9, self::rawRequest());
        self::drive();

        $logged = self::withErrorLog(static function (): void {
            FakeReactor::inject(9, ['kind' => 'cancel', 'age_us' => 20]);
            self::drive();
        });

        self::assertFalse($finallyRan, 'log mode never touches the fiber');
        self::assertStringContainsString('Ignis\Loop: request 9 swallowed its cancellation and parked again', $logged);
        self::assertSame(1, substr_count($logged, 'swallowed its cancellation'), 'one line, not one per turn');
        self::assertSame([], self::get('killPending'));
        self::assertSame([], self::get('logSwallowedPending'), 'forgotten once logged');
        self::assertArrayHasKey(9702, (array) self::get('waiting'), 'still parked, not force-closed');
    }

    // ---- ADR-0043 §7, L2/L3: poolBody() wakes an awaiter of a force-closed job -------------

    /**
     * A force-close reached through some path other than a swallowed cancellation — L3's signal
     * in the real binary, simulated here by marking a plain `Ignis\async()` job's fiber directly —
     * must still settle its Future, or whoever awaits it hangs forever.
     */
    public function testAForceClosedJobRejectsItsFutureWithKilledExceptionInsteadOfHangingItsAwaiter(): void
    {
        $future = Loop::spawn(static function (): string {
            Loop::awaitOp(9600);

            return 'unreachable';
        });
        self::drive();

        $weakFiberReference = (function (): \WeakReference {
            $fiber = null;
            foreach ((array) self::get('waiting') as $waiter) {
                $fiber = $waiter;
            }
            if (!$fiber instanceof \Fiber) {
                self::fail('the spawned job is not parked as expected');
            }
            self::set('killPending', [\spl_object_id($fiber) => $fiber]);

            return \WeakReference::create($fiber);
        })();

        self::call('forceClosePending');

        self::assertNull($weakFiberReference->get(), 'the job fiber\'s self-reference cycle was actually collected, not merely dereferenced');
        self::assertTrue($future->isDone());
        self::assertSame([], self::get('idle'), 'a killed fiber never goes back to the pool');

        $this->expectException(KilledException::class);
        $this->expectExceptionMessage('fiber force-closed');
        $future->await();
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

    /**
     * Runs $body with each named environment variable set (or, for `false`, unset), restoring
     * every one of them afterwards. `Recovery::fiberTimeoutFor()` reads the real environment on
     * every call rather than a value `boot()` cached, so this is how a test controls it.
     * @param array<string, string|false> $variables
     */
    private static function withEnv(array $variables, callable $body): void
    {
        $before = [];
        foreach ($variables as $name => $value) {
            $before[$name] = getenv($name);
            putenv($value === false ? $name : "{$name}={$value}");
        }

        try {
            $body();
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    private static function budget(int $fibers, int $queueDepth = 0): void
    {
        Loop::$fiberBudget = $fibers;
        Loop::$queueDepth = $queueDepth;
    }

    private static function queued(): int
    {
        $requestQueue = self::get('requestQueue');
        $queueHead = self::get('queueHead');
        if (!\is_array($requestQueue) || !\is_int($queueHead)) {
            self::fail('Loop::$requestQueue or Loop::$queueHead has an unexpected type');
        }

        return \count($requestQueue) - $queueHead;
    }

    private static function serveOnce(int $id, callable $handler): void
    {
        self::set('requestHandler', $handler);
        FakeReactor::inject($id, self::rawRequest());
        self::drive();
    }

    /**
     * No try/catch: Fiber::throw() re-throws straight back at the caller.
     * @return \Fiber<mixed,mixed,mixed,mixed>
     */
    private static function unguardedFiber(): \Fiber
    {
        return new \Fiber(static function (): void {
            \Fiber::suspend();
        });
    }
}
