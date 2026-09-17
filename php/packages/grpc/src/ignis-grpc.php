<?php
/**
 * Ignis gRPC runtime (E10, ADR-0014). Server handlers and the client are plain PHP over four
 * module functions:
 *   ignis_grpc_send(int $id, string $message): bool
 *   ignis_grpc_end(int $id, int $code, string $message): bool
 *   ignis_grpc_call(string $url, string $path, string $message, bool $streaming): int   (op id)
 *   ignis_grpc_recv(int $stream): int                                                   (op id)
 * Messages are opaque bytes: protobuf encoding lives here (Proto) or in google/protobuf.
 */
declare(strict_types=1);

namespace Ignis\Grpc;

use Ignis\CancelledException;
use Ignis\Http\Request;
use Ignis\Http\Response;
use Ignis\Loop;

final class Status
{
    public const OK = 0;
    public const CANCELLED = 1;
    public const UNKNOWN = 2;
    public const INVALID_ARGUMENT = 3;
    public const DEADLINE_EXCEEDED = 4;
    public const NOT_FOUND = 5;
    public const UNIMPLEMENTED = 12;
    public const INTERNAL = 13;
    public const UNAVAILABLE = 14;
}

final class StatusException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }
}

/** One incoming call: the request message plus the response stream. */
final class Call
{
    public bool $ended = false;
    public int $sent = 0;

    public function __construct(public readonly Request $request)
    {
    }

    /** `/package.Service/Method` */
    public function method(): string
    {
        return $this->request->path();
    }

    /** Raw request message (protobuf bytes). */
    public function message(): string
    {
        return $this->request->body;
    }

    /** @return array<string,string> request metadata (HTTP/2 headers, lower-cased) */
    public function metadata(): array
    {
        return $this->request->headers;
    }

    /** Send one response message; a false from the runtime means the client is gone. */
    public function send(string $bytes): void
    {
        if ($this->ended) {
            throw new \LogicException('gRPC call already ended');
        }
        if (!\ignis_grpc_send($this->request->id, $bytes)) {
            $this->ended = true;
            throw new CancelledException('gRPC client went away');
        }
        $this->sent++;
    }

    public function end(int $status = Status::OK, string $message = ''): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        \ignis_grpc_end($this->request->id, $status, $message);
    }
}

/**
 * Build an Ignis\serve handler from a method map. A method handler receives the Call and either
 * returns the single reply (unary) or streams with $call->send() and returns null.
 *
 * @param array<string, callable(Call): (string|null)> $methods keyed by `/package.Service/Method`
 * @param null|callable(Request): Response $fallback for non-gRPC requests (default 404)
 */
function router(array $methods, ?callable $fallback = null): callable
{
    return static function (Request $request) use ($methods, $fallback): Response {
        if (!str_starts_with($request->headers['content-type'] ?? '', 'application/grpc')) {
            return $fallback !== null ? $fallback($request) : Response::text("404 not a gRPC request\n", 404);
        }
        $call = new Call($request);
        $handler = $methods[$call->method()] ?? null;
        if ($handler === null) {
            $call->end(Status::UNIMPLEMENTED, 'unknown method ' . $call->method());
            return null;   // answered through the gRPC channel (ignis_grpc_send/end)
        }
        try {
            $reply = $handler($call);
            if (\is_string($reply)) {
                $call->send($reply);
            }
            $call->end();
        } catch (StatusException $e) {
            $call->end($e->status, $e->getMessage());
        } catch (CancelledException $e) {
            $call->end(Status::CANCELLED, 'cancelled');
        } catch (\Throwable $e) {
            $call->end(Status::INTERNAL, $e::class . ': ' . $e->getMessage());
        }
        return null;   // answered through the gRPC channel (ignis_grpc_send/end)
    };
}

/** gRPC client over the runtime's h2 channels; every call parks the current fiber. */
final class Client
{
    public function __construct(private readonly string $url)
    {
    }

    public function unary(string $method, string $message): string
    {
        return self::result(Loop::awaitOp(\ignis_grpc_call($this->url, $method, $message, false)));
    }

    /** @return \Generator<int, string> one item per server message, in order */
    public function serverStream(string $method, string $message): \Generator
    {
        $r = self::result(Loop::awaitOp(\ignis_grpc_call($this->url, $method, $message, true)));
        $stream = (int) (json_decode($r, true, 8, JSON_THROW_ON_ERROR)['stream'] ?? 0);
        while (true) {
            $msg = self::result(Loop::awaitOp(\ignis_grpc_recv($stream)));
            if ($msg === null) {
                return;
            }
            yield $msg;
        }
    }

    private static function result(mixed $payload): mixed
    {
        if (\is_array($payload) && ($payload['kind'] ?? '') === 'error') {
            $m = (string) $payload['message'];
            $code = preg_match('/code=(\d+)/', $m, $mm) ? (int) $mm[1] : Status::UNKNOWN;
            throw new StatusException($code, $m);
        }
        return $payload;
    }
}

/**
 * Minimal protobuf wire codec for demos and tests: varint (ints, bools) and length-delimited
 * (strings, bytes, nested messages as raw strings). Real applications use google/protobuf.
 */
final class Proto
{
    /** @param array<int, int|string|bool|list<int|string>> $fields field number => value(s) */
    public static function encode(array $fields): string
    {
        $out = '';
        foreach ($fields as $no => $value) {
            foreach (\is_array($value) ? $value : [$value] as $v) {
                if (\is_int($v) || \is_bool($v)) {
                    $out .= self::varint(($no << 3) | 0) . self::varint((int) $v);
                } else {
                    $out .= self::varint(($no << 3) | 2) . self::varint(\strlen($v)) . $v;
                }
            }
        }
        return $out;
    }

    /** @return array<int, int|string> field number => value (last one wins; repeated fields are not needed here) */
    public static function decode(string $bytes): array
    {
        $out = [];
        $i = 0;
        $n = \strlen($bytes);
        while ($i < $n) {
            $key = self::readVarint($bytes, $i);
            $no = $key >> 3;
            switch ($key & 7) {
                case 0:
                    $out[$no] = self::readVarint($bytes, $i);
                    break;
                case 2:
                    $len = self::readVarint($bytes, $i);
                    $out[$no] = substr($bytes, $i, $len);
                    $i += $len;
                    break;
                case 1:
                    $out[$no] = substr($bytes, $i, 8);
                    $i += 8;
                    break;
                case 5:
                    $out[$no] = substr($bytes, $i, 4);
                    $i += 4;
                    break;
                default:
                    throw new \InvalidArgumentException("unsupported wire type " . ($key & 7));
            }
        }
        return $out;
    }

    public static function varint(int $v): string
    {
        $s = '';
        do {
            $b = $v & 0x7f;
            $v >>= 7;
            $s .= \chr($v !== 0 ? $b | 0x80 : $b);
        } while ($v !== 0);
        return $s;
    }

    private static function readVarint(string $bytes, int &$i): int
    {
        $v = 0;
        $shift = 0;
        do {
            $b = \ord($bytes[$i++]);
            $v |= ($b & 0x7f) << $shift;
            $shift += 7;
        } while ($b & 0x80);
        return $v;
    }
}
