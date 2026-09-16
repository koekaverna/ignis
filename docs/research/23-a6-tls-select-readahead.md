# A6 — TLS read-ahead is invisible to `stream_select()`: reproduced, diagnosed, NOT fixed

Cycle 22, 2026-09-16. Roadmap item A6. Status: **the defect is real and now has a failing test**;
no fix is landed, because the one tried did not work and cost a reactor round trip per TLS read.

## The defect

`rustls` decrypts a whole TLS record at a time. `op_read` asks for `count.max(8192)` bytes, so with
a record larger than that the remainder stays decrypted on the tokio side. The encrypted bytes are
already consumed from the socket, so the raw fd shows nothing readable, and `stream_select()` on
that stream answers "not ready" while plaintext is waiting.

## Reproducer

`bench/php/a6_tls_select.php` against `bench/php/a6_hold_server.php`:

```
PORT=8461 CERT=/tmp/e6-ssl/cert.pem /opt/php85-zts/bin/php bench/php/a6_hold_server.php &
PORT=8461 CAFILE=/tmp/e6-ssl/ca.pem ./target/release/ignis bench/php/a6_tls_select.php
→ a6 hook=on first_bytes=8192 select_ready=0 select_us=23.4 rest_bytes=0 verdict=FAIL
```

Three conditions must hold at once, which is why the first three attempts all passed and were
misleading:

1. **A body larger than one `op_read`** (10 KB against a 8192-byte read), or nothing is left over.
2. **The client must first drain what `php_stream` buffered.** `fread($c, 1)` makes php_stream read a
   whole chunk into its own buffer, and the original `stream_select()` *does* see that buffer — so a
   one-byte read hides the bug completely.
3. **The peer must keep the connection open.** With `Connection: close` the fd is readable because of
   EOF, and select answers "ready" for the wrong reason. A held-open connection is required.

A fourth trap cost a run: the first version of the test ended with `stream_get_contents()`, which
blocks for ever on a held-open connection and made both arms of the A/B look identical ("hung").

## Why the obvious fix does not work

Draining rustls into `Sock.pending` after every TLS read (tried, measured, reverted) does not help:
`has_buffered()` then reports the stream as buffered, but `hooked_select()` answers by running the
**original** `stream_select` with a zero timeout, and the original filters the arrays using
php_stream's buffer and the fd. It cannot see `Sock.pending`. So the data moves to a place that is
still invisible to the code that decides the answer, at the cost of one extra reactor round trip on
every TLS read. Reverted rather than kept, since it buys nothing measurable.

## Candidate designs for whoever picks this up

1. **Patch the answer, not the buffer.** After the zero-timeout probe, add back any stream that
   `has_buffered()` says is ready but the probe filtered out, and correct the return count. Small and
   local to `hooked_select`, but it means writing the by-reference arrays ourselves, which is exactly
   the part of that function with the most subtle ownership handling.
2. **Give the stream a readiness fd it controls** (eventfd or a self-pipe) and have `op_cast` return
   that instead of the socket, with the reactor signalling it whenever plaintext is buffered. This is
   the standard trick and it makes every fd-based consumer correct at once — `socket_import_stream`
   included — but it changes what `op_cast` hands out, which E6''/V-26 depends on.

Design 2 is the right one; design 1 is the cheap one. Neither is a Phase-A-sized change, which is the
honest reason this item is being handed on rather than closed.
