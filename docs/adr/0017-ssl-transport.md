# ADR-0017 — ssl:// and tls:// on the stream hook via tokio-rustls

Status: **superseded by ADR-0037 (cycle 3, V-49, 2026-09-18)** — rustls and the `ssl://`/`tls://` factory are deleted; TLS is PHP's own `ext/openssl` on top of parked syscalls, which also closed A6/B7 by construction. Originally accepted (Cycle 19, 2026-09-16; V-25). Affects pain-map items: Swoole 5 (incomplete hooks: SSL was MISSING in research 15), RoadRunner 1 (workers blocking on I/O: TLS handshakes are the slowest client-side wait). Depends on ADR-0007 (stream hook), ADR-0009 (cancellation).

## Decision

1. The hook's transport factory is registered for `ssl`, `tls`, `tlsv1.2` and `tlsv1.3` in addition to `tcp`; inside a fiber those streams connect through the reactor with a TLS handshake performed by `tokio-rustls` on the tokio side (`Op::Connect { tls }`), and STARTTLS (`stream_socket_enable_crypto`) upgrades a hooked tcp connection in place (`Op::Upgrade`).
2. Verification follows PHP's context options (`verify_peer`, `verify_peer_name`, `allow_self_signed`, `cafile`, `peer_name`); the default verifies against the bundled Mozilla roots with host-name checking.
3. Older protocol names (`sslv3`, `tlsv1.0`, `tlsv1.1`) and client certificates are not hooked: they fall back to ext/openssl's blocking transport, unchanged.
4. Ignis's own HTTP listener stays plaintext (h2c/h1); TLS termination for the server side is a separate decision.

## Consequences

- `https://` fetches and PostgreSQL/SMTP STARTTLS from PHP code park the fiber instead of the thread; the E6 numbers extend to TLS.
- Two TLS stacks in the process (OpenSSL in libphp for everything unhooked, rustls in the runtime for hooked client streams); certificate-store configuration must be set for both if a deployment customises it.
- +≈ 20 crates (`rustls` with the `ring` provider, `tokio-rustls`, `webpki-roots`).

## Kill criterion — moot, and why it is recorded rather than written

This ADR never carried one, which CLAUDE.md requires. It is superseded (V-49, ADR-0037 cycle 3): the
rustls factory it decides on is deleted, so a criterion for reversing it would be a criterion for
reversing something that no longer runs. What replaced it carries its own, in ADR-0020 — the owner's
verbatim kill criterion for universal park: *any OpenSSL or libcurl test failing under `park` with a
lock in the trace*. That is the live version of this question and it is measured (V-51, H36).
