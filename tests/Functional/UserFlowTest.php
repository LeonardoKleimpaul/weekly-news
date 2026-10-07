<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFlowTest extends WebTestCase
{
    private const PASSWORD = 'Senha-do-teste-123';
    private KernelBrowser $client;
    private User $admin;
    private User $member;
    private static int $clientNumber = 1;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '192.0.2.'.self::$clientNumber++]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertStringEndsWith('_test', $em->getConnection()->getDatabase(), 'Execute apenas no banco de testes.');
        $em->createQuery('DELETE FROM App\Entity\Presentation p')->execute();
        $em->createQuery('DELETE FROM App\Entity\User u')->execute();
        $this->admin = $this->createUser('admin@example.com', 'Administrador', true);
        $this->member = $this->createUser('member@example.com', 'Participante');
    }

    public function testAnonymousMustLogIn(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseRedirects('https://localhost/login');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    public function testLoginAcceptsNormalizedEmailAndLogoutEndsSession(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Entrar')->form(['email' => 'MEMBER@EXAMPLE.COM', 'password' => self::PASSWORD]));
        self::assertResponseRedirects('https://localhost/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Participante');
        self::assertSelectorNotExists('a[href="/admin/usuarios"]');
        $this->client->submit($this->client->getCrawler()->selectButton('Sair')->form());
        self::assertResponseRedirects('https://localhost/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Entrar')->form(['email' => 'member@example.com', 'password' => 'senha-errada']));
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testLoginWithoutCsrfIsRejected(): void
    {
        $this->client->request('POST', '/login', ['email' => 'member@example.com', 'password' => self::PASSWORD]);
        self::assertResponseRedirects('https://localhost/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testInactiveUserCannotLogIn(): void
    {
        $this->member->setIsActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Entrar')->form(['email' => 'member@example.com', 'password' => self::PASSWORD]));
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testMemberCannotManageUsers(): void
    {
        $this->client->loginUser($this->member);
        foreach (['/admin/usuarios', '/admin/usuarios/novo', '/admin/usuarios/'.$this->admin->getId().'/editar'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/admin/usuarios/novo', ['user' => ['email' => 'intruder@example.com']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesUserWithHashedPassword(): void
    {
        $this->client->loginUser($this->admin);
        $this->submitNewUser('NEW@EXAMPLE.COM');
        self::assertResponseRedirects('/admin/usuarios', 303);
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'new@example.com']);
        self::assertNotNull($user);
        self::assertSame('Novo participante', $user->getName());
        self::assertFalse($user->isAdmin());
        self::assertTrue($user->isActive());
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
    }

    public function testDuplicateEmailIsRejectedRegardlessOfCase(): void
    {
        $this->client->loginUser($this->admin);
        $this->submitNewUser('MEMBER@EXAMPLE.COM');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Este e-mail já está em uso.');
        self::assertSame(2, static::getContainer()->get(UserRepository::class)->count([]));
    }

    public function testWeakAndMismatchedPasswordsAreRejected(): void
    {
        $this->client->loginUser($this->admin);
        $this->submitNewUser('new@example.com', 'curta');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Use pelo menos 12 caracteres.');
        $crawler = $this->client->request('GET', '/admin/usuarios/novo');
        $this->client->submit($crawler->selectButton('Criar usuário')->form([
            'user[name]' => 'Novo', 'user[email]' => 'new@example.com',
            'user[plainPassword][first]' => self::PASSWORD, 'user[plainPassword][second]' => 'Outra-senha-12345',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'As senhas precisam ser iguais.');
        self::assertSame(2, static::getContainer()->get(UserRepository::class)->count([]));
    }

    public function testUserCreationRequiresCsrf(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/usuarios/novo', ['user' => [
            'name' => 'Novo', 'email' => 'new@example.com', 'isActive' => 1,
            'plainPassword' => ['first' => self::PASSWORD, 'second' => self::PASSWORD],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(2, static::getContainer()->get(UserRepository::class)->count([]));
    }

    public function testAdminCanEditAndDeactivateMemberWithoutReplacingPassword(): void
    {
        $id = $this->member->getId();
        $password = $this->member->getPassword();
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/usuarios/'.$id.'/editar');
        $this->client->submit($crawler->selectButton('Salvar alterações')->form(['user[name]' => 'Nome atualizado', 'user[isActive]' => false]));
        self::assertResponseRedirects('/admin/usuarios', 303);
        $user = static::getContainer()->get(UserRepository::class)->find($id);
        self::assertSame('Nome atualizado', $user->getName());
        self::assertFalse($user->isActive());
        self::assertSame($password, $user->getPassword());
    }

    public function testAdminCanPromoteMemberAndResetPassword(): void
    {
        $id = $this->member->getId();
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/usuarios/'.$id.'/editar');
        $this->client->submit($crawler->selectButton('Salvar alterações')->form([
            'user[isAdmin]' => true,
            'user[plainPassword][first]' => 'Senha-redefinida-123',
            'user[plainPassword][second]' => 'Senha-redefinida-123',
        ]));
        self::assertResponseRedirects('/admin/usuarios', 303);
        $user = static::getContainer()->get(UserRepository::class)->find($id);
        self::assertTrue($user->isAdmin());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'Senha-redefinida-123'));
    }

    public function testDemotionInvalidatesAdminSession(): void
    {
        $this->client->loginUser($this->admin);
        $this->admin->setIsAdmin(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request('GET', '/admin/usuarios');
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testAdminCannotDeactivateOrDemoteOwnAccount(): void
    {
        $id = $this->admin->getId();
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/usuarios/'.$id.'/editar');
        self::assertSelectorExists('input[name="user[isAdmin]"][disabled]');
        self::assertSelectorExists('input[name="user[isActive]"][disabled]');
        $this->client->request('POST', '/admin/usuarios/'.$id.'/editar', ['user' => [
            'name' => 'Administrador', 'email' => 'admin@example.com', 'isAdmin' => false, 'isActive' => false,
            'plainPassword' => ['first' => '', 'second' => ''],
            '_token' => $crawler->filter('input[name="user[_token]"]')->attr('value'),
        ]]);
        self::assertResponseRedirects('/admin/usuarios', 303);
        $admin = static::getContainer()->get(UserRepository::class)->find($id);
        self::assertTrue($admin->isActive());
        self::assertTrue($admin->isAdmin());
    }

    public function testDeactivationInvalidatesExistingSession(): void
    {
        $this->client->loginUser($this->member);
        $this->member->setIsActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request('GET', '/');
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testPasswordChangeRequiresCurrentPasswordAndLogsOut(): void
    {
        $id = $this->member->getId();
        $this->client->loginUser($this->member);
        $crawler = $this->client->request('GET', '/conta');
        $this->client->submit($crawler->selectButton('Alterar senha')->form([
            'change_password[currentPassword]' => 'senha-errada',
            'change_password[newPassword][first]' => 'Senha-nova-123456',
            'change_password[newPassword][second]' => 'Senha-nova-123456',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'A senha atual está incorreta.');
        $crawler = $this->client->request('GET', '/conta');
        $this->client->submit($crawler->selectButton('Alterar senha')->form([
            'change_password[currentPassword]' => self::PASSWORD,
            'change_password[newPassword][first]' => 'Senha-nova-123456',
            'change_password[newPassword][second]' => 'Senha-nova-123456',
        ]));
        self::assertResponseRedirects('/login?password_changed=1', 303);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('https://localhost/login');
        $user = static::getContainer()->get(UserRepository::class)->find($id);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'Senha-nova-123456'));
        self::assertFalse($hasher->isPasswordValid($user, self::PASSWORD));
    }

    public function testLogoutWithoutCsrfDoesNotEndSession(): void
    {
        $this->client->loginUser($this->member);
        $this->client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testBootstrapCommandCreatesFirstAdminAndRefusesSecond(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM App\Entity\User u')->execute();
        $command = (new Application(static::$kernel))->find('app:create-admin');
        $tester = new CommandTester($command);
        $tester->setInputs([self::PASSWORD]);
        self::assertSame(0, $tester->execute(['email' => 'FIRST@EXAMPLE.COM', 'name' => 'Primeiro administrador']));
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'first@example.com']);
        self::assertNotNull($user);
        self::assertTrue($user->isAdmin());
        self::assertSame(1, $tester->execute(['email' => 'second@example.com', 'name' => 'Outro administrador']));
    }

    private function createUser(string $email, string $name, bool $isAdmin = false): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setIsAdmin($isAdmin);
        static::getContainer()->get(UserManager::class)->save($user, self::PASSWORD);

        return $user;
    }

    private function submitNewUser(string $email, string $password = self::PASSWORD): void
    {
        $crawler = $this->client->request('GET', '/admin/usuarios/novo');
        $this->client->submit($crawler->selectButton('Criar usuário')->form([
            'user[name]' => 'Novo participante', 'user[email]' => $email,
            'user[plainPassword][first]' => $password, 'user[plainPassword][second]' => $password,
        ]));
    }
}
