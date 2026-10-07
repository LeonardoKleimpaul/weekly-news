<?php

namespace App\Tests\Functional;

use App\Entity\Submission;
use App\Entity\User;
use App\Repository\SubmissionRepository;
use App\Service\SubmissionPhotoStorage;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

class SubmissionFlowTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j7S8AAAAASUVORK5CYII=';
    private KernelBrowser $client;
    private User $member;
    private User $other;
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::mockTime('2026-10-06 15:00:00 UTC');
        $this->client = static::createClient([], ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertStringEndsWith('_test', $em->getConnection()->getDatabase());
        $em->createQuery('DELETE FROM App\Entity\Presentation p')->execute();
        $em->createQuery('DELETE FROM App\Entity\Submission s')->execute();
        $em->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->member = $this->createUser('author@example.com', 'Ana');
        $this->other = $this->createUser('other@example.com', 'Outro administrador', true);
        $this->client->loginUser($this->member);
    }

    protected function tearDown(): void
    {
        if (null !== static::$kernel) {
            $photos = static::getContainer()->get(SubmissionPhotoStorage::class);
            foreach (glob($photos->path('*')) ?: [] as $path) {
                $this->temporaryFiles[] = $path;
            }
        }
        (new Filesystem())->remove($this->temporaryFiles);
        parent::tearDown();
    }

    public function testCalendarListsEveryFridayAndSupportsYearsWith53Fridays(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Olá, Ana.');
        self::assertSelectorCount(12, '.month-panel');
        self::assertSelectorCount(9, 'details.month-panel:not([open])');
        self::assertSelectorCount(3, 'details.month-panel[open]');
        self::assertSelectorExists('details.month-panel[open] #month-10');
        self::assertSelectorCount(52, '[data-friday]');
        self::assertSelectorTextContains('.friday-upcoming', '09');
        self::assertSelectorTextContains('.upcoming-panel .submission-status-pending', 'Você ainda não enviou');
        self::assertSelectorExists('[data-friday="2026-10-09"] .friday-status-pending .submission-dot');
        self::assertSelectorExists('[data-friday="2026-10-16"] .friday-status .submission-dot');
        self::assertSelectorNotExists('[data-friday="2026-10-16"] .friday-status-pending');
        self::assertSelectorNotExists('[data-friday="2026-10-02"] .friday-status-pending');
        $dates = $crawler->filter('[data-friday]')->each(fn (Crawler $card) => $card->attr('data-friday'));
        self::assertSame('2026-01-02', $dates[0]);
        self::assertSame('2026-12-25', $dates[51]);
        foreach ($dates as $date) {
            self::assertSame('5', (new \DateTimeImmutable($date))->format('N'));
        }
        $this->client->request('GET', '/ano/2027');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(53, '[data-friday]');
        self::assertSelectorCount(12, 'details.month-panel[open]');
        self::assertSelectorExists('[data-friday="2027-01-01"]');
        self::assertSelectorExists('[data-friday="2027-12-31"]');
        $this->client->request('GET', '/ano/2025');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(12, 'details.month-panel:not([open])');
    }

    public function testInvalidDatesAndYearsAreRejected(): void
    {
        foreach (['/ano/1999', '/ano/2101', '/sextas/2026-10-08', '/sextas/2026-02-30', '/sextas/2026-13-09', '/sextas/not-a-date'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testSubmissionAndPhotoRequireLogin(): void
    {
        $this->client->restart();
        foreach (['/sextas/2026-10-09', '/envios/999/foto'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('https://localhost/login');
        }
    }

    public function testMemberSendsPhotoAndTextAndSeesSavedStatus(): void
    {
        $text = "Minha história\n<script>alert('xss')</script>";
        $this->submit($text, $this->image());
        self::assertResponseRedirects('/sextas/2026-10-09', 303);
        $submission = $this->saved();
        self::assertSame($this->member->getId(), $submission->getAuthor()->getId());
        self::assertSame('2026-10-09', $submission->getFriday()->format('Y-m-d'));
        self::assertSame($text, $submission->getText());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $submission->getPhotoFilename());
        $path = static::getContainer()->get(SubmissionPhotoStorage::class)->path($submission->getPhotoFilename());
        self::assertFileExists($path);
        self::assertSame(base64_decode(self::PNG), file_get_contents($path));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Sua contribuição está salva');
        self::assertSelectorTextContains('[data-preview-text]', '<script>');
        self::assertSelectorNotExists('[data-preview-text] script');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('[data-friday="2026-10-09"]', 'Envio salvo');
        self::assertSelectorTextContains('.upcoming-panel .submission-status-saved', 'Envio salvo');
        self::assertSelectorNotExists('[data-friday="2026-10-09"] .friday-status-pending');
        $this->client->request('GET', '/envios/'.$submission->getId().'/foto');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/png');
        self::assertResponseHeaderSame('x-content-type-options', 'nosniff');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('cache-control'));
    }

    public function testEditingKeepsOneSubmissionAndCanKeepOrReplaceThePhoto(): void
    {
        $this->submit('Primeira versão', $this->image());
        $first = $this->saved();
        $id = $first->getId();
        $filename = $first->getPhotoFilename();
        $photos = static::getContainer()->get(SubmissionPhotoStorage::class);
        $this->submit('Texto atualizado');
        self::assertResponseRedirects('/sextas/2026-10-09', 303);
        $updated = $this->saved();
        self::assertSame($id, $updated->getId());
        self::assertSame('Texto atualizado', $updated->getText());
        self::assertSame($filename, $updated->getPhotoFilename());
        self::assertFileExists($photos->path($filename));
        $this->submit('Com outra foto', $this->image());
        self::assertResponseRedirects('/sextas/2026-10-09', 303);
        self::assertSame($id, $this->saved()->getId());
        self::assertNotSame($filename, $this->saved()->getPhotoFilename());
        self::assertFileDoesNotExist($photos->path($filename));
        self::assertSame(1, static::getContainer()->get(SubmissionRepository::class)->count([]));
    }

    public function testTextAndPhotoAreRequiredAndInvalidUploadsAreRejected(): void
    {
        $this->submit('');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Conte a sua história.');
        self::assertSelectorTextContains('main form', 'Escolha uma foto');

        $invalid = $this->temporaryFile('png', 'isto não é uma imagem');
        $this->submit('Uma história', $invalid);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Escolha uma foto JPG, PNG ou WebP');

        $wrongExtension = $this->temporaryFile('jpg', base64_decode(self::PNG));
        $this->submit('Uma história', $wrongExtension);
        self::assertResponseStatusCodeSame(422);

        $tooLarge = $this->temporaryFile('png', base64_decode(self::PNG).str_repeat("\0", 5 * 1024 * 1024));
        $this->submit('Uma história', $tooLarge);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'A foto deve ter no máximo 5 MB.');
        self::assertSame(0, static::getContainer()->get(SubmissionRepository::class)->count([]));
    }

    public function testTextLengthAndCsrfAreValidated(): void
    {
        $this->submit(str_repeat('a', 10001), $this->image());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Use no máximo 10000 caracteres.');
        $this->client->request('POST', '/sextas/2026-10-09', ['submission' => ['text' => 'Tentativa sem CSRF']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, static::getContainer()->get(SubmissionRepository::class)->count([]));
    }

    public function testOtherMembersIncludingAdminsCannotReadSomeoneElsesContribution(): void
    {
        $this->submit('História privada da Ana', $this->image());
        $id = $this->saved()->getId();
        $this->client->loginUser($this->other);
        $this->client->request('GET', '/sextas/2026-10-09');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('[data-preview-text]', 'História privada da Ana');
        $this->client->request('GET', '/envios/'.$id.'/foto');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/');
        self::assertSelectorTextNotContains('[data-friday="2026-10-09"]', 'Envio salvo');
        $this->submit('História do outro participante', $this->image());
        self::assertSame(2, static::getContainer()->get(SubmissionRepository::class)->count([]));
        $this->client->loginUser($this->member);
        $this->client->request('GET', '/sextas/2026-10-09');
        self::assertSelectorTextContains('[data-preview-text]', 'História privada da Ana');
    }

    public function testPastFridaysAreReadOnlyAndForgedPostCannotSave(): void
    {
        $this->client->request('GET', '/sextas/2026-10-02');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Esta sexta ficou sem o seu envio.');
        self::assertSelectorNotExists('main form');
        $this->client->request('POST', '/sextas/2026-10-02', ['submission' => ['text' => 'Envio atrasado']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, static::getContainer()->get(SubmissionRepository::class)->count([]));
    }

    public function testFridayDeadlineUsesBrasiliaTimeAndArchivePreservesTheSubmission(): void
    {
        self::mockTime('2026-10-10 02:59:00 UTC'); // Friday 23:59 in Brasília.
        $this->submit('A história de sexta', $this->image());
        self::assertResponseRedirects('/sextas/2026-10-09', 303);
        $id = $this->saved()->getId();
        self::mockTime('2026-10-10 03:00:00 UTC');
        $this->client->request('GET', '/sextas/2026-10-09');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.archived-submission', 'A história de sexta');
        self::assertSelectorNotExists('main form');
        $this->client->request('POST', '/sextas/2026-10-09', ['submission' => ['text' => 'Tentar alterar']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('A história de sexta', $this->saved()->getText());
        $this->client->request('GET', '/envios/'.$id.'/foto');
        self::assertResponseIsSuccessful();
    }

    public function testYearEndHighlightsSavedUpcomingContributionInTheNextYear(): void
    {
        self::mockTime('2026-12-31 15:00:00 UTC');
        $this->submit('A primeira história do ano', $this->image(), '2027-01-01');
        self::assertResponseRedirects('/sextas/2027-01-01', 303);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.upcoming-panel', '01/01/2027');
        self::assertSelectorTextContains('.upcoming-panel .button', 'Editar meu envio');
        $this->client->request('GET', '/ano/2027');
        self::assertSelectorTextContains('[data-friday="2027-01-01"]', 'Envio salvo');
    }

    private function submit(string $text, ?string $photo = null, string $friday = '2026-10-09'): void
    {
        $crawler = $this->client->request('GET', '/sextas/'.$friday);
        $button = $crawler->filter('main button[type="submit"]')->first();
        $form = $button->form(['submission[text]' => $text]);
        if (null !== $photo) {
            $form['submission[photo]']->upload($photo);
        }
        $this->client->submit($form);
    }

    private function saved(): Submission
    {
        $submission = static::getContainer()->get(SubmissionRepository::class)->findOneBy(['author' => $this->member->getId()]);
        self::assertNotNull($submission);

        return $submission;
    }

    private function createUser(string $email, string $name, bool $admin = false): User
    {
        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setIsAdmin($admin);
        static::getContainer()->get(UserManager::class)->save($user, 'Senha-do-teste-123');

        return $user;
    }

    private function image(): string
    {
        return $this->temporaryFile('png', base64_decode(self::PNG));
    }

    private function temporaryFile(string $extension, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'weekly-news-test-');
        $this->temporaryFiles[] = $path;
        $path .= '.'.$extension;
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
