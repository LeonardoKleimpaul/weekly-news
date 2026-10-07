<?php

namespace App\Tests\Functional;

use App\Entity\Presentation;
use App\Entity\Submission;
use App\Entity\User;
use App\Repository\PresentationRepository;
use App\Repository\SubmissionRepository;
use App\Service\SubmissionManager;
use App\Service\SubmissionPhotoStorage;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;

class PresentationFlowTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const FRIDAY = '2026-10-09';
    private const ROOM = '/sextas/'.self::FRIDAY.'/apresentacao';
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j7S8AAAAASUVORK5CYII=';
    private KernelBrowser $client;
    private User $admin;
    private User $member;
    private User $inactive;
    private array $files = [];

    protected function setUp(): void
    {
        self::mockTime('2026-10-09 18:00:00 UTC');
        $this->client = static::createClient([], ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertStringEndsWith('_test', $em->getConnection()->getDatabase());
        $em->createQuery('DELETE FROM App\Entity\Presentation p')->execute();
        $em->createQuery('DELETE FROM App\Entity\Submission s')->execute();
        $em->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->admin = $this->createUser('host@example.com', 'Administrador', true);
        $this->member = $this->createUser('member@example.com', 'Ana <amiga>');
        $this->inactive = $this->createUser('inactive@example.com', 'Conta inativa', false, false);
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->files);
        parent::tearDown();
    }

    public function testLobbyShowsOnlyReadinessAndIncludesActiveAdministrators(): void
    {
        $this->contribute($this->member);
        $this->client->request('GET', self::ROOM);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.participant-list li');
        self::assertSelectorTextContains('.participant-heading', '1/2');
        self::assertSelectorTextContains('.participant-list', 'Ana <amiga>');
        self::assertSelectorTextNotContains('.participant-list', 'Conta inativa');
        self::assertSelectorExists('#presentation-start[disabled]');
        self::assertSelectorNotExists('.presentation-story');
        self::assertStringNotContainsString('História privada', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('/envios/', $this->client->getResponse()->getContent());
        self::assertSelectorExists('a[href="/sextas/'.self::FRIDAY.'"]');
        $this->client->request('GET', '/sextas/'.self::FRIDAY);
        self::assertSelectorTextContains('.presentation-entry', 'Preparar apresentação');
    }

    public function testAnonymousAndMembersCannotControlThePresentation(): void
    {
        $this->client->restart();
        foreach ([self::ROOM, self::ROOM.'/estado', '/admin/sextas/'.self::FRIDAY.'/iniciar'] as $path) {
            $this->client->request(str_starts_with($path, '/admin') ? 'POST' : 'GET', $path);
            self::assertResponseRedirects('https://localhost/login');
        }
        $this->client->loginUser($this->member);
        $this->client->request('GET', self::ROOM);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#presentation-start');
        foreach (['iniciar', 'avancar'] as $action) {
            $this->client->request('POST', '/admin/sextas/'.self::FRIDAY.'/'.$action);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertNull($this->presentation());
    }

    public function testMissingContributionsAreCheckedOnTheServerEvenIfButtonIsEnabledManually(): void
    {
        $this->contribute($this->admin);
        $form = $this->startForm();
        $this->client->submit($form);
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'Aguarde o envio de todos');
        self::assertNull($this->presentation());
    }

    public function testStartAndAdvanceRequireValidCsrfAndCorrectHttpMethods(): void
    {
        $this->ready();
        $this->client->request('POST', '/admin/sextas/'.self::FRIDAY.'/iniciar', ['form' => ['_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->presentation());
        $this->start();
        $this->client->request('POST', '/admin/sextas/'.self::FRIDAY.'/avancar', ['form' => ['position' => '0', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->presentation()->getPosition());
        foreach (['iniciar', 'avancar'] as $action) {
            $this->client->request('GET', '/admin/sextas/'.self::FRIDAY.'/'.$action);
            self::assertResponseStatusCodeSame(405);
        }
    }

    public function testPresentationCanTemporarilyStartBeforeTheChosenFriday(): void
    {
        $this->ready();
        self::mockTime('2026-10-09 02:59:59 UTC'); // Still Thursday in Brasília.
        $form = $this->startForm();
        self::assertSelectorExists('#presentation-start:not([disabled])');
        self::assertSelectorNotExists('#start-reason');
        $this->client->submit($form);
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.presentation-progress', 'História 1 de 2');
        self::assertNotNull($this->presentation());
    }

    public function testInvalidDatesAreRejected(): void
    {
        foreach (['2026-10-08', '2026-02-30', '1999-01-01'] as $date) {
            $this->client->request('GET', '/sextas/'.$date.'/apresentacao');
            self::assertResponseStatusCodeSame(404);
            $this->client->request('POST', '/admin/sextas/'.$date.'/iniciar');
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testStartingPersistsOneOrderAndShowsTheSameFirstStoryToEveryone(): void
    {
        $ids = $this->ready();
        $this->contribute($this->inactive);
        $start = $this->startForm();
        $this->client->submit($start);
        self::assertResponseRedirects(self::ROOM, 303);
        $presentation = $this->presentation();
        self::assertEqualsCanonicalizing($ids, $presentation->getSubmissionOrder());
        self::assertSame($this->admin->getId(), $presentation->getStartedBy()->getId());
        self::assertSame('2026-10-09 18:00:00', $presentation->getStartedAt()->format('Y-m-d H:i:s'));
        $order = $presentation->getSubmissionOrder();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.presentation-progress', 'História 1 de 2');
        self::assertSelectorTextContains('.presentation-story .story-text', "<script>alert('teste')</script>");
        self::assertSelectorNotExists('.presentation-story script');
        $image = $this->client->getCrawler()->filter('.presentation-photo img')->attr('src');
        $revision = $this->client->getCrawler()->filter('[data-presentation-state]')->attr('data-revision');
        $this->client->request('GET', self::ROOM);
        self::assertSelectorExists('.presentation-photo img[src="'.$image.'"]');
        $this->client->submit($start); // Duplicate start does not reshuffle or reset.
        self::assertResponseRedirects(self::ROOM, 303);
        self::assertSame($order, $this->presentation()->getSubmissionOrder());
        self::assertSame(1, static::getContainer()->get(PresentationRepository::class)->count([]));
        $this->client->loginUser($this->member);
        $this->client->request('GET', self::ROOM.'/estado');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->client->getResponse()->isCacheable());
        self::assertSelectorExists('[data-revision="'.$revision.'"]');
        self::assertSelectorExists('.presentation-photo img[src="'.$image.'"]');
        self::assertSelectorNotExists('#presentation-advance');
        self::assertStringNotContainsString('<!DOCTYPE', $this->client->getResponse()->getContent());
    }

    public function testParticipantsOnlySeeOtherPhotosOnceTheyAreRevealed(): void
    {
        $ids = $this->ready();
        $inactiveId = $this->contribute($this->inactive)->getId();
        $this->client->request('GET', '/envios/'.$ids[1].'/foto');
        self::assertResponseStatusCodeSame(404);
        $this->start();
        $order = $this->presentation()->getSubmissionOrder();
        $viewer = static::getContainer()->get(SubmissionRepository::class)->find($order[0])->getAuthor();
        $this->client->loginUser($viewer);
        $this->client->request('GET', '/envios/'.$order[1].'/foto');
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->admin);
        $this->advance();
        $this->client->loginUser($viewer);
        $this->client->request('GET', '/envios/'.$order[1].'/foto');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        $this->client->request('GET', '/envios/'.$inactiveId.'/foto');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStartFreezesSubmissionsIncludingFormsOpenedEarlier(): void
    {
        $this->ready();
        $this->client->loginUser($this->member);
        $crawler = $this->client->request('GET', '/sextas/'.self::FRIDAY);
        $oldForm = $crawler->selectButton('Salvar alterações')->form(['submission[text]' => 'Tentativa atrasada']);
        $this->client->loginUser($this->admin);
        $this->start();
        $this->client->loginUser($this->member);
        $this->client->submit($oldForm);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/sextas/'.self::FRIDAY);
        self::assertSelectorNotExists('textarea');
        self::assertSelectorTextContains('.submission-heading', 'A apresentação começou');
        self::assertSelectorExists('a[href="'.self::ROOM.'"]');
        $submission = static::getContainer()->get(SubmissionRepository::class)->findOneBy(['author' => $this->member->getId()]);
        self::assertStringStartsWith('História privada', $submission->getText());
        $submission->setText('Outra tentativa');
        try {
            static::getContainer()->get(SubmissionManager::class)->save($submission, null);
            self::fail('The service must reject edits after start as well.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('A apresentação já começou', $exception->getMessage());
        }
    }

    public function testAdminAdvancesWithoutSkippingAndCompletesThePresentation(): void
    {
        $this->ready();
        $this->start();
        $crawler = $this->client->request('GET', self::ROOM);
        $oldForm = $crawler->selectButton('Próxima história')->form();
        $order = $this->presentation()->getSubmissionOrder();
        $this->client->submit($oldForm);
        self::assertResponseRedirects(self::ROOM, 303);
        self::assertSame(1, $this->presentation()->getPosition());
        $this->client->submit($oldForm);
        self::assertResponseRedirects(self::ROOM, 303);
        self::assertSame(1, $this->presentation()->getPosition());
        self::assertSame($order, $this->presentation()->getSubmissionOrder());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.error', 'já avançou');
        self::assertSelectorTextContains('.presentation-progress', 'História 2 de 2');
        $this->client->submit($this->client->getCrawler()->selectButton('Concluir apresentação')->form());
        self::assertResponseRedirects(self::ROOM, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.presentation-finished', 'Todas as histórias foram apresentadas');
        self::assertSelectorExists('[data-finished="true"]');
        self::assertSelectorNotExists('#presentation-advance');
        self::assertTrue($this->presentation()->isFinished());
        $this->client->submit($oldForm);
        self::assertSame(2, $this->presentation()->getPosition());
        $this->client->loginUser($this->member);
        $this->client->request('GET', self::ROOM.'/estado');
        self::assertSelectorExists('[data-finished="true"]');
    }

    public function testCalendarLinksToTheStartedRoomAndDoesNotOfferEditing(): void
    {
        $this->ready();
        $this->start();
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.upcoming-panel', 'Apresentação em andamento');
        self::assertSelectorTextNotContains('.upcoming-panel', 'Editar meu envio');
        self::assertSelectorExists('.upcoming-panel a[href="'.self::ROOM.'"]');
        self::assertSelectorExists('[data-friday="'.self::FRIDAY.'"][href="'.self::ROOM.'"]');
        self::mockTime('2026-10-12 18:00:00 UTC');
        $this->client->request('GET', '/');
        self::assertSelectorExists('[data-friday="'.self::FRIDAY.'"][href="'.self::ROOM.'"]');
        self::assertSelectorTextContains('.upcoming-panel', 'Preparar meu envio');
    }

    private function createUser(string $email, string $name, bool $admin = false, bool $active = true): User
    {
        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setIsAdmin($admin);
        $user->setIsActive($active);
        static::getContainer()->get(UserManager::class)->save($user, 'Senha-do-teste-123');

        return $user;
    }

    private function contribute(User $user): Submission
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $submission = new Submission($em->find(User::class, $user->getId()), new \DateTimeImmutable(self::FRIDAY));
        $submission->setText('História privada de '.$user->getName()."\n<script>alert('teste')</script>");
        $filename = bin2hex(random_bytes(16)).'.png';
        $photos = static::getContainer()->get(SubmissionPhotoStorage::class);
        $path = $photos->path($filename);
        (new Filesystem())->mkdir(dirname($path));
        file_put_contents($path, base64_decode(self::PNG));
        $this->files[] = $path;
        $submission->setPhotoFilename($filename);
        $em->persist($submission);
        $em->flush();

        return $submission;
    }

    private function ready(): array
    {
        return [$this->contribute($this->admin)->getId(), $this->contribute($this->member)->getId()];
    }

    private function startForm(): Form
    {
        $crawler = $this->client->request('GET', self::ROOM);

        return $crawler->filter('#presentation-start')->form();
    }

    private function start(): void
    {
        $this->client->submit($this->startForm());
        self::assertResponseRedirects(self::ROOM, 303);
    }

    private function advance(): void
    {
        $crawler = $this->client->request('GET', self::ROOM);
        $this->client->submit($crawler->filter('#presentation-advance')->form());
        self::assertResponseRedirects(self::ROOM, 303);
    }

    private function presentation(): ?Presentation
    {
        return static::getContainer()->get(PresentationRepository::class)->findOneBy(['friday' => new \DateTimeImmutable(self::FRIDAY)]);
    }
}
