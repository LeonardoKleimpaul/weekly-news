<?php

namespace App\Service;

use App\Entity\Submission;
use App\Repository\PresentationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Lock\LockFactory;

class SubmissionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubmissionPhotoStorage $photos,
        private FridayCalendar $calendar,
        private ClockInterface $clock,
        private PresentationRepository $presentations,
        private LockFactory $locks,
    ) {
    }

    public function save(Submission $submission, ?UploadedFile $photo): void
    {
        $lock = $this->locks->createLock('friday-'.$submission->getFriday()->format('Y-m-d'));
        $lock->acquire(true);
        $previousPhoto = $submission->getPhotoFilename();
        $newPhoto = null;
        try {
            if ($this->presentations->hasForFriday($submission->getFriday())) {
                throw new \DomainException('A apresentação já começou. Os envios desta sexta não podem mais ser alterados.');
            }
            if (!$this->calendar->canSubmit($submission->getFriday())) {
                throw new \DomainException('O prazo de envio desta sexta-feira terminou.');
            }
            if (null !== $photo) {
                $newPhoto = $this->photos->store($photo);
                $submission->setPhotoFilename($newPhoto);
            }
            $submission->markSaved($this->clock->now());
            $this->entityManager->persist($submission);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->photos->remove($newPhoto);
            throw $exception;
        } finally {
            $lock->release();
        }

        if (null !== $newPhoto) {
            $this->photos->remove($previousPhoto);
        }
    }
}
