<?php

declare(strict_types=1);

namespace Ignis\Testing;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * ADR-0043 §5/research 50 S-3 (C-2): gives an existing PHPUnit test case a blocking-call audit —
 * every unallowed blocking call the detector recorded on this thread during the test fails it
 * from an `#[After]` hook, with the site list.
 *
 * The detector records only what runs inside a fiber, and PHPUnit 12 runs a test method on its
 * own context (`TestCase::runTest()` is private, so no trait or extension can wrap it). Code the
 * test drives itself — a Symfony `KernelBrowser::request()`, a service call — is therefore audited
 * only through `$this->ignisAudited(fn () => …)`, which runs it on the loop; the natural place for
 * a `WebTestCase` is one `request()` override in its base class. Requests the application serves
 * on the loop meanwhile are in the records regardless. A no-op without the binary's detector.
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
