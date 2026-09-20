<?php

// V-107: a gRPC call refused by admission control must come back as a gRPC status, not hang.
//
// The scheduler answers a rejected request with HTTP 503 before it knows what transport the call
// arrived on. A gRPC id is not a whole-body id, so the reactor refused that answer and nobody ever
// closed the stream: three of five concurrent calls waited for ever with nothing logged. The
// transport now maps a refusal onto its own wire. Needs no grpcurl -- the client is ours.
declare(strict_types=1);
require __DIR__ . '/../../php/packages/runtime/src/ignis.php';
require __DIR__ . '/../../php/packages/grpc/src/ignis-grpc.php';

use Ignis\Grpc\Client;
use Ignis\Grpc\Proto;
use Ignis\Grpc\StatusException;

$client = new Client('http://' . getenv('TARGET'));
$n = (int) (getenv('N') ?: 5);
$done = [];
for ($i = 0; $i < $n; $i++) {
    Ignis\async(static function () use ($client, $i, &$done): void {
        $started = hrtime(true);
        try {
            $reply = $client->unary('/ignis.Greeter/Slow', Proto::encode([1 => "c$i"]));
            $done[$i] = sprintf("OK     %6.0fms  %s", (hrtime(true) - $started) / 1e6, Proto::decode($reply)[1] ?? '?');
        } catch (StatusException $e) {
            $done[$i] = sprintf("STATUS %6.0fms  code=%d %s", (hrtime(true) - $started) / 1e6, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            $done[$i] = sprintf("%s %6.0fms  %s", $e::class, (hrtime(true) - $started) / 1e6, $e->getMessage());
        }
    });
}
Ignis\all([Ignis\async(static function () use ($n, &$done): void {
    Ignis\sleep((int) (getenv('WAIT_MS') ?: 6000));
    $answered = 0;
    $refused = 0;
    for ($i = 0; $i < $n; $i++) {
        $line = $done[$i] ?? 'HUNG: no answer of any kind';
        printf("  call %d: %s\n", $i, $line);
        $answered += str_starts_with($line, 'OK') ? 1 : 0;
        $refused += str_contains($line, 'code=14') ? 1 : 0;
    }
    printf("grpc_refused: answered=%d refused=%d hung=%d\n", $answered, $refused, $n - $answered - $refused);
})]);
