<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Clock;
use App\Contracts\PlatformDataRepository;

final class PredictionsHubService
{
    public function __construct(private PlatformDataRepository $repository, private Clock $clock, private array $catalog, private array $settings)
    {
    }

    public function getPage(array $query): array
    {
        $now = $this->clock->now();
        $window = in_array($query['window'] ?? null, ['7', '30', '90'], true) ? $query['window'] : '30';
        $marketIds = array_column($this->settings['markets'], 'label', 'id');
        $market = is_string($query['market'] ?? null) && isset($marketIds[$query['market']]) ? $query['market'] : 'all';
        $filter = is_string($query['platform'] ?? null) && isset($this->catalog[$query['platform']]) ? $query['platform'] : 'all';
        $from = $now->modify('-' . $window . ' days')->getTimestamp();
        $cards = $history = $params = [];
        $focus = is_string($query['focus'] ?? null) && isset($this->catalog[$query['focus']]) ? $query['focus'] : array_key_first($this->catalog);
        $preview = null;
        foreach ($this->catalog as $slug => $config) {
            $data = $this->repository->find($slug);
            $leagues = $data['leagues'];
            $ids = array_column($leagues, 'name', 'id');
            $selection = $query['league_' . $slug] ?? null;
            $leagueId = is_string($selection) && isset($ids[$selection]) ? $selection : array_key_first($ids);
            $params['league_' . $slug] = $leagueId;
            $rows = array_values(array_filter($data['results'], static function (array $row) use ($leagueId, $market, $from, $now): bool {
                $time = strtotime($row['kickoff_at']);
                return $row['league_id'] === $leagueId && ($market === 'all' || $row['market_id'] === $market)
                    && $time >= $from && $time <= $now->getTimestamp() && in_array($row['status'], ['Won', 'Lost'], true);
            }));
            usort($rows, static fn (array $a, array $b): int => strcmp($b['kickoff_at'], $a['kickoff_at']) ?: strcmp($a['id'], $b['id']));
            $wins = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'Won'));
            $week = null;
            foreach ($data['upcoming'] ?? [] as $candidate) {
                if ($candidate['league_id'] === $leagueId) { $week = $candidate; break; }
            }
            if ($slug === $focus) {
                $previewMarket = $market === 'all' ? ($this->settings['default_market'] ?? array_key_first($marketIds)) : $market;
                $label = $marketIds[$previewMarket] ?? '';
                $picks = array_values(array_filter($data['picks'][$label] ?? [], static fn (array $pick): bool => $pick['league_id'] === $leagueId));
                $previewRows = array_values(array_filter($data['results'], static fn (array $row): bool => $row['league_id'] === $leagueId && $row['market_id'] === $previewMarket && in_array($row['status'], ['Won', 'Lost'], true) && strtotime($row['kickoff_at']) <= $now->getTimestamp()));
                usort($previewRows, static fn (array $a, array $b): int => strcmp($b['kickoff_at'], $a['kickoff_at']) ?: strcmp($a['id'], $b['id']));
                $preview = ['slug' => $slug, 'name' => $config['name'], 'league' => $ids[$leagueId] ?? 'Unavailable', 'league_id' => $leagueId, 'leagues' => $leagues, 'week' => $week, 'pick' => $picks[0] ?? null, 'market' => $previewMarket, 'rows' => array_slice($previewRows, 0, 100)];
            }
            $cards[] = ['slug' => $slug, 'name' => $config['name'], 'class' => $config['style'], 'leagues' => $leagues,
                'league_id' => $leagueId, 'league' => $ids[$leagueId] ?? 'Unavailable', 'week' => $week,
                'total' => count($rows), 'wins' => $wins, 'losses' => count($rows) - $wins,
                'rate' => $rows ? round(100 * $wins / count($rows), 1) : null,
                'form' => array_column(array_slice($rows, 0, 5), 'status'), 'sparkline' => $this->sparkline($rows)];
            if ($filter === 'all' || $filter === $slug) {
                foreach ($rows as $row) {
                    $history[] = $row + ['platform_id' => $slug, 'platform_name' => $config['name']];
                }
            }
        }
        usort($history, static fn (array $a, array $b): int => strcmp($b['kickoff_at'], $a['kickoff_at']) ?: strcmp($a['id'], $b['id']));
        $total = count($history);
        $pages = max(1, (int) ceil($total / 6));
        $page = is_string($query['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $query['page']) ? min((int) $query['page'], $pages) : 1;
        return ['preview' => $preview, 'focus' => $focus, 'cards' => $cards, 'history' => array_slice($history, ($page - 1) * 6, 6), 'total' => $total, 'page' => $page, 'pages' => $pages,
            'window' => $window, 'market' => $market, 'market_label' => $marketIds[$market] ?? 'All markets', 'platform_filter' => $filter,
            'params' => $params + ['focus' => $focus, 'window' => $window, 'market' => $market, 'platform' => $filter],
            'as_of' => $now->setTimezone(new \DateTimeZone('Africa/Lagos'))->format('d M Y, H:i') . ' WAT'];
    }

    /** Daily settled hit rates; a single day renders as a horizontal segment. */
    private function sparkline(array $rows): string
    {
        $days = [];
        foreach ($rows as $row) {
            $day = (new \DateTimeImmutable($row['kickoff_at']))->setTimezone(new \DateTimeZone('Africa/Lagos'))->format('Y-m-d');
            $days[$day] ??= ['wins' => 0, 'total' => 0];
            $days[$day]['wins'] += (int) ($row['status'] === 'Won');
            $days[$day]['total']++;
        }
        ksort($days);
        $rates = array_values(array_map(static fn (array $day): float => $day['wins'] / $day['total'], $days));
        if (!$rates) { return ''; }
        if (count($rates) === 1) { $rates[] = $rates[0]; }
        $points = [];
        foreach ($rates as $index => $rate) {
            $points[] = ($index === 0 ? 'M' : 'L') . round(200 * $index / (count($rates) - 1), 2) . ',' . round(42 - 38 * $rate, 2);
        }
        return implode(' ', $points);
    }
}
