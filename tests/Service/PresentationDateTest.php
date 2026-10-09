<?php

namespace App\Tests\Service;

use App\Repository\PresentationRepository;
use App\Repository\SubmissionRepository;
use App\Repository\UserRepository;
use App\Service\FridayCalendar;
use App\Service\PresentationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class PresentationDateTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testProductionRequiresTheChosenFridayButRehearsalsStillWorkInDevelopment(): void
    {
        self::mockTime('2026-10-07 12:00:00');
        self::bootKernel();
        $container = static::getContainer();
        $production = new PresentationManager(
            $container->get(EntityManagerInterface::class),
            $container->get(PresentationRepository::class),
            $container->get(SubmissionRepository::class),
            $container->get(UserRepository::class),
            $container->get(FridayCalendar::class),
            $container->get(ClockInterface::class),
            $container->get(LockFactory::class),
            'prod',
        );
        self::assertFalse($production->canStartOn(new \DateTimeImmutable('2026-10-09')));
        self::assertTrue($production->canStartOn(new \DateTimeImmutable('2026-10-02')));
        self::assertFalse($production->canStartOn(new \DateTimeImmutable('2026-10-08')));
        self::assertTrue($container->get(PresentationManager::class)->canStartOn(new \DateTimeImmutable('2026-10-09')));

        self::mockTime('2026-10-09 03:00:00');
        self::assertTrue($production->canStartOn(new \DateTimeImmutable('2026-10-09')));
    }
}
