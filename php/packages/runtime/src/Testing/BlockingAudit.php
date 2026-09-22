<?php

declare(strict_types=1);

namespace Ignis\Testing;

/**
 * What `BlockingAssertions` and `DetectsBlocking` share (ADR-0043 §5, research 50 S-3): running
 * code where the detector can see it, reading its records back and printing them. One trait, so a
 * test case may use both without a method collision.
 */
trait BlockingAudit
{
    /**
     * Runs $function where the detector records blocking calls — inside a fiber on the loop — and
     * returns its result. The detector's gate is the fiber: a call made on the test's own context
     * is never a record. Without the binary the callable simply runs.
     */
    protected function ignisAudited(callable $function): mixed
    {
        if (!\function_exists('ignis_blocking_sequence')) {
            return $function();
        }

        return \Ignis\async($function)->await();
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
    private function ignisUnallowedBlockingRecordsSince(int $sequence): array
    {
        return \array_values(\array_filter(
            $this->ignisBlockingRecordsSince($sequence),
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
