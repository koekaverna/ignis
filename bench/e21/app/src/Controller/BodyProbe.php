<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * What Symfony actually received. The embed SAPI has no `read_post`, so `php://input` is empty
 * unless the runtime backs it — and until 2026-09-20 it did so only in classic mode, which meant a
 * PUT with a urlencoded body reached Symfony with nothing: the runtime fills `$_POST` for POST
 * alone, and `createFromGlobals()` re-parses `php://input` for PUT/PATCH/DELETE.
 */
final class BodyProbe
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'method' => $request->getMethod(),
            'parsed' => $request->request->all(),
            'content_length' => \strlen($request->getContent()),
        ]);
    }
}
