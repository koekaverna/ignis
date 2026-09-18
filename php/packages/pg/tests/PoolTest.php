<?php

declare(strict_types=1);

namespace Ignis\Tests\Pg;

use Ignis\Loop;
use Ignis\Pg\Lease;
use Ignis\Pg\LeaseError;
use Ignis\Pg\Pool;
use Ignis\Pg\PoolError;
use Ignis\Pg\QueryError;
use Ignis\Scope;
use Ignis\Tests\FakeReactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fake-pg.php';

/**
 * `Ignis\Pg` (E14, ADR-0015): connections belong to the runtime, PHP holds leases. What is unit
 * testable is the lease discipline — one per fiber, released once, unusable afterwards — and the
 * statement order a transaction produces. Real rows, real cancellation and the pool's own
 * behaviour under contention are E14's.
 */
#[CoversClass(Pool::class)]
#[CoversClass(Lease::class)]
final class PoolTest extends TestCase
{
    protected function setUp(): void
    {
        FakeReactor::reset();
        FakePg::reset();
        Scope::clear();
        // The loop drives awaitOp() from {main}; keep boot() from reading the ambient environment
        // (and calling gc_disable()) and from publishing stats through a function that is not here.
        (new \ReflectionProperty(Loop::class, 'booted'))->setValue(null, true);
        (new \ReflectionProperty(Loop::class, 'canPublishStats'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Scope::clear();
        FakeReactor::reset();
    }

    public function testAnErrorPayloadBecomesAQueryErrorAndAnythingElsePassesThrough(): void
    {
        self::assertSame('plain', Pool::result('plain'));
        self::assertSame(['rows' => []], Pool::result(['rows' => []]), 'an array without the error tag is a result, not a failure');

        try {
            Pool::result(['kind' => 'error', 'message' => 'ERROR: relation "nope" does not exist']);
            self::fail('an error payload must not be handed to json_decode()');
        } catch (QueryError $e) {
            self::assertSame('ERROR: relation "nope" does not exist', $e->getMessage());
        }
    }

    public function testASecondAcquireInTheSameFiberIsAnErrorAndNeverAWait(): void
    {
        $pool = new Pool('postgres://x', 4);
        $lease = $pool->acquire();

        try {
            $pool->acquire();
            self::fail('a fiber that acquires twice would deadlock a pool of one');
        } catch (LeaseError $e) {
            self::assertSame('this fiber already holds a lease from pool 1', $e->getMessage());
        }

        $lease->release();
        self::assertInstanceOf(Lease::class, $pool->acquire(), 'and the slot is usable again once it is given back');
    }

    public function testAnIdleConnectionIsHandedOverWithNoReactorHop(): void
    {
        FakePg::$acquireIsIdle = true;
        $before = FakeReactor::inflight();

        $lease = (new Pool('postgres://x'))->acquire();

        self::assertSame(1, $lease->id);
        self::assertSame($before, FakeReactor::inflight(), 'the fast path does not park the fiber at all');
    }

    public function testReleaseIsIdempotent(): void
    {
        $lease = (new Pool('postgres://x'))->acquire();

        $lease->release();
        $lease->release();
        $lease->release(false);

        self::assertSame([['lease' => 1, 'reset' => true]], FakePg::$releases, 'giving the same connection back twice would hand it to two fibers at once');
    }

    public function testUsingALeaseAfterReleasingItIsAnError(): void
    {
        $lease = (new Pool('postgres://x'))->acquire();
        $lease->release();

        $this->expectException(LeaseError::class);
        $this->expectExceptionMessage('lease already released');
        $lease->query('SELECT 1');
    }

    public function testATransactionCommitsInOrderAndGivesTheConnectionBack(): void
    {
        $pool = new Pool('postgres://x');

        $result = $pool->transaction(static function (Lease $lease): string {
            $lease->exec('INSERT INTO t VALUES (1)');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['BEGIN', 'INSERT INTO t VALUES (1)', 'COMMIT'], FakePg::$statements);
        self::assertSame([['lease' => 1, 'reset' => true]], FakePg::$releases, 'released after the COMMIT, not before it');
    }

    public function testAThrowingTransactionRollsBackAndRethrowsTheOriginal(): void
    {
        $pool = new Pool('postgres://x');

        try {
            $pool->transaction(static function (Lease $lease): never {
                $lease->exec('UPDATE t SET a = 1');

                throw new \DomainException('the application said no');
            });
            self::fail('transaction() must not swallow what the body threw');
        } catch (\DomainException $e) {
            self::assertSame('the application said no', $e->getMessage());
        }

        self::assertSame(['BEGIN', 'UPDATE t SET a = 1', 'ROLLBACK'], FakePg::$statements);
        self::assertSame([['lease' => 1, 'reset' => true]], FakePg::$releases);
    }

    public function testAFailingRollbackDoesNotHideTheOriginalError(): void
    {
        FakePg::$failSql = 'ROLLBACK';
        $pool = new Pool('postgres://x');

        try {
            $pool->transaction(static function (Lease $lease): never {
                throw new \DomainException('the application said no');
            });
            self::fail('the body\'s exception is the one that matters');
        } catch (\DomainException $e) {
            self::assertSame('the application said no', $e->getMessage());
        }

        self::assertSame(['BEGIN', 'ROLLBACK'], FakePg::$statements, 'the reset on release rolls back anyway');
    }

    public function testALeaseThatGoesOutOfScopeReleasesItselfWithoutWaiting(): void
    {
        $pool = new Pool('postgres://x');
        $lease = $pool->acquire();
        self::assertNotNull(Scope::get('ignis.pg.lease.1'));

        $inflightBefore = FakeReactor::inflight();
        Scope::set('ignis.pg.lease.1', null);   // the scope holds the only other reference
        unset($lease);

        self::assertSame([['lease' => 1, 'reset' => true]], FakePg::$releases);
        self::assertSame($inflightBefore + 1, FakeReactor::inflight(), 'fire and forget: the op is submitted and nobody awaits its completion');
    }

    public function testTheOneStatementHelpersAcquireAndReleaseAroundEveryCall(): void
    {
        $pool = new Pool('postgres://x');

        self::assertSame([['sql' => 'SELECT 1', 'params' => '[]']], $pool->query('SELECT 1'));
        self::assertSame(1, $pool->exec('DELETE FROM t WHERE a = $1', ['x']));
        self::assertSame(['SELECT 1', 'DELETE FROM t WHERE a = $1'], FakePg::$statements);
        self::assertCount(2, FakePg::$releases, 'each helper gives its connection straight back');
        self::assertNull(Scope::get('ignis.pg.lease.1'), 'and clears the fiber\'s lease slot');
    }

    public function testAFailingQueryStillGivesTheConnectionBack(): void
    {
        FakePg::$failSql = 'SELECT boom';
        $pool = new Pool('postgres://x');

        try {
            $pool->query('SELECT boom');
            self::fail('a failing statement is a QueryError');
        } catch (QueryError $e) {
            self::assertSame('ERROR: SELECT boom', $e->getMessage());
        }

        self::assertSame([['lease' => 1, 'reset' => true]], FakePg::$releases, 'the finally in query() is what stops a failed statement leaking a connection');
    }

    public function testStatsComeStraightFromTheRuntime(): void
    {
        self::assertSame(['idle' => 4, 'leased' => 0], (new Pool('postgres://x'))->stats());
    }

    /**
     * Three failures, three classes. An operator reading a trace has to be able to tell "the
     * database is unreachable" from "the query was wrong" from "you used the lease wrongly", and
     * until 2026-09-18 the first arrived as QueryError -- named after a query that never ran.
     */
    public function testAnAcquireFailureIsAPoolErrorAndNotAQueryError(): void
    {
        $timedOut = ['kind' => 'error', 'message' => 'pg: acquire timed out after 5000 ms (IGNIS_PG_ACQUIRE_TIMEOUT_MS)'];

        try {
            Pool::orThrow($timedOut, PoolError::class);
            self::fail('an error payload must throw');
        } catch (PoolError $error) {
            self::assertStringContainsString('acquire timed out', $error->getMessage());
        }

        self::assertInstanceOf(\RuntimeException::class, new PoolError(''), 'unreachable is a runtime condition');
        self::assertInstanceOf(\LogicException::class, new LeaseError(''), 'misusing a lease is a programming mistake');

        // The shared helper still defaults to QueryError for everything a query returns.
        $this->expectException(QueryError::class);
        Pool::result(['kind' => 'error', 'message' => 'syntax error at or near "slect"']);
    }

    /**
     * S4-MIXED: every completion is data off the reactor, and orThrow() used to hand a non-string
     * "message" straight to the exception constructor without looking at it first.
     */
    public function testAnErrorPayloadWithNoUsableMessageGetsAGenericOne(): void
    {
        $this->expectException(QueryError::class);
        $this->expectExceptionMessage('the reactor reported an error with no message');
        Pool::result(['kind' => 'error']);
    }

    public function testDecodedJsonRejectsAPayloadThatIsNotAString(): void
    {
        $this->expectException(PoolError::class);
        $this->expectExceptionMessage('expected a JSON string from the reactor, got int');
        Pool::decodedJson(42, PoolError::class);
    }

    public function testDecodedJsonRejectsAJsonValueThatIsNotAnObject(): void
    {
        $this->expectException(PoolError::class);
        $this->expectExceptionMessage('expected a JSON object from the reactor, got string');
        Pool::decodedJson('"just a string"', PoolError::class);
    }

    public function testWithStringKeysRejectsANonStringKey(): void
    {
        $this->expectException(PoolError::class);
        Pool::withStringKeys([0 => 'value'], PoolError::class);
    }

    public function testAnAcquireCompletionMissingAnIntLeaseIsAPoolError(): void
    {
        $this->expectException(PoolError::class);
        (new \ReflectionMethod(Pool::class, 'leaseIdFromCompletion'))->invoke(null, ['lease' => 'not-an-int']);
    }

    public function testAQueryCompletionMissingRowsOrAffectedIsAQueryError(): void
    {
        $this->expectException(QueryError::class);
        (new \ReflectionMethod(Lease::class, 'asQueryResult'))->invoke(null, ['rows' => []]);
    }

    public function testAQueryCompletionWhoseRowIsNotAnArrayIsAQueryError(): void
    {
        $this->expectException(QueryError::class);
        (new \ReflectionMethod(Lease::class, 'asRow'))->invoke(null, 'not-a-row');
    }

    public function testBackendPidRejectsAColumnThatIsNotAnInt(): void
    {
        FakePg::$queryRow = ['pid' => '4821'];
        $lease = (new Pool('postgres://x'))->acquire();

        $this->expectException(QueryError::class);
        $this->expectExceptionMessage('pg_backend_pid() did not return an integer');
        $lease->backendPid();
    }
}
