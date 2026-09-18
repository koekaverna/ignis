<?php
declare(strict_types=1);
namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Do two overlapping requests share one database connection? (E24)
 *
 * The query parks the fiber inside libpq for `sleep` seconds and asks the server to echo the
 * request's own tag back. With a shared `Doctrine\DBAL\Connection` the second request enters the
 * same PostgreSQL connection while the first is still reading its result.
 */
final class PgProbe
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tag = (string) $request->query->get('tag', '?');
        $seconds = (float) $request->query->get('sleep', 0);

        try {
            $marker = $this->em->getConnection()
                ->executeQuery('SELECT pg_sleep(CAST(? AS double precision)), CAST(? AS text) AS marker', [$seconds, $tag])
                ->fetchAssociative()['marker'] ?? null;

            return new JsonResponse([
                'tag' => $tag,
                'marker' => $marker,
                'match' => $marker === $tag,
                'connection' => spl_object_id($this->em->getConnection()),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'tag' => $tag,
                'marker' => null,
                'match' => false,
                'connection' => spl_object_id($this->em->getConnection()),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
