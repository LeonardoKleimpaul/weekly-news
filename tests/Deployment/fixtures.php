<?php

use App\Entity\Submission;
use App\Entity\User;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require '/app/vendor/autoload.php';
(new Dotenv())->bootEnv('/app/.env');
$kernel = new Kernel('prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
if (!str_ends_with($em->getConnection()->getDatabase(), '_deployment_test')) {
    throw new RuntimeException('Fixtures require an isolated deployment test database.');
}
if ([] !== $em->getRepository(User::class)->findAll()) {
    throw new RuntimeException('Fixtures require an empty database.');
}

$today = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
$past = $today->modify('last friday');
$future = $today->modify('next friday');
$password = 'Production-smoke-123';
$photo = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j7S8AAAAASUVORK5CYII=');
$users = [];
foreach (range(1, 4) as $number) {
    $user = new User();
    $user->setName('Participante '.$number);
    $user->setEmail('member'.$number.'@deployment.invalid');
    $user->setIsAdmin(1 === $number);
    $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
    $em->persist($user);
    $em->flush();

    $submission = new Submission($user, $past);
    $filename = bin2hex(random_bytes(16)).'.png';
    file_put_contents('/app/var/uploads/prod/'.$filename, $photo);
    $submission->setPhotoFilename($filename);
    $submission->setText('História de produção do participante '.$number);
    $em->persist($submission);
    $users[] = ['id' => $user->getId(), 'email' => $user->getEmail()];
}
$em->flush();
echo json_encode(['past' => $past->format('Y-m-d'), 'future' => $future->format('Y-m-d'), 'users' => $users], JSON_THROW_ON_ERROR);
