<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;

/**
 * `security.logout_url_generator` keeps which firewall *this request* is on in a property, set per
 * request by the firewall listener. Two firewalls and two overlapping requests is the only shape in
 * which that shows: with one firewall every request writes the same value.
 */
final class LogoutProbe
{
    public function __construct(private readonly LogoutUrlGenerator $generator) {}

    public function __invoke(Request $request): JsonResponse
    {
        $expected = str_starts_with($request->getPathInfo(), '/admin') ? '/admin/logout' : '/logout';

        $milliseconds = (int) $request->query->get('ms', 0);
        if ($milliseconds > 0) {
            \Ignis\sleep($milliseconds);   // the other firewall's request runs here
        }

        $seen = $this->generator->getLogoutPath();

        return new JsonResponse(['expected' => $expected, 'seen' => $seen, 'leaked' => $seen !== $expected]);
    }
}
