<?php

namespace App\Tests\Service;

use App\Service\FridayCalendar;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class FridayCalendarTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testNextFridayAndDeadlineFollowBrasiliaAtTheYearBoundary(): void
    {
        self::mockTime('2027-01-02 02:59:00 UTC');
        $calendar = static::getContainer()->get(FridayCalendar::class);
        self::assertSame('2027-01-01', $calendar->today()->format('Y-m-d'));
        self::assertSame('2027-01-01', $calendar->upcomingFriday()->format('Y-m-d'));
        self::assertTrue($calendar->canSubmit(new \DateTimeImmutable('2027-01-01')));
        self::assertFalse($calendar->canSubmit(new \DateTimeImmutable('2026-12-25')));
        self::assertFalse($calendar->canSubmit(new \DateTimeImmutable('2027-01-02')));
    }

    public function testLastWeekOfDecemberPointsToFridayInTheNextYear(): void
    {
        self::mockTime('2026-12-31 15:00:00 UTC');
        $calendar = static::getContainer()->get(FridayCalendar::class);
        self::assertSame('2027-01-01', $calendar->upcomingFriday()->format('Y-m-d'));
    }
}
