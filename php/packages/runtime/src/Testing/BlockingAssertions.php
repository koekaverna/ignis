<?php

declare(strict_types=1);

namespace Ignis\Testing;

use PHPUnit\Framework\Assert;

/**
 * ADR-0043 §5/research 50 S-3: unit-level blocking audit for a PHPUnit test case. Runs a callable
 * on the loop and fails with the site list when it made a blocking call the detector did not
 * allow — `file_get_contents('/etc/hostname')` fails, `file_get_contents('http://…')` (parks)
 * passes. A no-op, still running $function, when the binary has no detector.
 */
trait BlockingAssertions
{
    use BlockingAudit;

    /** Runs $function on the loop and returns its result, failing the test on an unallowed blocking call. */
    protected function assertNoBlockingCalls(callable $function, string $message = ''): mixed
    {
        if (!\function_exists('ignis_blocking_sequence')) {
            return $function();
        }
        $sequenceBeforeCall = \ignis_blocking_sequence();
        $result = $this->ignisAudited($function);
        $unallowedRecords = $this->ignisUnallowedBlockingRecordsSince($sequenceBeforeCall);
        if ($unallowedRecords !== []) {
            Assert::fail(($message === '' ? '' : $message . "\n") . $this->ignisBlockingRecordsAsText($unallowedRecords));
        }

        return $result;
    }
}
