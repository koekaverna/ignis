<?php

declare(strict_types=1);

namespace Ignis\Testing;

use PHPUnit\Framework\Assert;

/**
 * ADR-0043 §5/research 50 S-3: unit-level blocking audit for a PHPUnit test case — fails with the
 * site list when a run made a blocking call the detector did not allow. A no-op without the binary.
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
