<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ScopedCart;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ScopedProbe
{
    public function __construct(private readonly ScopedCart $cart) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tag = (string) $request->query->get('tag', '');
        $this->cart->remember($tag);

        $milliseconds = (int) $request->query->get('ms', 0);
        if ($milliseconds > 0) {
            \Ignis\sleep($milliseconds);   // the other request runs here and writes its own tag
        }

        $recalled = $this->cart->recall();

        return new JsonResponse([
            'tag' => $tag,
            'recalled' => $recalled,
            'built_with' => $this->cart->builtWith(),
            // The service must answer the request that wrote to it, not the one that ran in between.
            'leaked' => $recalled !== $tag,
        ]);
    }
}
