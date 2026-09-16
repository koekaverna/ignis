<?php
// E10 demo: gRPC service written in PHP, served on the same listener as HTTP (examples/grpc/greeter.proto).
declare(strict_types=1);
require __DIR__ . '/../php/ignis.php';
require __DIR__ . '/../php/grpc/ignis-grpc.php';

use Ignis\Grpc\Call;
use Ignis\Grpc\Client;
use Ignis\Grpc\Proto;
use Ignis\Http\Request;
use Ignis\Http\Response;

$addr = getenv('IGNIS_ADDR') ?: '127.0.0.1:8080';
$self = new Client('http://' . $addr);

$methods = [
    '/ignis.Greeter/SayHello' => static function (Call $call): string {
        $name = (string) (Proto::decode($call->message())[1] ?? 'world');
        return Proto::encode([1 => "hello $name"]);
    },
    '/ignis.Greeter/Countdown' => static function (Call $call): null {
        $n = (int) (Proto::decode($call->message())[1] ?? 3);
        for ($i = $n; $i >= 1; $i--) {
            $call->send(Proto::encode([1 => $i, 2 => date('H:i:s') . '.' . sprintf('%03d', intdiv(hrtime(true), 1000000) % 1000)]));
            Ignis\sleep(10);
        }
        return null;
    },
    '/ignis.Greeter/Slow' => static function (Call $call): string {
        Ignis\sleep(200);
        $name = (string) (Proto::decode($call->message())[1] ?? 'world');
        return Proto::encode([1 => "slow hello $name"]);
    },
    '/ignis.Greeter/Proxy' => static function (Call $call) use ($self): string {
        // The client call parks this fiber; the thread keeps serving other calls meanwhile (H21b).
        $reply = $self->unary('/ignis.Greeter/Slow', $call->message());
        return Proto::encode([1 => 'via proxy: ' . (Proto::decode($reply)[1] ?? '?')]);
    },
];

$http = static function (Request $r): Response {
    if ($r->path() === '/stats') {
        return Response::json(ignis_stats() + ['resumes' => Ignis\Loop::$resumes]);
    }
    return Response::text("Ignis gRPC demo: use grpcurl -plaintext -proto examples/grpc/greeter.proto\n");
};

Ignis\serve(Ignis\Grpc\router($methods, $http), $addr);
