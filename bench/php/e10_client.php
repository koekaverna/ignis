<?php

// E10, without grpcurl or ghz: the runtime's own client against examples/grpc_server.php. Unary,
// server-streaming, and the arm that proves the client parks the fiber (H21b) — N concurrent Proxy
// calls, each awaiting a 200 ms Slow call through the client on one thread, must finish together.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/grpc/src/ignis-grpc.php';

use Ignis\Grpc\Client;
use Ignis\Grpc\Proto;

$client = new Client('http://' . getenv('TARGET'));
$n = (int) (getenv('N') ?: 10);

$unary = Ignis\async(static fn(): string => (string) (Proto::decode($client->unary('/ignis.Greeter/SayHello', Proto::encode([1 => 'ada'])))[1] ?? ''));
$stream = Ignis\async(static function () use ($client): array {
    $seen = [];
    foreach ($client->serverStream('/ignis.Greeter/Countdown', Proto::encode([1 => 3])) as $message) {
        $seen[] = (int) (Proto::decode($message)[1] ?? -1);
    }
    return $seen;
});
$started = hrtime(true);
$proxies = [];
for ($i = 0; $i < $n; $i++) {
    $proxies[] = Ignis\async(static fn(): string => (string) (Proto::decode($client->unary('/ignis.Greeter/Proxy', Proto::encode([1 => "p$i"])))[1] ?? ''));
}
$replies = Ignis\all($proxies);
$wallMs = (int) ((hrtime(true) - $started) / 1e6);

$viaProxy = count(array_filter($replies, static fn(string $r): bool => str_starts_with($r, 'via proxy: slow hello p')));
printf(
    "e10_client: unary=%s stream=%s proxy_ok=%d/%d wall_ms=%d\n",
    $unary->await() === 'hello ada' ? 'ok' : 'FAIL(' . $unary->await() . ')',
    $stream->await() === [3, 2, 1] ? 'ok' : 'FAIL(' . implode(',', $stream->await()) . ')',
    $viaProxy,
    $n,
    $wallMs,
);
