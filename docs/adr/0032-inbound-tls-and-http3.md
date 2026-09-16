# ADR-0032 — Inbound TLS and HTTP/3: deferred, rustls termination first, h3 after

Status: deferred, with triggers. Rests on ADR-0017 (client-side `ssl://`/`tls://` only), research 27
(curl's TLS backend is OpenSSL — a client-stack fact, unrelated to the listener).

## Context

ADR-0017 hooks outbound TLS only: `ssl://`/`tls://` streams connect through `tokio-rustls` on the
reactor side (V-25: 210–231 ms for 3 concurrent HTTPS fetches vs 613–623 ms unhooked). Its own text
says the listener "stays plaintext… TLS termination for the server side is a separate decision" —
this ADR is that decision. Nothing terminates inbound TLS today; `ignis.toml`'s `listen` (V-38) is
plaintext only. Research 27's OpenSSL-vs-gnutls finding is about ext/curl's client stack (governed by
ADR-0020's park policy); it says nothing about the listener and is not evidence for this decision.

## Options considered

1. Nothing; require an external TLS-terminating proxy — the implicit status quo, valid for any
   deployment that already has one (most V-n numbers here were taken behind plaintext or a
   terminator). Insufficient once a deployment has no proxy tier.
2. **rustls termination on hyper, same listener — chosen for when triggered.** Extends the rustls
   dependency ADR-0017 already has; hyper 1.x has first-class rustls integration.
3. h3 via `quinn` + `Alt-Svc` — deferred behind option 2; needs UDP/QUIC, a separate accept path, and
   only pays off once TLS termination exists and a client population that benefits is identified.
4. OpenSSL for the listener — rejected outright: a third TLS stack in the process (rustls for
   ADR-0017, OpenSSL in libphp per ADR-0020's `block` paths, plus this) with no offsetting benefit.

## Decision

Deferred. When undertaken: rustls termination ships first, measured only under `tc netem`. h3 (quinn)
follows only after termination is shipped and measured.

**Trigger for TLS termination:** a deployment that needs TLS on the listener itself — no proxy tier
in front.

**Trigger for h3:** a user with mobile clients not behind a CDN.

## Consequences

- Better (once triggered): one fewer moving part for a deployment needing inbound TLS, reusing
  infrastructure ADR-0017 already proved.
- Worse (today): a deployment needing inbound TLS before either trigger must run an external
  terminator — unchanged by this ADR.
- Affects: M4/M5 milestones' eventual TLS-on-listener acceptance, whenever added to the roadmap.
  ADR-0020's `park` policy for OpenSSL/libcurl is unaffected — it governs the client stack.
- Not measured: no V-n for inbound TLS or h3 on this listener.

## Kill criterion

Not applicable — nothing built yet. The two triggers above are the promotion criteria from deferred
to in progress.

## Status

deferred, with triggers (TLS: a deployment needing listener-side TLS; h3: mobile clients not behind
a CDN)
