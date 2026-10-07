<?php

namespace App\Service;

use Symfony\Component\Clock\ClockInterface;

class FridayCalendar
{
    public const TIMEZONE = 'America/Sao_Paulo';
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;
    public const MONTHS = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __construct(private ClockInterface $clock)
    {
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
    }

    public function upcomingFriday(): \DateTimeImmutable
    {
        $today = $this->today();
        $days = (5 - (int) $today->format('N') + 7) % 7;

        return $today->modify('+'.$days.' days');
    }

    public function isFriday(\DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');

        return '5' === $date->format('N') && $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    public function canSubmit(\DateTimeImmutable $friday): bool
    {
        // Compare calendar dates, not midnight instants in different timezones.
        return $this->isFriday($friday) && $friday->format('Y-m-d') >= $this->today()->format('Y-m-d');
    }

    /** @return list<\DateTimeImmutable> */
    public function fridays(int $year): array
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new \InvalidArgumentException('Ano fora do intervalo permitido.');
        }

        $date = new \DateTimeImmutable($year.'-01-01', new \DateTimeZone(self::TIMEZONE));
        if ('5' !== $date->format('N')) {
            $date = $date->modify('next friday');
        }

        $fridays = [];
        while ((int) $date->format('Y') === $year) {
            $fridays[] = $date;
            $date = $date->modify('+1 week');
        }

        return $fridays;
    }
}
