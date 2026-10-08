<?php

namespace App\Tests\Functional;

use App\Entity\Presentation;
use App\Entity\Submission;
use App\Entity\User;
use App\Entity\Vote;
use App\Repository\PresentationRepository;
use App\Repository\VoteRepository;
use App\Service\UserManager;
use App\Service\VotingManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Form;

class VotingFlowTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const FRIDAY = '2026-10-09';
    private const ROOM = '/sextas/'.self::FRIDAY.'/apresentacao';
    private KernelBrowser $client;
    private User $admin;
    private User $ana;
    private User $bruno;

    protected function setUp(): void
    {
        self::mockTime('2026-10-07 15:00:00 UTC');
        $this->client = static::createClient([], ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertStringEndsWith('_test', $em->getConnection()->getDatabase());
        $em->createQuery('DELETE FROM App\Entity\Presentation p')->execute();
        $em->createQuery('DELETE FROM App\Entity\Submission s')->execute();
        $em->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->admin = $this->user('host@example.com', 'Administrador', true);
        $this->ana = $this->user('ana@example.com', 'Ana <amiga>');
        $this->bruno = $this->user('bruno@example.com', 'Bruno');
        $this->client->loginUser($this->admin);
    }

    public function testAdminOpensVotingOnlyAfterAllStoriesAndMembersCannotControlIt(): void
    {
        $this->prepare(finish: false);
        $presentation = $this->presentation();
        try {
            static::getContainer()->get(VotingManager::class)->open($presentation, $this->admin);
            self::fail('Voting must wait for the final story.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Conclua a apresentação', $exception->getMessage());
        }
        $this->client->loginUser($this->ana);
        foreach (['/votacao/abrir', '/resultado/revelar'] as $suffix) {
            $this->client->request('POST', '/admin/sextas/'.self::FRIDAY.$suffix);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->loginUser($this->admin);
        foreach ($presentation->getSubmissionOrder() as $id) $this->advance();
        $this->client->request('GET', self::ROOM);
        self::assertSelectorExists('#voting-open');
        self::assertSelectorNotExists('#vote-confirm');
        $this->open();
        $this->client->request('GET', self::ROOM);
        self::assertSelectorExists('[data-phase="voting"][data-closed="false"]');
        self::assertSelectorCount(2, '.vote-option');
        self::assertSelectorCount(2, 'input[type="radio"]');
        self::assertSelectorNotExists('input[type="radio"][value="'.$this->admin->getId().'"]');
        self::assertSelectorTextContains('.vote-options', 'Ana <amiga>');
        self::assertSelectorTextContains('.voting-progress', '0 de 3');
        self::assertSelectorExists('a[href="/ranking"]');
    }

    public function testVoteRequiresAChoiceValidCsrfAndCannotBeForYourself(): void
    {
        $this->prepare();
        $this->open();
        $form = $this->voteForm($this->admin, $this->ana);
        $parameters = $form->getPhpValues();
        $parameters['vote']['_token'] = 'invalid';
        $this->client->request('POST', '/sextas/'.self::FRIDAY.'/votar', $parameters);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->votes()->count([]));
        $parameters = $this->voteForm($this->admin, $this->ana)->getPhpValues();
        unset($parameters['vote']['candidate']);
        $this->client->request('POST', '/sextas/'.self::FRIDAY.'/votar', $parameters);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.vote-options', 'Selecione um participante');
        $parameters = $this->voteForm($this->admin, $this->ana)->getPhpValues();
        $parameters['vote']['candidate'] = (string) $this->admin->getId();
        $this->client->request('POST', '/sextas/'.self::FRIDAY.'/votar', $parameters);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->votes()->count([]));
        try {
            static::getContainer()->get(VotingManager::class)->vote($this->presentation(), $this->admin, $this->admin->getId());
            self::fail('The service must also prevent self voting.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('em si mesmo', $exception->getMessage());
        }
        $this->cast($this->admin, $this->ana);
        self::assertSame(1, $this->votes()->count([]));
        $this->client->request('GET', self::ROOM);
        self::assertSelectorNotExists('#vote-confirm');
        self::assertSelectorTextContains('#room-title', 'Seu voto está confirmado');
    }

    public function testVoteCannotBeChangedByReloadingOrSubmittingAnOldForm(): void
    {
        $this->prepare();
        $this->open();
        $form = $this->voteForm($this->admin, $this->ana);
        $this->client->submit($form);
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->submit($form, ['vote[candidate]' => (string) $this->bruno->getId()]);
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'não pode ser alterado');
        self::assertSame(1, $this->votes()->count([]));
        $vote = $this->votes()->findOneBy(['voter' => $this->admin->getId()]);
        self::assertSame($this->ana->getId(), $vote->getCandidate()->getId());
        self::assertSame('2026-10-07 15:00:00', $vote->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->client->request('GET', self::ROOM);
        self::assertSelectorNotExists('#vote-confirm');
        self::assertSelectorTextContains('.voting-progress', '1 de 3');
    }

    public function testNewAccountsCannotJoinTheFrozenBallotAndDuplicatesHaveADatabaseConstraint(): void
    {
        $this->prepare();
        $this->open();
        $form = $this->voteForm($this->admin, $this->ana);
        $outsider = $this->user('new@example.com', 'Novo membro');
        $this->client->loginUser($outsider);
        $this->client->request('GET', self::ROOM);
        self::assertSelectorNotExists('#vote-confirm');
        self::assertSelectorTextContains('.voting-progress', '0 de 3');
        $this->client->submit($form);
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'Somente os participantes');
        self::assertSame(0, $this->votes()->count([]));
        $this->cast($this->admin, $this->ana);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Vote($this->presentation(), $em->find(User::class, $this->admin->getId()), $em->find(User::class, $this->bruno->getId()), new \DateTimeImmutable()));
        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testResultIsHiddenUntilEveryoneVotesAndTheAdminRevealsIt(): void
    {
        $this->prepare();
        $this->open();
        $oldVoteForm = $this->voteForm($this->admin, $this->ana);
        $this->cast($this->admin, $this->ana);
        try {
            static::getContainer()->get(VotingManager::class)->reveal($this->presentation(), $this->admin);
            self::fail('Reveal must wait for all votes.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Aguarde o voto de todos', $exception->getMessage());
        }
        $this->client->request('GET', self::ROOM.'/estado');
        self::assertSelectorNotExists('.score-list');
        self::assertSelectorNotExists('#voting-reveal');
        $this->client->request('GET', '/ranking');
        self::assertSelectorCount(3, '[data-points="0"]');
        $this->cast($this->ana, $this->admin);
        $this->cast($this->bruno, $this->admin);
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', self::ROOM);
        self::assertSelectorExists('[data-phase="result-ready"][data-closed="false"]');
        self::assertSelectorExists('#voting-reveal');
        self::assertSelectorNotExists('.weekly-winners');
        self::assertSame([], $this->presentation()->getResult());
        $reveal = $crawler->selectButton('Revelar resultado')->form();
        $this->client->submit($reveal);
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-phase="result"][data-closed="true"]');
        self::assertSelectorTextContains('.weekly-winners', 'Administrador');
        self::assertSelectorTextContains('.weekly-winners', '2 votos');
        self::assertSelectorCount(3, '.score-list li');
        self::assertTrue($this->presentation()->isClosed());
        $result = $this->presentation()->getResult();
        self::assertSame([2, 1, 0], array_column($result, 'points'));
        $closedAt = $this->presentation()->getClosedAt();
        $this->client->submit($reveal); // Refresh/repeated reveal must not award points twice.
        self::assertResponseRedirects(self::ROOM, 303);
        self::assertSame($result, $this->presentation()->getResult());
        self::assertEquals($closedAt, $this->presentation()->getClosedAt());
        $this->client->submit($oldVoteForm);
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'não está aberta');
        self::assertSame(3, $this->votes()->count([]));
        $this->client->request('GET', '/ranking');
        self::assertSelectorExists('[data-ranking-user="'.$this->admin->getId().'"][data-points="2"]');
        self::assertSelectorExists('[data-ranking-user="'.$this->ana->getId().'"][data-points="1"]');
        $this->client->loginUser($this->ana);
        $this->client->request('GET', self::ROOM.'/estado');
        self::assertSelectorExists('.weekly-winners');
        self::assertSelectorNotExists('#vote-confirm');
        $this->client->request('GET', '/sextas/'.self::FRIDAY);
        self::assertSelectorNotExists('textarea');
    }

    public function testRankingAccumulatesVotesAcrossEditionsAndKeepsTiesAndInactiveMembers(): void
    {
        $this->prepare();
        $this->open();
        $this->cast($this->admin, $this->ana);
        $this->cast($this->ana, $this->bruno);
        $this->cast($this->bruno, $this->admin);
        $this->reveal();
        $this->client->request('GET', self::ROOM);
        self::assertSelectorTextContains('#room-title', 'empate');
        self::assertSelectorCount(3, '.weekly-winners > div');
        self::assertSame([1, 1, 1], array_column($this->presentation()->getResult(), 'rank'));
        $this->client->request('GET', '/ranking');
        self::assertSelectorCount(3, '[data-points="1"]');
        foreach ($this->client->getCrawler()->filter('.score-position') as $position) {
            self::assertSame('1º', $position->textContent);
        }
        $this->prepare('2026-10-16');
        $this->open('2026-10-16');
        $this->cast($this->admin, $this->ana, '2026-10-16');
        $this->cast($this->ana, $this->admin, '2026-10-16');
        $this->cast($this->bruno, $this->admin, '2026-10-16');
        $this->reveal('2026-10-16');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->find(User::class, $this->bruno->getId())->setIsActive(false);
        $em->flush();
        $this->client->request('GET', '/ranking');
        self::assertSelectorExists('[data-ranking-user="'.$this->admin->getId().'"][data-points="3"]');
        self::assertSelectorExists('[data-ranking-user="'.$this->ana->getId().'"][data-points="2"]');
        self::assertSelectorExists('[data-ranking-user="'.$this->bruno->getId().'"][data-points="1"]');
        self::assertSame(6, $this->votes()->count([]));
    }

    public function testOpeningAndRevealingRequireCsrfAndRankingRequiresLogin(): void
    {
        $this->prepare();
        $this->client->request('POST', '/admin/sextas/'.self::FRIDAY.'/votacao/abrir', ['form' => ['_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->presentation()->getVotingOpenedAt());
        $this->open();
        $this->cast($this->admin, $this->ana);
        $this->cast($this->ana, $this->bruno);
        $this->cast($this->bruno, $this->admin);
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/sextas/'.self::FRIDAY.'/resultado/revelar', ['form' => ['_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->presentation()->isClosed());
        $this->client->restart();
        $this->client->request('GET', '/ranking');
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testOnePersonEditionCannotOpenVotingWithoutAnEligibleCandidate(): void
    {
        $this->ana->setIsActive(false);
        $this->bruno->setIsActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->prepare();
        $crawler = $this->client->request('GET', self::ROOM);
        self::assertSelectorExists('#voting-open[disabled]');
        $this->client->submit($crawler->filter('#voting-open')->form());
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'pelo menos dois participantes');
        self::assertNull($this->presentation()->getVotingOpenedAt());
    }

    private function user(string $email, string $name, bool $admin = false): User
    {
        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setIsAdmin($admin);
        static::getContainer()->get(UserManager::class)->save($user, 'Senha-do-teste-123');

        return $user;
    }

    private function prepare(string $friday = self::FRIDAY, bool $finish = true): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([$this->admin, $this->ana, $this->bruno] as $user) {
            $submission = new Submission($em->find(User::class, $user->getId()), new \DateTimeImmutable($friday));
            $submission->setText('História de '.$user->getName());
            $submission->setPhotoFilename(bin2hex(random_bytes(16)).'.png');
            $em->persist($submission);
        }
        $em->flush();
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/sextas/'.$friday.'/apresentacao');
        $this->client->submit($crawler->selectButton('Iniciar apresentação')->form());
        self::assertResponseRedirects('/sextas/'.$friday.'/apresentacao', 303);
        if ($finish) foreach ($this->presentation($friday)->getSubmissionOrder() as $id) $this->advance($friday);
    }

    private function advance(string $friday = self::FRIDAY): void
    {
        $crawler = $this->client->request('GET', '/sextas/'.$friday.'/apresentacao');
        $this->client->submit($crawler->filter('#presentation-advance')->form());
        self::assertResponseRedirects('/sextas/'.$friday.'/apresentacao', 303);
    }

    private function open(string $friday = self::FRIDAY): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/sextas/'.$friday.'/apresentacao');
        $this->client->submit($crawler->selectButton('Abrir votação')->form());
        self::assertResponseRedirects('/sextas/'.$friday.'/apresentacao', 303);
    }

    private function voteForm(User $voter, User $candidate, string $friday = self::FRIDAY): Form
    {
        $this->client->loginUser($voter);
        $crawler = $this->client->request('GET', '/sextas/'.$friday.'/apresentacao');

        return $crawler->selectButton('Confirmar voto')->form(['vote[candidate]' => (string) $candidate->getId()]);
    }

    private function cast(User $voter, User $candidate, string $friday = self::FRIDAY): void
    {
        $this->client->submit($this->voteForm($voter, $candidate, $friday));
        self::assertResponseRedirects('/sextas/'.$friday.'/apresentacao', 303);
    }

    private function reveal(string $friday = self::FRIDAY): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/sextas/'.$friday.'/apresentacao');
        $this->client->submit($crawler->selectButton('Revelar resultado')->form());
        self::assertResponseRedirects('/sextas/'.$friday.'/apresentacao', 303);
    }

    private function presentation(string $friday = self::FRIDAY): Presentation
    {
        return static::getContainer()->get(PresentationRepository::class)->findOneBy(['friday' => new \DateTimeImmutable($friday)]);
    }

    private function votes(): VoteRepository
    {
        return static::getContainer()->get(VoteRepository::class);
    }
}
