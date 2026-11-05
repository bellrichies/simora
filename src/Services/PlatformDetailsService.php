<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PlatformDataRepository;

final class PlatformDetailsService
{
    private array $settings;

    public function __construct(private PlatformDataRepository $repository, ?array $settings = null)
    {
        $this->settings = $settings ?? require dirname(__DIR__, 2) . '/config/app.php';
    }

    public function getDetails(string $platform, array $query): array
    {
        $data = $this->repository->find($platform);
        $leagues = $data['leagues'] ?? [];
        $leagueIds = array_column($leagues, 'id');
        $requestedLeague = $query['league'] ?? null;
        if ($requestedLeague !== null && (!is_string($requestedLeague) || !in_array($requestedLeague, $leagueIds, true))) {
            throw new \InvalidArgumentException('League not found.');
        }
        $leagueId = $requestedLeague ?? ($leagueIds[0] ?? null);
        $league = null;
        foreach ($leagues as $candidate) {
            if ($candidate['id'] === $leagueId) {
                $league = $candidate;
                break;
            }
        }
        if ($leagueId !== null) {
            $data['results'] = array_values(array_filter($data['results'], static fn (array $row): bool => ($row['league_id'] ?? null) === $leagueId));
            $data['upcoming'] = array_values(array_filter($data['upcoming'] ?? [], static fn (array $row): bool => ($row['league_id'] ?? null) === $leagueId));
            foreach ($data['picks'] ?? [] as $label => $rows) {
                $data['picks'][$label] = array_values(array_filter($rows, static fn (array $row): bool => ($row['league_id'] ?? null) === $leagueId));
            }
        }
        $markets = array_column($this->settings['markets'], 'label');
        $marketMap = array_column($this->settings['markets'], 'label', 'id');
        $market = $this->choice($query['market'] ?? null, $markets, $marketMap[$this->settings['default_market']]);
        $marketResults = array_values(array_filter($data['results'], static fn (array $row): bool => $row['pick'] === $market));
        $weeks = array_values(array_unique(array_column($marketResults, 'week')));
        // Keep existing bookmarked round filters working during the terminology change.
        $weekInput = $query['week'] ?? $query['round'] ?? null;
        if (is_string($weekInput) && preg_match('/^R(\d+)$/D', $weekInput, $legacyWeek)) {
            $weekInput = 'W' . $legacyWeek[1];
        }
        $weekFilter = $this->choice($weekInput, $weeks, 'all');
        $status = $this->choice($query['status'] ?? null, ['Won', 'Lost'], 'all');
        $limits = $this->settings['stake'];
        $stakeValue = $query['stake'] ?? (string) ($limits['default_minor'] / 100);
        $validStake = is_string($stakeValue) && preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $stakeValue)
            && (float) $stakeValue * 100 >= $limits['min_minor'] && (float) $stakeValue * 100 <= $limits['max_minor'];
        $stakeMinor = $validStake ? (int) round((float) $stakeValue * 100) : $limits['default_minor'];
        $history = [];
        foreach ($marketResults as $row) {
            $row['status'] = $this->isWinningSelection($market, $row['home_score'], $row['away_score']) ? 'Won' : 'Lost';
            $localKickoff = (new \DateTimeImmutable($row['kickoff_at']))
                ->setTimezone(new \DateTimeZone($this->settings['timezone']));
            $row['kickoff_label'] = $localKickoff->format('D j M · H:i');
            $row['kickoff_date'] = $localKickoff->format('Y-m-d');
            $row['kickoff_date_label'] = $localKickoff->format('D, j M Y');
            $row['kickoff_time_label'] = $localKickoff->format('H:i');
            $history[] = $row;
        }
        $won = count(array_filter($history, static fn (array $row): bool => $row['status'] === 'Won'));
        $simulation = $this->simulate($history, $stakeMinor);
        $filtered = array_values(array_filter($history, static fn (array $row): bool =>
            ($weekFilter === 'all' || $row['week'] === $weekFilter)
            && ($status === 'all' || $row['status'] === $status)));
        $pageSize = $this->settings['history_page_size'];
        $pages = max(1, (int) ceil(count($filtered) / $pageSize));
        $requestedPage = $query['page'] ?? '1';
        $page = is_string($requestedPage) && preg_match('/^[1-9]\d{0,5}$/D', $requestedPage)
            ? min((int) $requestedPage, $pages) : 1;
        $show = $query['show'] ?? null;
        if (is_string($show) && preg_match('/^[1-9]\d{0,5}$/D', $show)) {
            $visible = min(count($filtered), (int) ceil((int) $show / $pageSize) * $pageSize);
            $visibleHistory = array_slice($filtered, 0, $visible);
        } else {
            $visible = min(count($filtered), $page * $pageSize);
            $visibleHistory = array_slice($filtered, ($page - 1) * $pageSize, $pageSize);
        }
        $quota = $data['quota'] ?? ['used' => 0, 'limit' => 0];
        $upgraded = ($query['access'] ?? '') === 'pro';
        $picks = $data['picks'][$market] ?? [];
        $pickInput = $query['pick'] ?? '1';
        $pickIndex = is_string($pickInput) && preg_match('/^[1-9]\d{0,5}$/D', $pickInput)
            ? min((int) $pickInput, max(1, count($picks))) - 1 : 0;
        return ['league' => $league, 'markets' => $markets, 'market' => $market, 'week_filter' => $weekFilter,
            'settings' => $this->settings, 'source' => $data['source'] ?? null, 'page_size' => $pageSize,
            'weeks' => $weeks, 'status_filter' => $status, 'week' => $data['upcoming'][0] ?? null,
            'history' => $visibleHistory, 'total' => count($filtered), 'shown' => $visible,
            'next_page' => $visible < count($filtered) ? intdiv($visible, $pageSize) + 1 : null,
            'page' => $page, 'pages' => $pages, 'won' => $won, 'lost' => count($history) - $won,
            'hit_rate' => $history ? (int) round(100 * $won / count($history)) : 0,
            'quota' => $quota, 'upgraded' => $upgraded,
            'locked' => !$upgraded && $quota['used'] >= $quota['limit'],
            'picks' => $picks, 'pick_index' => $pickIndex, 'pick' => $picks[$pickIndex] ?? null,
            'stake_minor' => $stakeMinor, 'stake_error' => !$validStake, 'simulation' => $simulation,
            'simulation_rows' => array_map(static fn (array $row): array => [
                'won' => $row['status'] === 'Won', 'odds' => $row['odds_hundredths'],
            ], array_reverse($history))];
    }

    public function isWinningSelection(string $market, int $home, int $away): bool
    {
        $marketId = array_search($market, array_column($this->settings['markets'], 'label', 'id'), true);
        return $marketId !== false && \App\Domain\MarketSettlement::won($marketId, $home, $away);
    }

    public function simulate(array $history, int $stakeMinor): array
    {
        $net = 0;
        $points = [0];
        foreach (array_reverse($history) as $row) {
            $net += $row['status'] === 'Won'
                ? (int) round($stakeMinor * ($row['odds_hundredths'] - 100) / 100) : -$stakeMinor;
            $points[] = $net;
        }
        $staked = count($history) * $stakeMinor;
        return ['net_minor' => $net, 'staked_minor' => $staked, 'points' => $points,
            'roi' => $staked > 0 ? round(100 * $net / $staked, 1) : 0];
    }

    private function choice(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
