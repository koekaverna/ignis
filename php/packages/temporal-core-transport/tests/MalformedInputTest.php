<?php

declare(strict_types=1);

namespace Ignis\Tests\Temporal\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Client\GRPC\Context;
use Temporal\DataConverter\DataConverter;
use Temporal\Worker\Transport\Core\ActivationSource;
use Temporal\Worker\Transport\Core\CoreCodec;
use Temporal\Worker\Transport\Core\CoreServiceClient;
use Temporal\Worker\Transport\Core\ServiceCall;

/**
 * The two places where an untyped value used to reach a fatal instead of a message.
 *
 * sdk-php correlates every outgoing request by the id this codec puts on the incoming one, so a
 * core document that does not name its run or its activity would give every request of every run
 * the same empty id — and `interceptCall()` is handed an `object` by sdk-php's interface while the
 * only thing it can put on the wire is protobuf.
 */
#[CoversClass(CoreCodec::class)]
#[CoversClass(CoreServiceClient::class)]
final class MalformedInputTest extends TestCase
{
    public function testAnActivationIsIdentifiedByItsRunId(): void
    {
        $requests = $this->codec()->decode(self::json([
            'runId' => 'run-1',
            'historyLength' => 1,
            'jobs' => [['initializeWorkflow' => ['workflowId' => 'wf-1', 'workflowType' => 'Greet']]],
        ]));

        $ids = [];
        foreach ($requests as $request) {
            $ids[] = $request->getID();
        }

        self::assertSame(['run-1'], $ids);
    }

    public function testAnActivationWithoutARunIdIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('runId');

        $this->codec()->decode(self::json(['historyLength' => 1, 'jobs' => []]));
    }

    public function testAnActivityTaskWithoutAnActivityIdIsRejected(): void
    {
        $codec = $this->codec();
        $codec->forBatch(ActivationSource::ACTIVITY);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('start.activityId');

        $codec->decode(self::json(['taskToken' => 'dG9rZW4=', 'start' => ['activityType' => 'Greet']]));
    }

    public function testANonProtobufRequestIsRejectedByName(): void
    {
        $client = new CoreServiceClient(new class implements ServiceCall {
            public function call(string $path, string $request): string
            {
                throw new \LogicException('the transport must refuse before it reaches the host');
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stdClass');

        $client->interceptCall(
            'GetSystemInfo',
            new \stdClass(),
            Context::default(),
            static fn(): object => new \stdClass(),
        );
    }

    private function codec(): CoreCodec
    {
        return new CoreCodec(DataConverter::createDefault(), 'ignis', 'default');
    }

    /** @param array<string, mixed> $document */
    private static function json(array $document): string
    {
        return \json_encode($document, \JSON_THROW_ON_ERROR);
    }
}
