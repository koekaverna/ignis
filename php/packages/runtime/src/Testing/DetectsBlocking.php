<?php

declare(strict_types=1);

namespace Ignis\Testing;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * ADR-0043 §5/research 50 S-3 (C-2): gives an existing PHPUnit test case a blocking-call audit,
 * with no change to its own tests — `use DetectsBlocking;` on a Symfony `WebTestCase` turns every
 * `KernelBrowser::request()` in it into an audit. A no-op without the binary's detector.
 */
trait DetectsBlocking
{
    private int $ignisBlockingWatchSequence = 0;

    #[Before]
    protected function ignisStartBlockingWatch(): void
    {
        $this->ignisBlockingWatchSequence = \function_exists('ignis_blocking_sequence') ? \ignis_blocking_sequence() : 0;
    }

    #[After]
    protected function ignisAssertNoBlockingCalls(): void
    {
        if (!\function_exists('ignis_blocking_records')) {
            return;
        }
        $unallowedRecords = \array_values(\array_filter(
            \ignis_blocking_records($this->ignisBlockingWatchSequence),
            static fn(array $record): bool => !$record['allowed'],
        ));
        if ($unallowedRecords === []) {
            return;
        }
        Assert::fail("blocking calls made during this test:\n" . $this->ignisBlockingRecordsAsText($unallowedRecords));
    }

    /** @param list<array{site:string,duration_us:int,uri:string,trace:list<string>}> $records */
    private function ignisBlockingRecordsAsText(array $records): string
    {
        $lines = [];
        foreach ($records as $record) {
            $lines[] = \sprintf('%s %d %s', $record['site'], $record['duration_us'], $record['uri']);
            foreach ($record['trace'] as $frame) {
                $lines[] = '  ' . $frame;
            }
        }

        return \implode("\n", $lines);
    }
}
