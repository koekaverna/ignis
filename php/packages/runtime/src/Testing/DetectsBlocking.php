<?php

declare(strict_types=1);

namespace Ignis\Testing;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * ADR-0043 §5/research 50 S-3 (C-2): gives a PHPUnit test case a blocking-call audit, failing an
 * unallowed blocking call from an `#[After]` hook; code the test drives itself needs `ignisAudited()`.
 */
trait DetectsBlocking
{
    use BlockingAudit;

    private int $ignisBlockingWatchSequence = 0;

    #[Before]
    protected function ignisStartBlockingWatch(): void
    {
        $this->ignisBlockingWatchSequence = \function_exists('ignis_blocking_sequence') ? \ignis_blocking_sequence() : 0;
    }

    #[After]
    protected function ignisAssertNoBlockingCalls(): void
    {
        $unallowedRecords = $this->ignisUnallowedBlockingRecordsSince($this->ignisBlockingWatchSequence);
        if ($unallowedRecords === []) {
            return;
        }
        Assert::fail("blocking calls made during this test:\n" . $this->ignisBlockingRecordsAsText($unallowedRecords));
    }
}
