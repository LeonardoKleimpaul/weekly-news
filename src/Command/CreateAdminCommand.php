<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:create-admin', description: 'Cria o primeiro administrador do Weekly News.')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private UserRepository $users,
        private UserManager $manager,
        private ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail do administrador')
            ->addArgument('name', InputArgument::REQUIRED, 'Nome do administrador');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        foreach ($this->users->findAll() as $existingUser) {
            if ($existingUser->isAdmin() && $existingUser->isActive()) {
                $io->error('Já existe um administrador ativo. Gerencie as outras contas pelo site.');

                return Command::FAILURE;
            }
        }

        $user = new User();
        $user->setName($input->getArgument('name'));
        $user->setEmail($input->getArgument('email'));
        $user->setIsAdmin(true);
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error($error->getMessage());
            }

            return Command::INVALID;
        }

        if (!$input->isInteractive()) {
            $io->error('Execute em um terminal interativo para informar a senha de forma oculta.');

            return Command::INVALID;
        }

        $password = $io->askHidden('Senha (mínimo de 12 caracteres)', function (?string $password): string {
            $errors = $this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 12, max: 128)]);
            if (count($errors) > 0) {
                throw new \InvalidArgumentException('Use uma senha de 12 a 128 caracteres.');
            }

            return $password;
        });

        $this->manager->save($user, $password);
        $io->success('Administrador criado. Entre no site para cadastrar os participantes.');

        return Command::SUCCESS;
    }
}
