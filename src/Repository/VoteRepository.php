<?php

namespace App\Repository;

use App\Entity\Presentation;
use App\Entity\User;
use App\Entity\Vote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Vote> */
class VoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vote::class);
    }

    /** @return array<int, int> Votes indexed by candidate ID. */
    public function tally(Presentation $presentation): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.candidate) AS candidate, COUNT(v.id) AS points')
            ->where('v.presentation = :presentation')->setParameter('presentation', $presentation)
            ->groupBy('v.candidate')->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['candidate']] = (int) $row['points'];
        }

        return $counts;
    }

    /** @return list<array{user_id: int, name: string, points: int}> */
    public function rankingPoints(): array
    {
        // Only closed editions count, so the ranking cannot reveal an ongoing vote.
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('u.id AS user_id, u.name AS name, COUNT(p.id) AS points')
            ->from(User::class, 'u')
            ->leftJoin(Vote::class, 'v', Join::WITH, 'v.candidate = u')
            ->leftJoin('v.presentation', 'p', Join::WITH, 'p.closedAt IS NOT NULL')
            ->groupBy('u.id')->addGroupBy('u.name')->addGroupBy('u.isActive')
            ->having('u.isActive = true OR COUNT(p.id) > 0')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row) => ['user_id' => (int) $row['user_id'], 'name' => $row['name'], 'points' => (int) $row['points']], $rows);
    }
}
