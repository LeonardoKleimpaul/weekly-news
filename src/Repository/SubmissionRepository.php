<?php

namespace App\Repository;

use App\Entity\Submission;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Submission>
 */
class SubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Submission::class);
    }

    /** @return array<string, Submission> Indexed by Friday (Y-m-d). */
    public function findForYear(User $author, int $year): array
    {
        $submissions = $this->createQueryBuilder('s')
            ->where('s.author = :author')
            ->andWhere('s.friday >= :start AND s.friday < :end')
            ->setParameter('author', $author)
            ->setParameter('start', new \DateTimeImmutable($year.'-01-01'), Types::DATE_IMMUTABLE)
            ->setParameter('end', new \DateTimeImmutable(($year + 1).'-01-01'), Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();

        $byFriday = [];
        foreach ($submissions as $submission) {
            $byFriday[$submission->getFriday()->format('Y-m-d')] = $submission;
        }

        return $byFriday;
    }
}
