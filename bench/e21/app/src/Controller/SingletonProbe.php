<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\SingletonCapture;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SingletonProbe
{
    public function __construct(private readonly SingletonCapture $singleton) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tag = (string) $request->query->get('tag', '');
        $milliseconds = (int) $request->query->get('ms', 0);
        if ($milliseconds > 0) {
            \Ignis\sleep($milliseconds);
        }

        $live = $this->singleton->liveTag();
        $captured = $this->singleton->capturedTag();

        return new JsonResponse([
            'tag' => $tag,
            'live' => $live,
            'captured' => $captured,
            'live_connection' => $this->singleton->liveConnection(),
            'captured_connection' => $this->singleton->capturedConnection(),
            // The façade must answer this request; the captured value cannot and is the defect.
            'leaked' => $live !== $tag,
            'captured_leaked' => $captured !== $tag,
        ]);
    }
}
