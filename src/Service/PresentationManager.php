<?php

namespace App\Service;

use App\Entity\Presentation;
use App\Entity\Submission;
use App\Entity\User;
use App\Repository\PresentationRepository;
use App\Repository\SubmissionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

class PresentationManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PresentationRepository $presentations,
        private SubmissionRepository $submissions,
        private UserRepository $users,
        private FridayCalendar $calendar,
        private ClockInterface $clock,
        private LockFactory $locks,
    ) {
    }

    /** @return array{participants: list<array{user: User, submitted: bool}>, submission_ids: list<int>, ready: bool} */
    public function readiness(\DateTimeImmutable $friday): array
    {
        $byAuthor = [];
        foreach ($this->submissions->findBy(['friday' => $friday]) as $submission) {
            $byAuthor[$submission->getAuthor()->getId()] = $submission->getId();
        }
        $participants = [];
        $ids = [];
        foreach ($this->users->findBy(['isActive' => true], ['name' => 'ASC', 'id' => 'ASC']) as $user) {
            $id = $byAuthor[$user->getId()] ?? null;
            $participants[] = ['user' => $user, 'submitted' => null !== $id];
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return ['participants' => $participants, 'submission_ids' => $ids, 'ready' => [] !== $participants && count($participants) === count($ids)];
    }

    public function canStartOn(\DateTimeImmutable $friday): bool
    {
        // TODO: REATIVAR a validação de data após os testes manuais. Esta regra precisa voltar ao código.
        // Para restaurar, descomente o retorno original e remova o retorno temporário abaixo.
        // return $this->calendar->isFriday($friday) && $friday->format('Y-m-d') <= $this->calendar->today()->format('Y-m-d');
        return $this->calendar->isFriday($friday);
    }

    public function start(\DateTimeImmutable $friday, User $administrator): Presentation
    {
        $this->assertAdministrator($administrator);
        $lock = $this->locks->createLock('friday-'.$friday->format('Y-m-d'));
        $lock->acquire(true);
        try {
            $existing = $this->presentations->findOneBy(['friday' => $friday]);
            if (null !== $existing) {
                return $existing;
            }
            if (!$this->canStartOn($friday)) {
                throw new \DomainException('A apresentação pode começar a partir da sexta-feira escolhida.');
            }
            $readiness = $this->readiness($friday);
            if (!$readiness['ready']) {
                throw new \DomainException('Aguarde o envio de todos os usuários ativos antes de iniciar.');
            }
            $order = (new \Random\Randomizer())->shuffleArray($readiness['submission_ids']);
            $presentation = new Presentation($friday, $order, $this->clock->now(), $administrator);
            $this->entityManager->persist($presentation);
            $this->entityManager->flush();

            return $presentation;
        } finally {
            $lock->release();
        }
    }

    public function advance(\DateTimeImmutable $friday, int $expectedPosition, User $administrator): void
    {
        $this->assertAdministrator($administrator);
        $lock = $this->locks->createLock('friday-'.$friday->format('Y-m-d'));
        $lock->acquire(true);
        try {
            $presentation = $this->presentations->findOneBy(['friday' => $friday]);
            if (null === $presentation) {
                throw new \DomainException('Inicie a apresentação antes de avançar.');
            }
            $this->entityManager->refresh($presentation);
            if ($presentation->isFinished() || $presentation->getPosition() !== $expectedPosition) {
                throw new \DomainException('A apresentação já avançou. Confira a história atual antes de continuar.');
            }
            $presentation->advance();
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    public function canViewPhoto(Submission $submission): bool
    {
        $presentation = $this->presentations->findOneBy(['friday' => $submission->getFriday()]);

        return null !== $presentation && in_array($submission->getId(), $presentation->getRevealedSubmissionIds(), true);
    }

    private function assertAdministrator(User $user): void
    {
        if (!$user->isActive() || !$user->isAdmin()) {
            throw new \DomainException('Somente administradores ativos podem controlar a apresentação.');
        }
    }
}
