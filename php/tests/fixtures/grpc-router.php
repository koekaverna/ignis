<?php

declare(strict_types=1);

/**
 * One gRPC dispatch through `Ignis\Grpc\router()`, in a process of its own.
 *
 * It exists because `packages/runtime/stubs/ignis.php` defines `ignis_grpc_send`/`ignis_grpc_end`
 * as stubs that throw, and PHPUnit's bootstrap has already loaded them. Declaring them here, before
 * the autoloader, makes the guards in the stub file no-ops — the same trick `tests/bootstrap.php`
 * uses for the fake reactor — so the router runs to its `return` statement instead of dying on the
 * first stub call. Whatever comes back is printed on stdout, one `key=value` line at a time, and
 * `Ignis\Tests\Grpc\RouterTest` asserts on it.
 *
 *   php tests/fixtures/grpc-router.php <case>
 *
 * Cases: not-grpc, not-grpc-fallback, unknown-method, unary, status-exception, cancelled.
 */
function ignis_grpc_send(int $id, string $message): bool
{
    echo 'send=', $id, ':', $message, "\n";

    return true;
}

function ignis_grpc_end(int $id, int $code, string $message): bool
{
    echo 'end=', $id, ':', $code, ':', $message, "\n";

    return true;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ignis\CancelledException;
use Ignis\Grpc\Call;
use Ignis\Grpc\Status;
use Ignis\Grpc\StatusException;
use Ignis\Http\Request;
use Ignis\Http\Response;

$case = $argv[1] ?? '';
$grpc = ['content-type' => 'application/grpc+proto'];

$methods = [
    '/pkg.Svc/Unary' => static fn(Call $call): string => 'reply:' . $call->message(),
    '/pkg.Svc/Boom' => static function (Call $call): string {
        throw new StatusException(Status::INVALID_ARGUMENT, 'bad argument');
    },
    '/pkg.Svc/Gone' => static function (Call $call): string {
        throw new CancelledException('client went away');
    },
];

[$request, $fallback] = match ($case) {
    'not-grpc' => [new Request('POST', '/pkg.Svc/Unary', ['content-type' => 'application/json'], '', 7), null],
    'not-grpc-fallback' => [new Request('GET', '/health', [], '', 7), static fn(Request $r): Response => Response::text("ok\n")],
    'unknown-method' => [new Request('POST', '/pkg.Svc/Nope', $grpc, '', 7), null],
    'unary' => [new Request('POST', '/pkg.Svc/Unary', $grpc, 'ping', 7), null],
    'status-exception' => [new Request('POST', '/pkg.Svc/Boom', $grpc, '', 7), null],
    'cancelled' => [new Request('POST', '/pkg.Svc/Gone', $grpc, '', 7), null],
    default => throw new InvalidArgumentException("unknown case {$case}"),
};

try {
    $answer = (Ignis\Grpc\router($methods, $fallback))($request);
    echo 'returned=', get_debug_type($answer), "\n";
    if ($answer instanceof Response) {
        echo 'status=', $answer->status, "\n", 'body=', $answer->body;
    }
} catch (Throwable $e) {
    echo 'threw=', $e::class, "\n", 'message=', $e->getMessage(), "\n";
}
