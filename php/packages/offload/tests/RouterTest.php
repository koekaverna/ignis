<?php

declare(strict_types=1);

namespace Ignis\Tests\Offload;

use Ignis\Offload\Handle;
use Ignis\Offload\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fake-offload.php';
require_once __DIR__ . '/proxy-subjects.php';

/**
 * `Ignis\Offload\Router` (ADR-0016 §3) is the auto-routing half of the offload mechanism: real
 * handles live on one worker thread, the fiber holds a proxy that remembers which. Everything
 * tested here is pure bookkeeping — ref packing, worker affinity, round-robin and the generated
 * proxy class. Anything that would actually submit a job and park is E16's, not this file's.
 */
#[CoversClass(Router::class)]
#[CoversClass(Handle::class)]
final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        FakeOffload::reset();
        (new \ReflectionProperty(Router::class, 'rr'))->setValue(null, 0);
    }

    public function testUnwrapPacksHandlesAndProxiesIntoRefsAtAnyDepth(): void
    {
        $handle = new Handle(1, 5, 'CurlHandle');
        $proxy = new \stdClass();
        $proxy->__ignisHandle = new Handle(2, 9, 'PDO');

        self::assertSame(
            [
                ['__ref' => [1, 5, 'CurlHandle']],
                'plain',
                ['nested' => [['__ref' => [2, 9, 'PDO']], 7]],
            ],
            self::call('unwrap', [$handle, 'plain', ['nested' => [$proxy, 7]]]),
        );
    }

    public function testUnwrapLeavesDataThatHoldsNoHandleAlone(): void
    {
        self::assertSame(['a' => 1, 'b' => [true, null, 'x']], self::call('unwrap', ['a' => 1, 'b' => [true, null, 'x']]));
    }

    public function testARefForAFinalClassComesBackAsABareHandle(): void
    {
        $handle = self::call('wrap', ['__ref' => [3, 11, 'CurlHandle']]);

        self::assertInstanceOf(Handle::class, $handle, 'CurlHandle is final, so there is no proxy subclass to make');
        self::assertSame(3, $handle->worker);
        self::assertSame(11, $handle->id);
        self::assertSame('CurlHandle', $handle->class);
    }

    public function testARefForANonFinalClassComesBackAsAProxyOfIt(): void
    {
        $proxy = self::call('wrap', ['__ref' => [1, 42, 'IgnisOffloadProxySubject']]);

        self::assertInstanceOf(\IgnisOffloadProxySubject::class, $proxy, 'instanceof and the class constants come from the parent for free');
        self::assertSame('Ignis\Offload\Proxy\IgnisOffloadProxySubject', $proxy::class);
        self::assertSame(42, $proxy->__ignisHandle->id);
        self::assertSame('subject', $proxy::TAG);
    }

    public function testWrapRecursesAndLeavesAnythingThatIsNotARefAlone(): void
    {
        $wrapped = self::call('wrap', ['rows' => [['__ref' => [0, 1, 'CurlHandle']], ['__ref' => [0, 1, 'CurlHandle'], 'extra' => 1]]]);

        self::assertInstanceOf(Handle::class, $wrapped['rows'][0]);
        self::assertSame(['__ref' => [0, 1, 'CurlHandle'], 'extra' => 1], $wrapped['rows'][1], 'a ref is a ref only when it is the single key');
    }

    public function testAffinityIsTheWorkerOfTheFirstHandleAmongTheArguments(): void
    {
        $proxy = new \stdClass();
        $proxy->__ignisHandle = new Handle(2, 9, 'PDO');

        self::assertSame(1, self::call('affinityOf', ['a', new Handle(1, 5, 'CurlHandle'), $proxy]));
        self::assertSame(2, self::call('affinityOf', [$proxy]));
        self::assertNull(self::call('affinityOf', ['a', 1, [new Handle(4, 1, 'PDO')]]), 'a handle nested inside an array argument does not pin the worker');
    }

    public function testPickIsRoundRobinOverTheWorkerCount(): void
    {
        FakeOffload::$workers = 3;
        self::assertSame([0, 1, 2, 0], [self::call('pick'), self::call('pick'), self::call('pick'), self::call('pick')]);

        FakeOffload::$workers = 0;
        self::assertSame(1 % 1, self::call('pick'), 'no workers still has to name one: max(1, …) keeps the modulo alive');
    }

    public function testDispatchLetsACallThatHoldsNoneOfOurHandlesRunUnrouted(): void
    {
        $before = Router::$routed;

        self::assertNull(Router::dispatch('file_get_contents', ['/etc/hostname']));
        self::assertSame(1, FakeOffload::$passed, 'ignis_route_pass() tells the runtime to run the original');
        self::assertSame($before, Router::$routed);
        self::assertSame([], FakeOffload::$submitted, 'and nothing was sent to a worker');
    }

    public function testTypeStringRendersEveryKindOfTypeTheProxyHasToRepeat(): void
    {
        $subject = \IgnisOffloadProxySubject::class;
        $returns = static function (string $method) use ($subject): string {
            $type = (new \ReflectionMethod($subject, $method))->getReturnType();
            self::assertNotNull($type);

            return self::call('typeString', $type, $subject);
        };

        self::assertSame('string', $returns('plain'), 'a builtin stays bare');
        self::assertSame('?\DateTimeImmutable', $returns('nullableClass'), 'a class name is made absolute and keeps its ?');
        self::assertSame('string|int|null', $returns('union'), 'a union carries its own null and must not gain a ?; the order is the engine\'s own normalisation');
        self::assertSame('\Countable&\ArrayAccess', $returns('intersection'));
        self::assertSame('\\' . $subject, $returns('selfType'), 'self resolves to the class being proxied, not to the proxy');
        self::assertSame('static', $returns('staticType'), 'static stays late-bound');
        self::assertSame('void', $returns('byReference'));
    }

    public function testProxyClassGeneratesAnLspCompatibleOverridePerPublicMethod(): void
    {
        Router::proxyClass(\IgnisOffloadProxySubject::class);
        $proxy = new \ReflectionClass('Ignis\Offload\Proxy\IgnisOffloadProxySubject');

        $declared = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            array_filter($proxy->getMethods(), static fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $proxy->getName()),
        );
        sort($declared);
        self::assertSame(
            ['__construct', '__destruct', 'byReference', 'fromHandle', 'intersection', 'nullableClass', 'plain', 'selfType', 'staticType', 'union', 'untyped'],
            $declared,
            'a static method, a final method and the parent constructor are skipped; the three of its own are added',
        );

        $plain = $proxy->getMethod('plain');
        self::assertSame('string', (string) $plain->getReturnType());
        self::assertSame('b', $plain->getParameters()[1]->getDefaultValue(), 'a default value is copied, or the override would not be callable the same way');

        $byReference = $proxy->getMethod('byReference');
        self::assertTrue($byReference->getParameters()[0]->isPassedByReference());
        self::assertTrue($byReference->getParameters()[1]->isVariadic());

        self::assertSame('?DateTimeImmutable', (string) $proxy->getMethod('nullableClass')->getReturnType());
        self::assertSame('string|int|null', (string) $proxy->getMethod('union')->getReturnType());
        self::assertNull($proxy->getMethod('untyped')->getReturnType(), 'no return type on the parent means none on the override');
    }

    public function testProxyClassIsIdempotentAndIgnoresAClassThatIsNotLoaded(): void
    {
        Router::proxyClass(\IgnisOffloadProxySubject::class);
        Router::proxyClass(\IgnisOffloadProxySubject::class);   // a second eval of the same name would be a fatal
        Router::proxyClass('NoSuchClassAnywhere');

        self::assertFalse(class_exists('Ignis\Offload\Proxy\NoSuchClassAnywhere', false));
        self::assertTrue(class_exists('Ignis\Offload\Proxy\IgnisOffloadProxySubject', false));
    }

    public function testReleasingAHandleIsFireAndForget(): void
    {
        $handle = new Handle(1, 77, 'CurlHandle');
        Router::release($handle);

        self::assertCount(1, FakeOffload::$submitted);
        $job = FakeOffload::$submitted[0];
        self::assertSame('Ignis\Offload\WorkerRuntime::routed', $job['fn']);
        self::assertSame(1, $job['affinity'], 'the free has to land on the worker that holds the object');
        self::assertSame(['free', [['__ref' => [1, 77, 'CurlHandle']]]], unserialize($job['args']));
    }

    /**
     * DEFECT (minor, pinned). `Router::release()` does not mark the handle released, and a proxy
     * holds the `Handle` it was built from. So a proxy going out of scope frees the remote object
     * twice: once from the generated `Proxy\<Class>::__destruct()`, then again from
     * `Handle::__destruct()`, which still sees `$released === false`. `WorkerRuntime::routed('free')`
     * is an `unset()`, so nothing breaks — every proxied object just costs two offload submissions
     * to let go of instead of one.
     */
    public function testAProxyReleasesItsRemoteObjectTwiceBug(): void
    {
        $proxy = self::call('wrap', ['__ref' => [1, 88, 'IgnisOffloadProxySubject']]);
        FakeOffload::reset();

        unset($proxy);

        self::assertCount(2, FakeOffload::$submitted, 'one free from the proxy destructor, one from the handle it still holds');
        self::assertSame(
            [['free', [['__ref' => [1, 88, 'IgnisOffloadProxySubject']]]], ['free', [['__ref' => [1, 88, 'IgnisOffloadProxySubject']]]]],
            array_map(static fn(array $job): mixed => unserialize($job['args']), FakeOffload::$submitted),
        );
    }

    private static function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(Router::class, $method))->invoke(null, ...$args);
    }
}
