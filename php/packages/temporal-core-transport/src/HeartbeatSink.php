<?php

/**
 * Optional companion to ActivationSource: a host that can record activity heartbeats.
 *
 * Separate from `ActivationSource` on purpose — polling activations and recording a heartbeat are
 * different capabilities, and a host that has only the first (a recorded-activation test, a replay
 * worker) stays valid. `CoreRpc` checks for this interface and tells sdk-php plainly when it is
 * missing, instead of pretending the heartbeat was recorded.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

interface HeartbeatSink
{
    /**
     * @param string $taskToken raw bytes of the activity task token
     * @param list<array{metadata:array<string,string>,data:string}> $details
     * @return array{canceled?:bool,paused?:bool,reset?:bool} what sdk-php reads back; an empty
     *         array means "still running" — core reports cancellation on the activity task stream,
     *         not through the heartbeat's return value.
     */
    public function heartbeat(string $taskToken, array $details): array;
}
