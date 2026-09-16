# Research 13 — gRPC on the Ignis stack (E10)

Date: 2026-09-16T02:00Z (Cycle 14). Sources read: `tonic 0.14.6` (`src/server/grpc.rs`, `src/client/grpc.rs`, `src/codec/mod.rs`, `src/body.rs`, `Cargo.toml` features), `hyper-util 0.1` auto builder already used by `http.rs`, RoadRunner `roadrunner-server/roadrunner` (build), `grpc/grpc` (ext-grpc build inputs).

## What gRPC needs from the transport

- HTTP/2 only; requests are `POST /pkg.Service/Method` with `content-type: application/grpc[+proto]`, `te: trailers`. Ignis already serves h2 through `hyper_util::server::conn::auto` (V-5 uses h1; the same listener negotiates h2c via prior knowledge, which is what gRPC clients use without TLS).
- Messages are length-prefixed frames (1 byte compression flag + 4 bytes big-endian length) in the request and response bodies; the status travels in HTTP trailers (`grpc-status`, `grpc-message`). Hyper 1 supports trailers through `http_body::Frame::trailers`.
- So the server side is "one more body encoding on the connection we already own"; nothing about it needs a second listener, a second h2 implementation or a sidecar.

## tonic 0.14 as a library, not a framework

- `tonic::server::Grpc<C: Codec>` does the framing: `Grpc::new(codec).unary(svc, http_req)` and `.server_streaming(svc, http_req)` take any `http::Request<B>` and return `http::Response<tonic::body::Body>` with trailers, `grpc-timeout` handling, compression negotiation and status mapping. The service traits (`UnaryService<R>`, `ServerStreamingService<R>`) are plain: `call(Request<R>) -> Future<Result<Response<Stream>, Status>>`.
- `tonic::client::Grpc<T>` over `tonic::transport::Channel` gives `unary(req, path, codec)` and `server_streaming(req, path, codec) -> Streaming<M>`.
- The codec is user-defined. A `Codec` with `Encode = Decode = Bytes` (`RawCodec`) makes tonic carry opaque messages, so **PHP owns the protobuf encoding** and no `.proto` ever reaches the Rust build (no `tonic-build`, no `protoc` at build time). This matches how sdk-python/Ignis handle Temporal (research 12): bytes across the boundary, schema in userland.
- Feature cost: `tonic` with `default-features = false, features = ["server", "channel"]` avoids axum/router/codegen; it reuses hyper 1, h2 0.4, http-body 1 already in the tree. tonic's `body::Body` is a boxed `http_body::Body<Data = Bytes, Error = Status>`; wrapping the existing `Full<Bytes>` responses in it lets one hyper service return both plain HTTP and gRPC responses.

## Mapping onto the reactor (ADR-0007/0010 shape)

- Server, both unary and server-streaming, through **one** Rust path: `server_streaming` with a `ServerStreamingService` whose response stream is an `mpsc` receiver filled by PHP. A unary handler is a stream of exactly one message. The PHP side sees the request as an ordinary `Ignis\Http\Request` (method POST, path `/pkg.Service/Method`, headers = metadata, body = the decoded request message) and answers with `ignis_grpc_send(id, bytes)` any number of times and `ignis_grpc_end(id, code, message)` once. Dropping the response stream (client gone) cancels the request fiber exactly like the HTTP path (ADR-0009): the same `cancel_request(id)`.
- Client: `ignis_grpc_call(url, path, bytes, streaming)` is an `Op::Custom` future (the seam added for Temporal): unary resolves to the reply bytes; server-streaming resolves to a stream handle, and `ignis_grpc_recv(handle)` is one op per message (one fiber suspension per message, no buffering beyond h2 flow control). Channels are cached per URL and created with `connect_lazy()` so no await happens outside the reactor.
- No Zend pointer crosses to tokio (rule from STATUS): the boundary is `Bytes` and integer ids, same as HTTP.

## Protobuf in PHP without ext-protobuf

- `google/protobuf` on Packagist is a pure-PHP implementation (with optional ext-protobuf acceleration), so real applications keep their generated classes. For the E10 demo, a 60-line encoder/decoder for the wire types actually used (varint, length-delimited) is enough and honest about what is measured: the runtime, not the serializer.

## The two comparison targets

| | RoadRunner grpc plugin | ext-grpc (PECL) |
|---|---|---|
| Architecture | Go binary owns the gRPC server (grpc-go), forwards each call to a PHP worker pool over pipes/sockets (goridge); PHP workers are synchronous, one call per worker at a time; server-streaming needs the newer "streaming" worker protocol; client calls from PHP need ext-grpc or a plain HTTP/2 client | C extension binding grpc C-core (~ the whole `grpc/grpc` tree: BoringSSL, abseil, protobuf, re2, c-ares, upb); PHP-FPM/CLI process makes **client** calls; it has no server |
| Build inputs | Go toolchain; `go install …/cmd/rr@latest` is refused by Go 1.24 (`exclude` directives in the module) so the repo must be cloned and built with `go build ./cmd/rr` (Go ≥ 1.26 required as of v2025.1.15) | `git clone --recurse-submodules grpc/grpc` (submodules on googlesource.com, boringssl) then `pecl install grpc`, which compiles the C-core inside the extension build; historically 20–40 min on 4 cores and a 100+ MB `.so` |
| Where measurement happens tonight | `/home/user/cmp/roadrunner` build in the background (result in VALIDATION V-20) | `/home/user/cmp/grpc` clone; submodule fetch in the background (blocked domains are logged in STATUS.md) |

## What we expect (H21)

- Unary hello-world through PHP at `ghz -c 64`: throughput within 3× of the HTTP hello (128k req/s, V-6) is not expected — each gRPC call is an h2 stream with trailers and two PHP function calls; the realistic bound is tonic's own unary ceiling on this box (tens of thousands per second). The claim to falsify is latency: p99 < 5 ms at c=64 and a client call that parks the fiber (100 concurrent `Proxy` calls, each awaiting a 200 ms upstream, complete in < 300 ms total).
