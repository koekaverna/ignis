<?php

declare(strict_types=1);

/** IDE and PHPStan stubs for the `ignis_*` functions crates/ignis/src/php/module.rs registers; never loaded by the binary. */

// --- core reactor primitives (ADR-0001/ADR-0002) ---

/**
 * The shapes `ignis_poll()` can return, as PHPStan types rather than prose.
 *
 * They are declared once here so every reader of a completion narrows on `kind` instead of
 * indexing a `mixed`. There is no class and no runtime cost: these are type aliases the analyser
 * resolves and the engine never sees.
 *
 * They live in `php/phpstan.neon` under `parameters.typeAliases`, not in a docblock here: a
 * `@phpstan-type` attached to no class-like resolves nowhere, which is why `ignis_poll()`'s return
 * type read as invalid for as long as this file went unanalysed. Global aliases are also importable,
 * so `Loop.php` no longer needs a local copy of the same shapes.
 */


if (!function_exists('ignis_submit_sleep')) {
    /** ignis_submit_sleep(int $ms): int — op id for a timer completion (V-2, V-3). */
    function ignis_submit_sleep(int $ms): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_poll')) {
    /**
     * ignis_poll(int $timeout_ms): array — id => payload; the runtime's single wait point (V-33).
     * @return array<int, int|string|null|IgnisCompletion|IgnisRequest>
     */
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
    /**
     * ignis_stats(): array — worker and recovery counters.
     * @return array{threads: int, stalled: int, restarts: int, killed: int, blocking_calls: int, leaked_workers: int, blocked_workers: int, alerts_dropped: int}
     */
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
    /**
     * ignis_respond(int $id, int $status, array $headers, string $body): bool (ADR-0002/ADR-0003, V-5).
     * @param array<string, string|list<string>> $headers
     */
    function ignis_respond(int $id, int $status, array $headers, string $body): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- readiness watch / cancellation (ADR-0008, ADR-0009) ---

if (!function_exists('ignis_watch')) {
    /**
     * ignis_watch(resource $stream, int $mode): int — one-shot readiness watch, mode 1=read 2=write (ADR-0008, V-23 addendum).
     * @param resource $stream
     */
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
    /**
     * ignis_cancel_parked_any(\Fiber $fiber, \Throwable $exception): bool — resume a C-parked fiber by throwing (ADR-0009, V-14/V-30).
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    function ignis_cancel_parked_any(\Fiber $fiber, \Throwable $exception): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- fiber-scoped superglobals (ADR-0006) ---

if (!function_exists('ignis_set_superglobals')) {
    /**
     * ignis_set_superglobals(array $server, array $get, array $post, array $cookie): void (ADR-0006, V-11).
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookie
     */
    function ignis_set_superglobals(array $server, array $get, array $post, array $cookie): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

// --- fiber-scoped objects (BACKLOG.md S-SCOPED-CLASS, DECISIONS.md 2026-09-20) ---

if (!function_exists('ignis_park_inventory')) {
    /**
     * ignis_park_inventory(): array — every library observed making an interposed call in this
     * process, the symbols it called, and whether the policy let each one park. A `false` blocked.
     * @return array<string, array<string, bool>>
     */
    function ignis_park_inventory(): array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_scope_allocate')) {
    /**
     * ignis_scope_allocate(string $class): object — an $class instance with IS_UNDEF property
     * slots and handlers addressing this fiber's row. Never runs the constructor.
     */
    function ignis_scope_allocate(string $class): object
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_set_request_info')) {
    /** ignis_set_request_info(string $method, string $content_type, string $body): void — SG(request_info) for this fiber. */
    function ignis_set_request_info(string $method, string $content_type, string $body): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_clear_request_info')) {
    /** ignis_clear_request_info(): void — drops this fiber's body and unhooks SG(request_info). */
    function ignis_clear_request_info(): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_scope_seal')) {
    /**
     * ignis_scope_seal(object $instance): void — what this fiber currently holds for $instance
     * becomes row zero, the values every other scope inherits. Call it again after anything that.
     */
    function ignis_scope_seal(object $instance): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_scope_rows_clear')) {
    /** ignis_scope_rows_clear(): void — drops this fiber's Scope::create() property rows. */
    function ignis_scope_rows_clear(): void
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

// --- development reload (research 40) ---

if (!function_exists('ignis_watch_files')) {
    /**
     * ignis_watch_files(array $files): int — add loaded files to the watcher; returns how many
     * directories became watched (research 40).
     * @param list<string> $files
     */
    function ignis_watch_files(array $files): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_watch_generation')) {
    /** ignis_watch_generation(): int — settled changes so far; a worker reloads when it grows. */
    function ignis_watch_generation(): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_watch_claim_reset')) {
    /** ignis_watch_claim_reset(): bool — true for exactly one caller per settled change. */
    function ignis_watch_claim_reset(): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_watch_begin_reload')) {
    /** ignis_watch_begin_reload(): bool — claim the single reload slot; false means wait your turn. */
    function ignis_watch_begin_reload(): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_watch_end_reload')) {
    /** ignis_watch_end_reload(): void — this worker is up; the next one may reload. */
    function ignis_watch_end_reload(): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_stop_accepting')) {
    /** ignis_stop_accepting(): void — leave HTTP dispatch, keeping the requests already in flight. */
    function ignis_stop_accepting(): void
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
    /** ignis_temporal_poll(int $worker): int — op; JSON WorkflowActivation, or null at shutdown (ADR-0013, V-18, V-109). */
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
    /** ignis_temporal_poll_activity(int $worker): int — op; JSON ActivityTask, or null at shutdown (ADR-0013, V-18, V-109). */
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
     * @param array<string, string|list<string>> $headers
     */
    function ignis_stream_bind(int $id, int $status, array $headers): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_stream_unbind')) {
    /**
     * ignis_stream_unbind(): array — stops forwarding and reports [tail, started].
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

// --- stall detection, stuck-fiber recovery and blocking alerts (ADR-0043) ---

if (!function_exists('ignis_fiber_request')) {
    /** ignis_fiber_request(int $id): void — the request the current fiber serves, 0 = none. */
    function ignis_fiber_request(int $id): void
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_fiber_kill_pending')) {
    /**
     * ignis_fiber_kill_pending(Fiber $fiber, bool $on): bool — marks $fiber for force-close, refusing it any further C-side park.
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    function ignis_fiber_kill_pending(\Fiber $fiber, bool $on): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_allow_blocking')) {
    /** ignis_allow_blocking(bool $on): bool — sets the current fiber's flag, returns the previous value. */
    function ignis_allow_blocking(bool $on): bool
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_fiber_where')) {
    /**
     * ignis_fiber_where(Fiber $fiber): ?string — "file:line" of a suspended fiber's suspension point, null otherwise.
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     */
    function ignis_fiber_where(\Fiber $fiber): ?string
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_blocking_sequence')) {
    /** ignis_blocking_sequence(): int — a monotonically increasing sequence of recorded blocking calls, process-wide. */
    function ignis_blocking_sequence(): int
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_blocking_records')) {
    /**
     * ignis_blocking_records(int $since): array — this thread's blocking records newer than $since.
     * @return list<array{sequence: int, site: string, duration_us: int, errno: int, request: int, uri: string, allowed: bool, trace: list<string>}>
     */
    function ignis_blocking_records(int $since): array
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}

if (!function_exists('ignis_blocking_report')) {
    /** ignis_blocking_report(): string — the JSON blocking report. */
    function ignis_blocking_report(): string
    {
        throw new \LogicException('stub: only the ignis binary defines ' . __FUNCTION__);
    }
}
