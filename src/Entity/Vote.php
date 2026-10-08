<?php

namespace App\Entity;

use App\Repository\VoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_vote_presentation_voter', fields: ['presentation', 'voter'])]
class Vote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Presentation $presentation,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private User $voter,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private User $candidate,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPresentation(): Presentation
    {
        return $this->presentation;
    }

    public function getVoter(): User
    {
        return $this->voter;
    }

    public function getCandidate(): User
    {
        return $this->candidate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
