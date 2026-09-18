<?php

declare(strict_types=1);

namespace Ignis\Tests\Offload;

use Ignis\Offload\CallbackRef;
use Ignis\Offload\RemoteException;
use Ignis\Offload\WorkerRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fake-offload.php';
require_once \dirname(__DIR__) . '/src/worker.php';

/**
 * The worker side of the offload pool. The file is embedded into the binary with `include_str!`
 * (crates/ignis/src/main.rs), so it cannot be split into `WorkerRuntime.php` + a three-line
 * `worker.php`: the Rust side evaluates it under the name `ignis-offload-worker`, where `__DIR__`
 * does not point at this directory and a `require` next to it would not resolve. The seam is the
 * other way round — the trailing `WorkerRuntime::run()` is guarded on `ignis_offload_next()`, the
 * job channel only the binary provides, and the two duplicated class declarations are guarded the
 * way `ignis-offload.php` already guards its own.
 */
#[CoversClass(WorkerRuntime::class)]
final class WorkerRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        FakeOffload::reset();
        self::set('handles', []);
        self::set('nextHandle', 1);
        WorkerRuntime::$job = 77;
        WorkerRuntime::$worker = 3;
    }

    protected function tearDown(): void
    {
        putenv('IGNIS_OFFLOAD_FUNCTIONS');
        putenv('IGNIS_OFFLOAD_CLASSES');
    }

    public function testRequiringTheWorkerDidNotStartItsJobLoop(): void
    {
        self::assertFalse(\function_exists('ignis_offload_next'), 'the guard is only honest while the job channel is genuinely absent');
        self::assertTrue(class_exists(WorkerRuntime::class, false));
    }

    public function testACallbackRefBecomesAClosureThatCallsBackAtAnyDepth(): void
    {
        FakeOffload::$callbackAnswer = serialize(['ok' => 'from the caller']);

        $bound = WorkerRuntime::bindCallbacks(['sql', ['on' => new CallbackRef(4)], 9]);

        self::assertSame('sql', $bound[0]);
        self::assertSame(9, $bound[2]);
        self::assertInstanceOf(\Closure::class, $bound[1]['on']);
        self::assertSame('from the caller', ($bound[1]['on'])('a row', 2));
        self::assertSame(
            [['job' => 77, 'cb' => 4, 'args' => serialize(['a row', 2])]],
            FakeOffload::$callbacks,
            'the arguments travel back serialized, tagged with this worker\'s current job',
        );
    }

    public function testACallbackWhoseCallerIsGoneThrows(): void
    {
        FakeOffload::$callbackFails = true;
        $stub = WorkerRuntime::bindCallbacks(new CallbackRef(1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload callback failed (caller gone)');
        $stub();
    }

    public function testACallbackThatThrewOnTheCallerComesBackAsARemoteException(): void
    {
        FakeOffload::$callbackAnswer = serialize(['err' => [\DomainException::class, 'bad row', 7]]);
        $stub = WorkerRuntime::bindCallbacks(new CallbackRef(1));

        try {
            $stub();
            self::fail('a callback that threw on the caller must not look like a return');
        } catch (RemoteException $e) {
            self::assertSame(\DomainException::class, $e->remoteClass);
            self::assertSame('callback threw: bad row', $e->getMessage());
            self::assertSame(7, $e->getCode());
        }
    }

    public function testAnUnknownHandleIsAnErrorRatherThanANull(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offload: unknown handle 12 on this worker');

        self::call('resolveRefs', [['__ref' => [3, 12, 'CurlHandle']]]);
    }

    public function testAKnownHandleResolvesToTheObjectThisWorkerHolds(): void
    {
        $object = new \ArrayObject([1, 2, 3]);
        self::set('handles', [5 => $object]);

        $resolved = self::call('resolveRefs', ['keep', ['deep' => ['__ref' => [3, 5, 'ArrayObject']]]]);

        self::assertSame('keep', $resolved[0]);
        self::assertSame($object, $resolved[1]['deep'], 'the object never leaves this thread; only the ref does');
    }

    public function testOnlyRoutableObjectsAreKeptBehindARef(): void
    {
        $curl = curl_init();
        $plain = new \ArrayObject();

        $registered = self::call('registerObjects', ['rows' => [$curl, $plain, 'text']]);

        self::assertSame(['__ref' => [3, 1, 'CurlHandle']], $registered['rows'][0], 'a CurlHandle is worker-pinned');
        self::assertSame([1 => $curl], self::get('handles'));
        self::assertSame($plain, $registered['rows'][1], 'anything else is copied back to the caller as it is');
        self::assertSame('text', $registered['rows'][2]);
    }

    public function testRoutedRunsAFunctionAConstructorAMethodAndAFree(): void
    {
        putenv('IGNIS_OFFLOAD_FUNCTIONS=strtoupper');
        putenv('IGNIS_OFFLOAD_CLASSES=ArrayObject');

        self::assertSame('ABC', WorkerRuntime::routed('fn:strtoupper', ['abc']));

        $made = WorkerRuntime::routed('new:ArrayObject', [[1, 2, 3]]);
        self::assertInstanceOf(\ArrayObject::class, $made, 'ArrayObject is not routable, so it is copied rather than pinned');
        self::assertSame(3, WorkerRuntime::routed('method:count', [$made]));

        self::set('handles', [5 => new \ArrayObject()]);
        self::assertNull(WorkerRuntime::routed('free', [['__ref' => [3, 5, 'ArrayObject']]]));
        self::assertSame([], self::get('handles'), 'a free drops the object even though the ref cannot be resolved any more');
    }

    /**
     * The function name reaches this thread as data on the job channel, so the worker is what has
     * to decide whether it may be invoked at all. The answer is the list `route.rs` installed its
     * trampolines from: nothing else can legitimately arrive as `fn:`.
     */
    public function testAFunctionTheRuntimeDoesNotRouteIsRefused(): void
    {
        putenv('IGNIS_OFFLOAD_FUNCTIONS=strtoupper');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('offload: passthru is not a routed function');

        WorkerRuntime::routed('fn:passthru', ['id']);
    }

    /** Unset is the shipped state: `route.rs` has routed no function by default since V-59. */
    public function testWithNoRoutedFunctionsConfiguredNoFunctionRunsHere(): void
    {
        putenv('IGNIS_OFFLOAD_FUNCTIONS');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('offload: strtoupper is not a routed function');

        WorkerRuntime::routed('fn:strtoupper', ['abc']);
    }

    /** Unset is the shipped state again: the default class list is `SQLite3` and nothing else. */
    public function testAClassTheRuntimeDoesNotRouteIsRefused(): void
    {
        putenv('IGNIS_OFFLOAD_CLASSES');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('offload: ArrayObject is not a routed class');

        WorkerRuntime::routed('new:ArrayObject', [[1, 2, 3]]);
    }

    public function testAnUnknownRoutedKindIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bad routed call wat:something');

        WorkerRuntime::routed('wat:something', []);
    }

    public function testARoutedMethodOnSomethingThatIsNotAnObjectIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('routed method count on a non-object');

        WorkerRuntime::routed('method:count', ['not an object']);
    }

    private static function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(WorkerRuntime::class, $method))->invoke(null, ...$args);
    }

    private static function set(string $property, mixed $value): void
    {
        (new \ReflectionProperty(WorkerRuntime::class, $property))->setValue(null, $value);
    }

    private static function get(string $property): mixed
    {
        return (new \ReflectionProperty(WorkerRuntime::class, $property))->getValue();
    }
}
