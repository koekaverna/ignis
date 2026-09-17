<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\Thing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Do two overlapping requests share Doctrine's identity map?
 *
 * The entity is loaded, mutated in memory (never flushed), the fiber parks, and the entity is read
 * back. A shared EntityManager means the second request's mutation is visible here.
 */
final class EmProbe
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tag = (string) $request->query->get('tag', '?');
        $ms  = (int) $request->query->get('ms', 0);

        $thing = $this->em->find(Thing::class, 1);
        $thing->name = $tag;                       // in-memory only
        $before = $thing->name;
        $emBefore = spl_object_id($this->em->getUnitOfWork());

        if ($ms > 0) {
            \Ignis\sleep($ms);
        }

        $after = $this->em->find(Thing::class, 1)->name;

        return new JsonResponse([
            'tag' => $tag,
            'fiber' => spl_object_id(\Fiber::getCurrent()),
            'uow' => $emBefore,
            'uow_after' => spl_object_id($this->em->getUnitOfWork()),
            'before' => $before,
            'after' => $after,
            'leaked' => $after !== $before,
        ]);
    }
}
