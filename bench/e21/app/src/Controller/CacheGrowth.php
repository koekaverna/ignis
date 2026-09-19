<?php
declare(strict_types=1);

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a cache pool accumulates when nobody calls `reset()` on it.
 *
 * `FiberServicesResetter` stopped the reset in fiber mode (V-95), so the question is what that
 * costs. For an `AbstractAdapter` pool — `cache.app` here is a `FilesystemAdapter` — `reset()` does
 * not touch the cached values: it commits deferred writes and clears `$ids`, a key-to-id map. So the
 * cost is that map, and this route measures it rather than arguing about it.
 */
final class CacheGrowth
{
    public function __construct(private readonly CacheItemPoolInterface $cache) {}

    public function __invoke(Request $request): JsonResponse
    {
        $key = 'probe_' . $request->query->get('tag', '0');
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            $item->set($key);
            $this->cache->save($item);
        }

        // Memory rather than the adapter's internals: what `reset()` drops differs by Symfony version,
        // and the question is whether anything grows without it, which memory answers directly.
        return new JsonResponse([
            'key' => $key,
            'adapter' => $this->cache::class,
            'memory' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
        ]);
    }
}
