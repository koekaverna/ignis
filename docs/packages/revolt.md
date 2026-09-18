# `ignis/revolt`

Runs AMPHP and Revolt code unchanged, with every await becoming a park on the Ignis reactor
(ADR-0008).

## What it does

`Ignis\Revolt\IgnisDriver` is a `Revolt\EventLoop` driver implemented over the runtime's own
reactor. Libraries that already speak Revolt — AMPHP's HTTP client, its sockets, anything built on
them — keep their API and stop running their own event loop: their timers and readiness waits
become the same ops every other Ignis fiber uses, on the one wait point a PHP thread has.

## It is the thread's only scheduler

The driver and `Ignis\Loop` cannot both run on one PHP thread. `ignis_poll()` drains the completion
channel, so whichever of the two polls first takes the other's completions and the fibers waiting on
them never wake — not an error, a hang. The supported shape is an AMPHP application driven by this
driver; `Ignis\serve()` is the other shape, and it uses the loop. Constructing the driver while the
loop is running throws `LogicException` rather than producing the hang, since 2026-09-18 — before
that the incompatibility was real and written down nowhere.

## Install

```
composer require ignis/revolt:@dev
```

Select it the way Revolt selects any driver:

```
REVOLT_DRIVER=Ignis\Revolt\IgnisDriver
```

No application code names the class. Constructing it outside the `ignis` binary throws
`UnsupportedFeatureException`, so a misconfigured environment fails loudly instead of quietly
running two loops.

## What is guaranteed

Revolt's own `DriverTest` suite runs against this driver in CI, and E7 compares the output of
AMPHP's examples under our driver against a stock event loop: the runs must agree (`e7 differing=0`
in every smoke run).

## Limits

- **Signal callbacks are not implemented** — `SignalCallback` throws `UnsupportedFeatureException`.
- **A foreign scheduler is a hazard.** A fiber created by another event loop (Revolt's own
  `StreamSelectDriver`, a library's bare `new Fiber`) that makes a parkable syscall is suspended
  into our reactor, and in that program nothing calls `ignis_poll()` to resume it. Under Ignis,
  AMPHP runs on this driver — that is what E7 exists to prove — and `R-FOREIGN-FIBER` tracks the
  narrowing fix.
