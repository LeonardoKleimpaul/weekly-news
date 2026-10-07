<?php

namespace App\Entity;

use App\Repository\SubmissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SubmissionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_submission_author_friday', fields: ['author', 'friday'])]
class Submission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Conte a sua história.')]
    #[Assert\Length(max: 10000, maxMessage: 'Use no máximo {{ limit }} caracteres.')]
    private string $text = '';

    #[ORM\Column(length: 64)]
    private ?string $photoFilename = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $friday,
    ) {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFriday(): \DateTimeImmutable
    {
        return $this->friday;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = trim($text);
    }

    public function getPhotoFilename(): ?string
    {
        return $this->photoFilename;
    }

    public function setPhotoFilename(string $photoFilename): void
    {
        $this->photoFilename = $photoFilename;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markSaved(\DateTimeImmutable $now): void
    {
        if (null === $this->id) {
            $this->createdAt = $now;
        }
        $this->updatedAt = $now;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }
}
