<?php

declare(strict_types=1);

/**
 * IDE/static-analysis stubs for the `ignis_*` functions the runtime registers
 * from Rust (crates/ignis/src/php/module.rs). These are never loaded by the
 * ignis binary itself — it defines the real functions before userland runs —
 * so every stub is guarded with function_exists() and just throws if somehow
 * reached under the real binary (BACKLOG.md H-7).
 *
 * Load via composer's autoload-dev (php/composer.json), or `require` this
 * file directly in your IDE/PHPStan/Psalm bootstrap.
 */

// --- core reactor primitives (ADR-0001/ADR-0002) ---

if (!function_exists('ignis_submit_sleep')) {
    /** ignis_submit_sleep(int $ms): int — op id for a timer completion (V-2, V-3). */
    function ignis_submit_sleep(int $ms): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_poll')) {
    /** ignis_poll(int $timeout_ms): array — id => payload; the runtime's single wait point (V-33). */
    function ignis_poll(int $timeout_ms): array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_inflight')) {
    /** ignis_inflight(): int — pending op count, used for least-inflight dispatch (ADR-0010, V-15). */
    function ignis_inflight(): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_stats')) {
    /** ignis_stats(): array — [threads, stalled, restarts] (ADR-0012, V-17). */
    function ignis_stats(): array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_serve')) {
    /** ignis_serve(string $addr): bool — start listening on hyper/tokio (ADR-0002, V-5). */
    function ignis_serve(string $addr): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_respond')) {
    /** ignis_respond(int $id, int $status, array $headers, string $body): bool (ADR-0002/ADR-0003, V-5). */
    function ignis_respond(int $id, int $status, array $headers, string $body): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- readiness watch / cancellation (ADR-0008, ADR-0009) ---

if (!function_exists('ignis_watch')) {
    /** ignis_watch(resource $stream, int $mode): int — one-shot readiness watch, mode 1=read 2=write (ADR-0008, V-23 addendum). */
    function ignis_watch($stream, int $mode): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_cancel')) {
    /** ignis_cancel(int $op): int — cancel a pending ignis_watch op (ADR-0009, V-23 addendum). */
    function ignis_cancel(int $op): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_cancel_parked_any')) {
    /** ignis_cancel_parked_any(\Fiber $fiber, \Throwable $exception): bool — resume a C-parked fiber by throwing (ADR-0009, V-14/V-30). */
    function ignis_cancel_parked_any(\Fiber $fiber, \Throwable $exception): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- fiber-scoped superglobals (ADR-0006) ---

if (!function_exists('ignis_set_superglobals')) {
    /** ignis_set_superglobals(array $server, array $get, array $post, array $cookie): void (ADR-0006, V-11). */
    function ignis_set_superglobals(array $server, array $get, array $post, array $cookie): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- gRPC (ADR-0014, V-20) ---

if (!function_exists('ignis_grpc_send')) {
    /** ignis_grpc_send(int $id, string $message): bool — one response message (ADR-0014, V-20). */
    function ignis_grpc_send(int $id, string $message): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_grpc_end')) {
    /** ignis_grpc_end(int $id, int $code, string $message): bool — finish a response stream (ADR-0014, V-20). */
    function ignis_grpc_end(int $id, int $code, string $message): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_grpc_call')) {
    /** ignis_grpc_call(string $url, string $path, string $message, bool $streaming): int — op id, client call (ADR-0014, V-20). */
    function ignis_grpc_call(string $url, string $path, string $message, bool $streaming): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_grpc_recv')) {
    /** ignis_grpc_recv(int $stream): int — op id; payload is the next message or null (ADR-0014, V-20). */
    function ignis_grpc_recv(int $stream): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- PostgreSQL pool (ADR-0015, V-21) ---

if (!function_exists('ignis_pg_open')) {
    /** ignis_pg_open(string $dsn, int $max): int — pool id, no I/O (ADR-0015, V-21). */
    function ignis_pg_open(string $dsn, int $max): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_pg_acquire')) {
    /** ignis_pg_acquire(int $pool): int|array — lease id, or op id if none idle (ADR-0015, V-21). */
    function ignis_pg_acquire(int $pool): int|array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_pg_query')) {
    /** ignis_pg_query(int $lease, string $sql, string $paramsJson): int — op id; payload {rows, affected} (ADR-0015, V-21). */
    function ignis_pg_query(int $lease, string $sql, string $paramsJson): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_pg_release')) {
    /** ignis_pg_release(int $lease, bool $reset): int — op id; payload 1 when idle again (ADR-0015, V-21). */
    function ignis_pg_release(int $lease, bool $reset): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_pg_stats')) {
    /** ignis_pg_stats(int $pool): ?array — [idle, created, available] (ADR-0015, V-21). */
    function ignis_pg_stats(int $pool): ?array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- offload pool (ADR-0016, V-24) ---

if (!function_exists('ignis_offload_submit')) {
    /** ignis_offload_submit(string $fn, string $serializedArgs, int $affinity = -1): int|false — op id (ADR-0016, V-24). */
    function ignis_offload_submit(string $fn, string $serializedArgs, int $affinity = -1): int|false
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_offload_next')) {
    /** ignis_offload_next(): ?array — worker thread: blocks for the next job [id, fn, args] (ADR-0016, V-24). */
    function ignis_offload_next(): ?array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_offload_done')) {
    /** ignis_offload_done(int $job, string $serializedResult): bool — worker thread (ADR-0016, V-24). */
    function ignis_offload_done(int $job, string $serializedResult): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_offload_callback')) {
    /** ignis_offload_callback(int $job, int $cb, string $serializedArgs): string|false — worker thread, blocks (ADR-0016, V-24). */
    function ignis_offload_callback(int $job, int $cb, string $serializedArgs): string|false
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_offload_cb_result')) {
    /** ignis_offload_cb_result(int $job, int $seq, string $serializedResult): bool — calling thread (ADR-0016, V-24). */
    function ignis_offload_cb_result(int $job, int $seq, string $serializedResult): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_offload_stats')) {
    /** ignis_offload_stats(): array — [workers, busy, done, queued] (ADR-0016, V-24). */
    function ignis_offload_stats(): array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- auto-routing (ADR-0016, V-24 addendum) ---

if (!function_exists('ignis_route_enable')) {
    /** ignis_route_enable(bool $on): void — the PHP Router is loaded (ADR-0016, V-24 addendum). */
    function ignis_route_enable(bool $on): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_route_pass')) {
    /** ignis_route_pass(): void — the Router declines the current call (ADR-0016, V-24 addendum). */
    function ignis_route_pass(): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- Temporal SDK worker primitives (feature = "temporal", ADR-0013) ---
// Only present in a build with `--features temporal`; the stubs are harmless
// to declare unconditionally since they are guarded like every other one.

if (!function_exists('ignis_temporal_connect')) {
    /** ignis_temporal_connect(string $url, string $namespace, string $taskQueue): int — op; result JSON {worker} (ADR-0013, V-18). */
    function ignis_temporal_connect(string $url, string $namespace, string $taskQueue): int
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_temporal_replay')) {
    /** ignis_temporal_replay(string $url, string $workflowId, string $taskQueue): int — op; result JSON {worker} (ADR-0013, V-19). */
    function ignis_temporal_replay(string $url, string $workflowId, string $taskQueue): int
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_temporal_poll')) {
    /** ignis_temporal_poll(int $worker): int — op; JSON WorkflowActivation (ADR-0013, V-18). */
    function ignis_temporal_poll(int $worker): int
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_temporal_complete')) {
    /** ignis_temporal_complete(int $worker, string $completionJson): int — op; "ok" (ADR-0013, V-18). */
    function ignis_temporal_complete(int $worker, string $completionJson): int
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_temporal_poll_activity')) {
    /** ignis_temporal_poll_activity(int $worker): int — op; JSON ActivityTask (ADR-0013, V-18). */
    function ignis_temporal_poll_activity(int $worker): int
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_temporal_complete_activity')) {
    /** ignis_temporal_complete_activity(int $worker, string $completionJson): int — op (ADR-0013, V-18). */
    function ignis_temporal_complete_activity(int $worker, string $completionJson): int
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_temporal_shutdown')) {
    /** ignis_temporal_shutdown(int $worker): int — op; initiates shutdown (ADR-0013, V-18). */
    function ignis_temporal_shutdown(int $worker): int
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

// --- output capture and streamed responses (V-72, V-76, V-77) ---

if (!function_exists('ignis_capture_start')) {
    /** ignis_capture_start(): bool — this fiber's output goes to a fresh buffer until it is taken. */
    function ignis_capture_start(): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_capture_take')) {
    /** ignis_capture_take(): string — the bytes written since the matching start; stops capturing. */
    function ignis_capture_take(): string
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_capture_reset')) {
    /** ignis_capture_reset(): bool — drops whatever this fiber left behind, so a pooled fiber does not hand its bytes to the next request (V-67). */
    function ignis_capture_reset(): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_stream_bind')) {
    /**
     * ignis_stream_bind(int $id, int $status, array $headers): bool — this fiber's output becomes the body of response $id.
     *
     * @param array<string, string> $headers
     */
    function ignis_stream_bind(int $id, int $status, array $headers): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_stream_unbind')) {
    /**
     * ignis_stream_unbind(): array — stops forwarding and reports [tail, started].
     *
     * @return array{0: string, 1: bool}
     */
    function ignis_stream_unbind(): array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_stream_write')) {
    /** ignis_stream_write(string $bytes): int — a frame of this fiber's response; 0 if the runtime took it, an op id to await if the queue is full, -1 if this fiber is not streaming. */
    function ignis_stream_write(string $bytes): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_respond_chunk')) {
    /** ignis_respond_chunk(int $id, string $bytes): int — one frame of a streamed response; returns an op to await. */
    function ignis_respond_chunk(int $id, string $bytes): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_respond_end')) {
    /** ignis_respond_end(int $id): bool — no more chunks; the body is complete. */
    function ignis_respond_end(int $id): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_publish_stats')) {
    /**
     * ignis_publish_stats(array $stats): void — the PHP loop hands its own counters to the runtime for /_ignis/metrics (M4-4).
     *
     * @param array<string, int> $stats
     */
    function ignis_publish_stats(array $stats): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_temporal_heartbeat')) {
    /** ignis_temporal_heartbeat(int $worker, string $json): bool — activity heartbeat (ADR-0013). */
    function ignis_temporal_heartbeat(int $worker, string $json): bool
    {
        throw new \LogicException('stub: only the ignis binary (temporal feature) defines ' . __FUNCTION__);
    }
}

// --- backend (b) async-ABI primitives (cfg(php_async_abi), ADR-0003) ---
// Only present when built against the true-async fork (scripts/build-php-async.sh).

if (!function_exists('ignis_park_on')) {
    /** ignis_park_on(int $id): void — mark the current coroutine as waiting for reactor op $id (ADR-0003, V-8). */
    function ignis_park_on(int $id): void
    {
        throw new \LogicException('stub: only the ignis binary (backend b) defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_op_result')) {
    /** ignis_op_result(int $id): int — payload of a completed op, or -1 if unknown; removes it (ADR-0003, V-8). */
    function ignis_op_result(int $id): int
    {
        throw new \LogicException('stub: only the ignis binary (backend b) defines ' . __FUNCTION__);
    }
}
