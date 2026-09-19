<?php

declare(strict_types=1);

namespace Ignis\Tests\Offload;

use Ignis\Offload\CallbackRef;
use Ignis\Offload\Client;
use Ignis\Offload\RemoteException;
use Ignis\Offload\WorkerRuntime;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fake-offload.php';
require_once \dirname(__DIR__) . '/src/worker.php';

/**
 * A-DUPES(a): `CallbackRef` and `RemoteException` are declared once each in `ignis-offload.php`
 * and once each in `worker.php`, guarded because the two files can never both run in the process
 * that matters (the real worker thread never sees `ignis-offload.php` at all, and a test process
 * that requires both would hit "cannot redeclare"). `testCallbackRefIsDeclaredIdenticallyOnBothSides`
 * and its `RemoteException` counterpart are what stands in for "one declaration": the two copies
 * cannot become one file (see the doc block on the guard in either source file), so this is what
 * stops them drifting apart the way their error envelopes already had — `RemoteException::$remoteTrace`
 * was always empty for a callback failure while the job path carried it.
 *
 * `testAFailedCallbackCarriesItsCallerSideStackBackToTheWorker` is the regression test for that
 * bug: `Client::runCallback()` (the caller side, in `ignis-offload.php`) now puts the failing
 * closure's `getTraceAsString()` in the envelope, and `WorkerRuntime::unpackCallbackAnswer()` (the
 * worker side, in `worker.php`) now reads it back into `RemoteException::$remoteTrace`.
 */
final class EnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        FakeOffload::reset();
    }

    public function testCallbackRefIsDeclaredIdenticallyOnBothSides(): void
    {
        self::assertSame(
            self::classTokens(\dirname(__DIR__) . '/src/ignis-offload.php', 'CallbackRef'),
            self::classTokens(\dirname(__DIR__) . '/src/worker.php', 'CallbackRef'),
        );
    }

    public function testRemoteExceptionIsDeclaredIdenticallyOnBothSides(): void
    {
        self::assertSame(
            self::classTokens(\dirname(__DIR__) . '/src/ignis-offload.php', 'RemoteException'),
            self::classTokens(\dirname(__DIR__) . '/src/worker.php', 'RemoteException'),
        );
    }

    public function testAFailedCallbackCarriesItsCallerSideStackBackToTheWorker(): void
    {
        $thrower = static function (): never {
            throw new \DomainException('bad row');
        };
        (new \ReflectionProperty(Client::class, 'pending'))->setValue(null, [9 => $thrower]);

        (new \ReflectionMethod(Client::class, 'runCallback'))->invoke(null, [
            'job' => 1, 'seq' => 1, 'cb' => 9, 'args' => serialize([]),
        ]);
        (new \ReflectionProperty(Client::class, 'pending'))->setValue(null, []);

        $envelope = FakeOffload::$callbackResults[0]['result'] ?? null;
        self::assertIsString($envelope, 'Client::runCallback() must answer ignis_offload_cb_result() with a serialized envelope');
        FakeOffload::$callbackAnswer = $envelope;

        WorkerRuntime::$job = 1;
        $bound = WorkerRuntime::bindCallbacks(new CallbackRef(9));
        self::assertInstanceOf(\Closure::class, $bound);

        try {
            $bound();
            self::fail('a callback that threw on the caller must not look like a return');
        } catch (RemoteException $e) {
            self::assertSame(\DomainException::class, $e->remoteClass);
            self::assertSame('callback threw: bad row', $e->getMessage());
            self::assertNotSame('', $e->remoteTrace, 'the trace must survive the round trip, not just the message');
            self::assertStringContainsString(__FUNCTION__, $e->remoteTrace, 'the stack has to be the one from where the callback actually threw');
        }
    }

    /**
     * The tokens of `final class $class { ... }` as declared in `$file`, whitespace and comments
     * dropped — the two files nest the guard at different depths, so indentation differs even when
     * the declaration does not.
     *
     * @return list<int|string>
     */
    private static function classTokens(string $file, string $class): array
    {
        $tokens = \token_get_all((string) \file_get_contents($file));
        $classIndex = null;
        foreach ($tokens as $index => $token) {
            if (\is_array($token) && $token[0] === \T_CLASS && self::nextSignificantText($tokens, $index) === $class) {
                $classIndex = $index;
                break;
            }
        }
        self::assertIsInt($classIndex, "class {$class} is not declared in {$file}");

        $depth = 0;
        $opened = false;
        $collected = [];
        for ($index = $classIndex; $index < \count($tokens); $index++) {
            $token = $tokens[$index];
            $text = \is_array($token) ? $token[1] : $token;
            if (!(\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true))) {
                $collected[] = $text;
            }
            if ($text === '{') {
                $depth++;
                $opened = true;
            } elseif ($text === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    break;
                }
            }
        }

        return $collected;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function nextSignificantText(array $tokens, int $index): ?string
    {
        for ($cursor = $index + 1; $cursor < \count($tokens); $cursor++) {
            $token = $tokens[$cursor];
            if (\is_array($token) && $token[0] === \T_WHITESPACE) {
                continue;
            }
            return \is_array($token) ? $token[1] : $token;
        }
        return null;
    }
}
