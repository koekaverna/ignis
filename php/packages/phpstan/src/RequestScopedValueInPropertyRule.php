<?php

declare(strict_types=1);

namespace Ignis\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Keeping a request's value in an object that outlives the request.
 *
 * Under fibers the container's singletons are shared by every request on the thread, and the answer
 * to that is a per-fiber façade: `RequestStack` marked scoped, and `FiberEntityManager`, are single objects
 * whose every method reads `Ignis\Scope`, so a service holding one resolves per request. Measured —
 * six interleaved requests, each saw its own (V-96).
 *
 * What the façade cannot fix is a value taken *out* of it and stored: `$this->request =
 * $stack->getCurrentRequest()` in a constructor runs once for the whole process, and the object
 * answers with the first request's data for ever after. In the same measurement the captured
 * `Connection` was one socket, the warm-up request's, while every live request had its own — which
 * is V-85 with a different owner.
 *
 * No compiler pass can see this: it is an assignment in a method body, not a wiring decision. Static
 * analysis can, which is what this rule is. It deliberately does **not** complain about the
 * injection — that shape is correct and flagging it would fail an application at its first
 * controller.
 *
 * @implements Rule<Assign>
 */
final class RequestScopedValueInPropertyRule implements Rule
{
    /**
     * @param array<string, list<string>> $sources           class or interface => methods returning a request's own value
     * @param list<string>                $perRequestClasses classes whose instances do not outlive a request
     */
    public function __construct(
        private readonly array $sources,
        private readonly array $perRequestClasses,
    ) {}

    public function getNodeType(): string
    {
        return Assign::class;
    }

    /** @return list<\PHPStan\Rules\IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $target = $this->assignedProperty($node);
        if ($target === null) {
            return [];
        }
        if ($this->livesForOneRequest($scope)) {
            return [];
        }
        $source = $this->requestScopedSource($node->expr, $scope);
        if ($source === null) {
            return [];
        }
        [$class, $method] = $source;

        return [
            RuleErrorBuilder::message(\sprintf(
                '%s stores the result of %s::%s(), which belongs to one request, so this object answers with that '
                . 'request\'s data for every request after it. Keep the service and call it each time — a fiber-scoped '
                . 'façade resolves per request — or make this class per-request.',
                $target,
                $this->shortName($class),
                $method,
            ))
                ->identifier('ignis.requestScopedValueInProperty')
                ->build(),
        ];
    }

    /** The assignment target, as text, when it is a property that outlives the call. */
    private function assignedProperty(Assign $node): ?string
    {
        if ($node->var instanceof StaticPropertyFetch && $node->var->name instanceof Node\VarLikeIdentifier) {
            return 'A static property $' . $node->var->name->toString();
        }
        if ($node->var instanceof PropertyFetch && $node->var->name instanceof Node\Identifier) {
            return 'Property $' . $node->var->name->toString();
        }

        return null;
    }

    /**
     * An object Symfony rebuilds for every request may hold a request's value: that is what it is for.
     * The check is on the enclosing class, not on the assignment.
     */
    private function livesForOneRequest(Scope $scope): bool
    {
        $class = $scope->getClassReflection();
        if ($class === null) {
            return true;    // a closure or plain function: nothing outlives anything here
        }
        foreach ($this->perRequestClasses as $perRequest) {
            if ($class->is($perRequest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class and method the stored value came out of, anywhere in the expression.
     *
     * The whole expression rather than its outermost call, because the value that gets stored is
     * usually derived: `$stack->getCurrentRequest()?->query->get('tag')` ends in `get()` and
     * `spl_object_hash($manager->getConnection())` in a function call. Both pin the object to one
     * request just as surely as keeping the `Request` itself would — the first version of this rule
     * looked only at the outermost call and found neither in the code that provoked it (V-96).
     *
     * @return array{0: string, 1: string}|null
     */
    private function requestScopedSource(Node\Expr $expression, Scope $scope): ?array
    {
        foreach ((new NodeFinder())->findInstanceOf($expression, MethodCall::class) as $call) {
            $source = $this->sourceOfCall($call, $scope);
            if ($source !== null) {
                return $source;
            }
        }
        foreach ((new NodeFinder())->findInstanceOf($expression, NullsafeMethodCall::class) as $call) {
            $source = $this->sourceOfCall($call, $scope);
            if ($source !== null) {
                return $source;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    private function sourceOfCall(MethodCall|NullsafeMethodCall $call, Scope $scope): ?array
    {
        if (!$call->name instanceof Node\Identifier) {
            return null;
        }
        $method = $call->name->toString();
        $type = $scope->getType($call->var);
        foreach ($this->sources as $class => $methods) {
            if (\in_array($method, $methods, true) && (new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                return [$class, $method];
            }
        }

        return null;
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
