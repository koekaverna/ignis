# `ignis/grpc`

gRPC handlers and clients in PHP, on the listener the HTTP server already has (E10).

## What it does

tonic serves h2 on the same socket as the HTTP front door, and a gRPC method arrives in PHP as an
ordinary fiber. No second server, no `ext-grpc` — which matters more than it sounds: the C core is
a client-only extension, so it cannot serve these handlers at all, and building it costs a C++
toolchain and a three-figure number of megabytes.

## Install

```
composer require ignis/grpc:@dev
```

## Serve

```php
require '/opt/ignis/php/packages/grpc/src/ignis-grpc.php';

use Ignis\Grpc\{Call, Proto};

Ignis\serve(Ignis\Grpc\router([
    // a unary method returns the reply bytes; the router sends them and closes the stream
    '/ignis.Greeter/SayHello' => static fn (Call $call): string
        => Proto::encode([1 => 'hello ' . (Proto::decode($call->message())[1] ?? 'world')]),

    // a streaming method sends its own messages and returns null
    '/ignis.Greeter/Countdown' => static function (Call $call): null {
        for ($i = (int) (Proto::decode($call->message())[1] ?? 3); $i >= 1; $i--) {
            $call->send(Proto::encode([1 => $i]));
            Ignis\sleep(10);
        }
        return null;
    },
]), '0.0.0.0:8080');
```

`Call` carries the method, the message bytes and the metadata. A handler that returns a string has
it sent and the stream closed for it; one that returns `null` has already sent whatever it wanted
through `$call->send()`. Throwing `Ignis\Grpc\StatusException` sets the status, a cancelled request
becomes `CANCELLED`, and any other throwable becomes `INTERNAL` with its class and message —
the router does all of that, so a handler is the two lines above. Messages cross as opaque bytes, so `google/protobuf`
generated classes work, and the small `Proto` helper is there for cases that do not need them.

## Call

```php
$reply = (new Ignis\Grpc\Client('http://backend:50051'))->unary('/greeter.Greeter/SayHello', $bytes);
```

`serverStream()` returns a `\Generator` of messages. The calling fiber parks for the round trip;
the thread keeps serving.

## Configure

Nothing of its own: the listener, threads and limits are the runtime's
([configuration](../reference/configuration.md)). gRPC is detected per request by content type, so
one address serves both protocols.

## Limits

Unary and server-streaming only — client-streaming and bidirectional are not implemented, and TLS
on the listener is not either (terminate it in front). Deadlines arrive as tonic's `grpc-timeout`;
client metadata and compression are untouched.
