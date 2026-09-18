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
