# Research 20 — ssl:// and tls:// through the stream hook (E6')

Date: 2026-09-16T03:45Z (Cycle 19). Sources: ext/openssl/openssl.c (`php_openssl_ssl_socket_factory` registered for `ssl`, `sslv3`, `tls`, `tlsv1.0`–`tlsv1.3` **and `tcp`** — with openssl built, the stock tcp transport is openssl's, which our MINIT then replaces, keeping it as the fallback), main/streams/php_stream_transport.h (`PHP_STREAM_OPTION_CRYPTO_API` with `STREAM_CRYPTO_OP_SETUP`/`ENABLE`, `php_stream_xport_crypto_param`), ext/openssl/xp_ssl.c (context options `verify_peer`, `verify_peer_name`, `allow_self_signed`, `cafile`, `peer_name`, `SNI_enabled`), `tokio-rustls 0.26` (`TlsConnector`, `client::TlsStream`), `rustls 0.23` (`ClientConfig`, `RootCertStore`, `dangerous()` verifier), `webpki-roots`.

## Findings

- A `ssl://host:443` client stream is: TCP connect, then a TLS client handshake with SNI = host (or `peer_name`), then reads/writes through the TLS session. `https://` in `file_get_contents` is the http wrapper over an `ssl://` transport, so hooking the transport covers `https://` too.
- `stream_socket_enable_crypto($tcpStream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)` (STARTTLS: SMTP, PostgreSQL's `sslmode`) arrives as `PHP_STREAM_OPTION_CRYPTO_API` with `op = SETUP` then `ENABLE(activate = 1)` on an existing tcp stream; the transport must upgrade its socket in place.
- Everything else stays as in ADR-0007: the tokio side owns the socket (now possibly a `TlsStream<TcpStream>`), the PHP side parks per op. Verification policy is read from the stream context at connect time; with none, the default is verify with the Mozilla roots and the host name (same default as PHP ≥ 5.6).

## Decision points (ADR-0017)

- One factory for `tcp`, `ssl`, `tls`, `tlsv1.2`, `tlsv1.3`: the protocol name selects `tls: Some(server_name)` on connect. Unsupported names (`sslv3`, `tlsv1.0/1.1`) stay on openssl.
- `Op::Connect { host, port, tls: Option<TlsOpts> }` and `Op::Upgrade { conn, tls: TlsOpts }` (STARTTLS); the connection actor becomes generic over `Box<dyn AsyncRead + AsyncWrite + Send + Unpin>`.
- `TlsOpts { server_name, verify_peer, verify_peer_name, allow_self_signed, cafile }`; `verify_peer=false` or `allow_self_signed=true` install a verifier that accepts any certificate (this is what PHP does); `cafile` adds PEM roots.
- Crypto provider: `ring` (no cmake, no aws-lc build); TLS 1.2 + 1.3.
- Out of scope now: client certificates (`local_cert`), server-side TLS (Ignis's own listener stays plain h2c/h1; TLS termination is a follow-up), `peer_certificate` capture, `stream_socket_enable_crypto(false)` (downgrade).

## Expectation (H25)

- Three concurrent `file_get_contents('https://127.0.0.1:844{1,2,3}/')` against three local TLS servers that each sleep 200 ms complete in ≈ 200 ms on one PHP thread (the same shape as V-12 for tcp); with the hook off (`IGNIS_NO_STREAM_HOOK=1`) they take ≈ 600 ms.
- `stream_socket_enable_crypto()` on a hooked tcp stream upgrades in place and the same 3-way timing holds.
- A wrong host name fails verification unless `verify_peer_name=false`; a self-signed server certificate is rejected unless `allow_self_signed=true` or `verify_peer=false`.
