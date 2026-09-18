<?php

/**
 * sdk-php's third seam: `RPCConnectionInterface`, the side channel an activity uses for things that
 * are not commands. Under RoadRunner it is Goridge to the plugin; here it reaches the host.
 *
 * Only `temporal.RecordActivityHeartbeat` is implemented, because it is the only one an activity
 * body reaches on its own. Anything else throws by name rather than returning a plausible empty
 * answer — an activity that believes it heartbeated and did not is a task that dies at its timeout
 * for no visible reason.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

use Temporal\Api\Common\V1\Payloads;
use Temporal\Exception\TransportException;
use Temporal\Worker\Transport\RPCConnectionInterface;

final class CoreRpc implements RPCConnectionInterface
{
    public function __construct(private readonly ActivationSource $source) {}

    public function call(string $method, $payload)
    {
        if ($method !== 'temporal.RecordActivityHeartbeat') {
            throw new TransportException(\sprintf(
                'RPC "%s" is not available on the core transport (only temporal.RecordActivityHeartbeat is).',
                $method,
            ));
        }
        if (!$this->source instanceof HeartbeatSink) {
            throw new TransportException('this host cannot record activity heartbeats (no HeartbeatSink)');
        }
        if (!\is_array($payload) || !\is_string($payload['details'] ?? null) || !\is_string($payload['taskToken'] ?? null)) {
            throw new TransportException('the "temporal.RecordActivityHeartbeat" payload must be array{taskToken: string, details: string}');
        }

        $details = self::payloadsFromWireBytes($payload['details']);

        $out = [];
        foreach ($details->getPayloads() as $payloadMessage) {
            $metadata = [];
            foreach ($payloadMessage->getMetadata() as $k => $v) {
                if (\is_string($k) && \is_string($v)) {
                    $metadata[$k] = \base64_encode($v);
                }
            }
            $out[] = ['metadata' => $metadata, 'data' => \base64_encode($payloadMessage->getData())];
        }

        return $this->source->heartbeat(\base64_decode($payload['taskToken']), $out);
    }

    /**
     * sdk-php hands the details over as wire bytes of a Payloads message — the one place its API
     * forces the protobuf wire format on us. Decoding it here keeps the host's contract in the
     * same plain shape as everything else.
     */
    private static function payloadsFromWireBytes(string $base64): Payloads
    {
        $details = new Payloads();
        $details->mergeFromString(\base64_decode($base64));

        return $details;
    }
}
