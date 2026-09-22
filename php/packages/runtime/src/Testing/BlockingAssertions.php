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
    /** Runs $function on the loop and returns its result, failing the test on an unallowed blocking call. */
    protected function assertNoBlockingCalls(callable $function, string $message = ''): mixed
    {
        if (!\function_exists('ignis_blocking_sequence')) {
            return $function();
        }
        $sequenceBeforeCall = \ignis_blocking_sequence();
        $result = \Ignis\async($function)->await();
        $unallowedRecords = $this->ignisUnallowedBlockingRecords($sequenceBeforeCall);
        if ($unallowedRecords !== []) {
            Assert::fail(($message === '' ? '' : $message . "\n") . $this->ignisBlockingRecordsAsText($unallowedRecords));
        }

        return $result;
    }

    /**
     * This thread's blocking records newer than $sequence, in the shape `ignis_blocking_records()`
     * returns them.
     * @return list<array{sequence:int,site:string,duration_us:int,errno:int,request:int,uri:string,allowed:bool,trace:list<string>}>
     */
    protected function ignisBlockingRecordsSince(int $sequence): array
    {
        if (!\function_exists('ignis_blocking_records')) {
            return [];
        }

        return \ignis_blocking_records($sequence);
    }

    /** @return list<array{sequence:int,site:string,duration_us:int,errno:int,request:int,uri:string,allowed:bool,trace:list<string>}> */
    private function ignisUnallowedBlockingRecords(int $sequenceBeforeCall): array
    {
        return \array_values(\array_filter(
            $this->ignisBlockingRecordsSince($sequenceBeforeCall),
            static fn(array $record): bool => !$record['allowed'],
        ));
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
