<?php

namespace App\Entity;

use App\Repository\PresentationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PresentationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_presentation_friday', fields: ['friday'])]
class Presentation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $position = 0;

    /** @param list<int> $submissionOrder */
    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $friday,
        #[ORM\Column(type: Types::JSON)]
        private array $submissionOrder,
        #[ORM\Column]
        private \DateTimeImmutable $startedAt,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $startedBy,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFriday(): \DateTimeImmutable
    {
        return $this->friday;
    }

    /** @return list<int> */
    public function getSubmissionOrder(): array
    {
        return $this->submissionOrder;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getStartedBy(): ?User
    {
        return $this->startedBy;
    }

    public function isFinished(): bool
    {
        return $this->position >= count($this->submissionOrder);
    }

    public function getCurrentSubmissionId(): ?int
    {
        return $this->submissionOrder[$this->position] ?? null;
    }

    /** @return list<int> */
    public function getRevealedSubmissionIds(): array
    {
        return array_slice($this->submissionOrder, 0, $this->position + 1);
    }

    public function advance(): void
    {
        if (!$this->isFinished()) {
            ++$this->position;
        }
    }
}
