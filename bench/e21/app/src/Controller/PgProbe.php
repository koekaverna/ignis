<?php
declare(strict_types=1);
namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tag = (string) $request->query->get('tag', '?');
        $seconds = (float) $request->query->get('sleep', 0);
        // The registry resolves the service on every call, so a non-shared connection is built per
        // request rather than kept in this controller's property (S-DBAL-DIRECT).
        $connection = $request->query->get('connection') === 'reporting'
            ? $this->registry->getConnection('reporting')
            : $this->em->getConnection();

        try {
            $row = $connection
                ->executeQuery('SELECT pg_sleep(CAST(? AS double precision)), CAST(? AS text) AS marker, pg_backend_pid() AS backend', [$seconds, $tag])
                ->fetchAssociative();

            return new JsonResponse([
                'tag' => $tag,
                'marker' => $row['marker'] ?? null,
                'match' => ($row['marker'] ?? null) === $tag,
                'backend' => $row['backend'] ?? null,
                'connection' => spl_object_id($connection),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'tag' => $tag,
                'marker' => null,
                'match' => false,
                'connection' => spl_object_id($connection),
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
