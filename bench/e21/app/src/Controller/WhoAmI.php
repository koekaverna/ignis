<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class WhoAmI
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $tag = (string) $request->query->get('tag', '?');
        $ms  = (int) $request->query->get('ms', '300');

        $before = $this->snapshot($request);
        fwrite(STDERR, sprintf("[%s] %s before=%s\n", $tag, self::now(), json_encode($before)));

        if ($ms > 0 && \function_exists('Ignis\\sleep')) {
            \Ignis\sleep($ms);
        }

        $after = $this->snapshot($request);
        fwrite(STDERR, sprintf("[%s] %s after =%s\n", $tag, self::now(), json_encode($after)));

        return new JsonResponse([
            'tag'     => $tag,
            'fiber'   => spl_object_id(\Fiber::getCurrent() ?? new \stdClass()),
            'before'  => $before,
            'after'   => $after,
            'leaked'  => $before !== $after,
        ]);
    }

    /** @return array<string,mixed> */
    private function snapshot(Request $request): array
    {
        return [
            'token_storage'    => $this->tokenStorage->getToken()?->getUserIdentifier(),
            'security_getUser' => $this->security->getUser()?->getUserIdentifier(),
            'request_user'     => $request->getUser(),
            'stack_user'       => $this->requestStack->getCurrentRequest()?->getUser(),
            'php_auth_user'    => $_SERVER['PHP_AUTH_USER'] ?? null,
            'is_admin'         => $this->security->isGranted('ROLE_ADMIN'),
        ];
    }

    private static function now(): string
    {
        return sprintf('%.3f', microtime(true));
    }
}
