<?php

namespace App\Service;

use App\Repository\VoteRepository;

class RankingService
{
    public function __construct(private VoteRepository $votes)
    {
    }

    /** @return list<array{user_id: int, name: string, points: int, rank: int}> */
    public function standings(): array
    {
        return $this->rank($this->votes->rankingPoints());
    }

    /**
     * @param list<array{user_id: int, name: string, points: int}> $rows
     * @return list<array{user_id: int, name: string, points: int, rank: int}>
     */
    public function rank(array $rows): array
    {
        usort($rows, static fn (array $a, array $b) => ($b['points'] <=> $a['points']) ?: strcasecmp($a['name'], $b['name']) ?: ($a['user_id'] <=> $b['user_id']));
        $previousPoints = null;
        $rank = 0;
        foreach ($rows as $index => &$row) {
            if ($row['points'] !== $previousPoints) {
                $rank = $index + 1;
            }
            $row['rank'] = $rank;
            $previousPoints = $row['points'];
        }
        unset($row);

        return $rows;
    }
}
