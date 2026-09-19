# `ignis/phpstan`

A PHPStan rule for the one defect fiber scope cannot fix by itself.

## What it catches

Under fibers the container's singletons are shared by every request on the thread. The answer to that
is a per-fiber **façade**: `FiberRequestStack` and `FiberEntityManager` are single objects whose every
method reads `Ignis\Scope`, so a service that holds one resolves per request. That shape is correct
and measured — six interleaved requests, each saw its own, with a different database connection each
time (V-96).

What a façade cannot fix is a value taken *out* of it and kept:

```php
final class Report
{
    public function __construct(private RequestStack $requests)
    {
        $this->locale = $requests->getCurrentRequest()?->getLocale();   // ← pinned
    }
}
```

The constructor runs once for the process. From then on the object answers with whichever request
happened to build it — in the same measurement, a captured `Connection` was one socket, the first
request's, while every live request had its own. No compiler pass can see this: it is an assignment
in a method body, not a wiring decision.

The rule reports it, naming the property and the call the value came from. It does **not** complain
about the injection — flagging that would fail an application at its first controller, to prevent a
shape that works.

## Install

```
composer require --dev ignis/phpstan
```

There is no `phpstan/extension-installer` requirement; include it yourself:

```neon
includes:
    - vendor/ignis/phpstan/extension.neon
```

## Configuring it

The sources are a list, not a guess. Add the ones your application has:

```neon
parameters:
    ignisRequestScoped:
        sources:
            App\Context\TenantContext: [current]
        perRequestClasses:
            - App\Controller\BaseController
```

`sources` is `class or interface => methods that return something belonging to one request`.
`perRequestClasses` is the escape hatch: an object your framework rebuilds for every request may keep
a request's value, because that is what it is for.

## What it does not do

It looks at assignments to properties, instance and static. A value put into an array element of a
property, or handed to another long-lived object through a setter, is the same defect and is not
reported — `S-SINGLETON-CAPTURE` carries that, and the runtime half of the answer is `S-EXCLUSIVE`:
a resource that refuses to be used from a fiber other than the one it was handed to.
