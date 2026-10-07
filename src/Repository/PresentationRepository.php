<?php

namespace App\Repository;

use App\Entity\Presentation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Presentation> */
class PresentationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Presentation::class);
    }

    public function hasForFriday(\DateTimeImmutable $friday): bool
    {
        // A scalar query also sees starts committed by another request.
        return (bool) $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->where('p.friday = :friday')->setParameter('friday', $friday, Types::DATE_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return array<string, Presentation> */
    public function findForYear(int $year): array
    {
        $presentations = $this->createQueryBuilder('p')
            ->where('p.friday >= :start AND p.friday < :end')
            ->setParameter('start', new \DateTimeImmutable($year.'-01-01'), Types::DATE_IMMUTABLE)
            ->setParameter('end', new \DateTimeImmutable(($year + 1).'-01-01'), Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();

        $byFriday = [];
        foreach ($presentations as $presentation) {
            $byFriday[$presentation->getFriday()->format('Y-m-d')] = $presentation;
        }

        return $byFriday;
    }
}
