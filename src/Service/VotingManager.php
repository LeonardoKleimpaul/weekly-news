<?php

namespace App\Service;

use App\Entity\Presentation;
use App\Entity\User;
use App\Entity\Vote;
use App\Repository\SubmissionRepository;
use App\Repository\UserRepository;
use App\Repository\VoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

class VotingManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubmissionRepository $submissions,
        private UserRepository $users,
        private VoteRepository $votes,
        private RankingService $ranking,
        private ClockInterface $clock,
        private LockFactory $locks,
    ) {
    }

    /** @return array{cast: int, total: int, complete: bool, eligible: bool, voted: bool} */
    public function status(Presentation $presentation, User $user): array
    {
        $total = count($presentation->getBallot());
        $cast = $this->votes->count(['presentation' => $presentation]);

        return [
            'cast' => $cast,
            'total' => $total,
            'complete' => $total > 0 && $cast === $total,
            'eligible' => in_array($user->getId(), array_column($presentation->getBallot(), 'user_id'), true),
            'voted' => null !== $this->votes->findOneBy(['presentation' => $presentation, 'voter' => $user]),
        ];
    }

    public function open(Presentation $presentation, User $administrator): void
    {
        $this->assertAdministrator($administrator);
        $lock = $this->locks->createLock('friday-'.$presentation->getFriday()->format('Y-m-d'));
        $lock->acquire(true);
        try {
            $this->entityManager->refresh($presentation);
            if (null !== $presentation->getVotingOpenedAt()) {
                return;
            }
            if (!$presentation->isFinished()) {
                throw new \DomainException('Conclua a apresentação antes de abrir a votação.');
            }
            $ballot = [];
            foreach ($presentation->getSubmissionOrder() as $id) {
                $submission = $this->submissions->find($id);
                if (null === $submission) {
                    throw new \DomainException('Uma história desta edição não está mais disponível.');
                }
                $author = $submission->getAuthor();
                $ballot[] = ['user_id' => $author->getId(), 'name' => $author->getName()];
            }
            if (count($ballot) < 2) {
                throw new \DomainException('A votação precisa de pelo menos dois participantes, pois o voto em si mesmo não é permitido.');
            }
            usort($ballot, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']) ?: ($a['user_id'] <=> $b['user_id']));
            $presentation->openVoting($ballot, $this->clock->now());
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    public function vote(Presentation $presentation, User $voter, int $candidateId): void
    {
        $lock = $this->locks->createLock('friday-'.$presentation->getFriday()->format('Y-m-d'));
        $lock->acquire(true);
        try {
            $this->entityManager->refresh($presentation);
            if (null === $presentation->getVotingOpenedAt() || $presentation->isClosed()) {
                throw new \DomainException('A votação desta edição não está aberta.');
            }
            $participants = array_column($presentation->getBallot(), 'user_id');
            if (!$voter->isActive() || !in_array($voter->getId(), $participants, true)) {
                throw new \DomainException('Somente os participantes desta edição podem votar.');
            }
            if (null !== $this->votes->findOneBy(['presentation' => $presentation, 'voter' => $voter])) {
                throw new \DomainException('Seu voto já foi confirmado e não pode ser alterado.');
            }
            if ($candidateId === $voter->getId()) {
                throw new \DomainException('Você não pode votar em si mesmo.');
            }
            $candidate = in_array($candidateId, $participants, true) ? $this->users->find($candidateId) : null;
            if (null === $candidate) {
                throw new \DomainException('Escolha um participante desta edição.');
            }
            $vote = new Vote($presentation, $voter, $candidate, $this->clock->now());
            $this->entityManager->persist($vote);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    public function reveal(Presentation $presentation, User $administrator): void
    {
        $this->assertAdministrator($administrator);
        $lock = $this->locks->createLock('friday-'.$presentation->getFriday()->format('Y-m-d'));
        $lock->acquire(true);
        try {
            $this->entityManager->refresh($presentation);
            if ($presentation->isClosed()) {
                return;
            }
            if (null === $presentation->getVotingOpenedAt() || !$this->status($presentation, $administrator)['complete']) {
                throw new \DomainException('Aguarde o voto de todos os participantes antes de revelar.');
            }
            $counts = $this->votes->tally($presentation);
            $rows = array_map(static fn (array $participant) => [...$participant, 'points' => $counts[$participant['user_id']] ?? 0], $presentation->getBallot());
            $presentation->close($this->ranking->rank($rows), $this->clock->now());
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    private function assertAdministrator(User $user): void
    {
        if (!$user->isActive() || !$user->isAdmin()) {
            throw new \DomainException('Somente administradores ativos podem controlar a votação.');
        }
    }
}
