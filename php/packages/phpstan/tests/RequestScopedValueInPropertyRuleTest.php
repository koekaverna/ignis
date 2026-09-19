<?php

declare(strict_types=1);

namespace Ignis\PHPStan\Tests;

use Ignis\PHPStan\RequestScopedValueInPropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * The rule has to separate two shapes that look alike and are not (V-96): keeping the fiber-scoped
 * façade, which resolves per request and is the design, and keeping a value taken out of it, which
 * pins the object to whichever request built it.
 *
 * @extends RuleTestCase<RequestScopedValueInPropertyRule>
 */
final class RequestScopedValueInPropertyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RequestScopedValueInPropertyRule(
            [
                'Symfony\\Component\\HttpFoundation\\RequestStack' => ['getCurrentRequest', 'getMainRequest'],
                'Doctrine\\ORM\\EntityManagerInterface' => ['getConnection'],
            ],
            ['Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController'],
        );
    }

    /**
     * The derived cases at the end matter most: they are what the rule missed in its first version.
     * What a singleton stores is rarely the `Request` — it is a locale, a tag, a connection hash —
     * and each of those pins the object just as surely (V-96, `bench/e21` `SingletonCapture`).
     */
    public function testItFlagsTheCaptureAndLeavesTheFacadeAlone(): void
    {
        $this->analyse([__DIR__ . '/data/singleton-capture.php'], [
            [
                'Property $request stores the result of RequestStack::getCurrentRequest(), which belongs to one request, '
                . 'so this object answers with that request\'s data for every request after it. Keep the service and call '
                . 'it each time — a fiber-scoped façade resolves per request — or make this class per-request.',
                19,
            ],
            [
                'Property $connection stores the result of EntityManagerInterface::getConnection(), which belongs to one '
                . 'request, so this object answers with that request\'s data for every request after it. Keep the service '
                . 'and call it each time — a fiber-scoped façade resolves per request — or make this class per-request.',
                20,
            ],
            [
                'Property $request stores the result of RequestStack::getMainRequest(), which belongs to one request, so '
                . 'this object answers with that request\'s data for every request after it. Keep the service and call it '
                . 'each time — a fiber-scoped façade resolves per request — or make this class per-request.',
                25,
            ],
            [
                'A static property $shared stores the result of RequestStack::getCurrentRequest(), which belongs to one '
                . 'request, so this object answers with that request\'s data for every request after it. Keep the service '
                . 'and call it each time — a fiber-scoped façade resolves per request — or make this class per-request.',
                55,
            ],
            [
                'Property $locale stores the result of RequestStack::getCurrentRequest(), which belongs to one request, so '
                . 'this object answers with that request\'s data for every request after it. Keep the service and call it '
                . 'each time — a fiber-scoped façade resolves per request — or make this class per-request.',
                69,
            ],
            [
                'Property $connectionHash stores the result of EntityManagerInterface::getConnection(), which belongs to '
                . 'one request, so this object answers with that request\'s data for every request after it. Keep the '
                . 'service and call it each time — a fiber-scoped façade resolves per request — or make this class '
                . 'per-request.',
                70,
            ],
        ]);
    }
}
