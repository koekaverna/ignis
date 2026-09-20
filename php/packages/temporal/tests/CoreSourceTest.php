<?php

declare(strict_types=1);

namespace Ignis\Tests\Temporal;

use Ignis\Temporal\CoreSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `CoreSource` trusts a reactor completion and a connect document only after these checks
 * (PHPStan level 9, S4-MIXED): both used to index and cast a `mixed` op result as if the shape
 * were guaranteed.
 */
#[CoversClass(CoreSource::class)]
final class CoreSourceTest extends TestCase
{
    private static function reflect(string $method): \ReflectionMethod
    {
        return new \ReflectionMethod(CoreSource::class, $method);
    }

    // ---- activationOf: only end of stream is a shutdown ----

    /**
     * `poll()` used to catch every `RuntimeException` and report a clean shutdown, so a worker that
     * lost its connection to the server exited as if it had been asked to. sdk-core names exactly
     * two ways a poll stops and says of the second "lang should consider this fatal"; the runtime
     * now sends `null` for the first and an error for the second (V-109).
     */
    public function testANullCompletionIsTheShutdownSignalAndOnlyThat(): void
    {
        self::assertNull(self::reflect('activationOf')->invoke(null, null));
    }

    public function testAnActivationIsPassedThrough(): void
    {
        self::assertSame('{"runId":"r"}', self::reflect('activationOf')->invoke(null, '{"runId":"r"}'));
    }

    public function testATransportFailureIsRaisedInsteadOfBeingReportedAsAShutdown(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('poll: status: Unavailable');

        self::reflect('activationOf')->invoke(null, ['kind' => 'error', 'message' => 'poll: status: Unavailable']);
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
        $this->expectExceptionMessage('temporal op failed');

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

    // ---- workerIdOf: the connect result's {"worker": id} document ----

    #[DataProvider('malformedWorkerIdDocuments')]
    public function testAMalformedWorkerIdDocumentIsRejected(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporal connect did not return a "worker" id');

        self::reflect('workerIdOf')->invoke(null, $json);
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
        self::assertSame(7, self::reflect('workerIdOf')->invoke(null, '{"worker":7}'));
    }
}
