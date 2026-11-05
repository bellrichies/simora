<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PlatformDataRepository;
use App\Infrastructure\JsonFixtureStore;

final class DashboardDataService
{
    public function __construct(private PlatformDataRepository $repository, private JsonFixtureStore $store, private array $catalog, private array $settings)
    {
    }

    public function getData(bool $includeOverview, bool $includeLeagues = false, array $overviewQuery = []): array
    {
        $account = $this->store->read('account');
        $platforms = $pulse = [];
        $modelRows = [];
        $modelForms = [];
        foreach ($this->catalog as $slug => $configuration) {
            // Only Overview needs every platform's history/schedule; other pages use catalog navigation.
            $compact = $includeOverview && $this->repository instanceof \App\Contracts\PlatformOverviewRepository;
            $data = $includeOverview
                ? ($compact ? $this->repository->overview($slug) : $this->repository->find($slug))
                : ['leagues' => [], 'upcoming' => []];
            if (!$includeOverview && $includeLeagues) {
                $data['leagues'] = $this->repository instanceof \App\Contracts\PlatformLeagueCatalog
                    ? $this->repository->listLeagues($slug) : $this->repository->find($slug)['leagues'];
            }
            $week = $data['upcoming'][0] ?? null;
            $platforms[] = ['slug' => $slug, 'name' => $configuration['name'],
                'league' => $data['leagues'][0]['name'] ?? $configuration['league'],
                'leagues' => $data['leagues'],
                'interval' => 'Every ' . ($configuration['interval_seconds'] / 60) . ' minutes',
                'interval_seconds' => $configuration['interval_seconds'],
                'status' => $week ? 'Demo schedule available' : 'No week scheduled',
                'class' => $configuration['style'], 'week_state' => $week ? 'ready' : 'empty',
                'week' => $week ? ['league' => $week['league_name'], 'week' => $week['week'],
                    'kickoff_at' => $week['kickoff_at'], 'fixture' => ($data['source']['kind'] ?? null) === 'synthetic'] : null];
            if (!$includeOverview) {
                continue;
            }
            if ($compact) {
                foreach ($data['pulse'] as $label => $counts) {
                    $pulse[$label] ??= ['won' => 0, 'total' => 0];
                    $pulse[$label]['won'] += $counts['won'];
                    $pulse[$label]['total'] += $counts['total'];
                }
                $rows = $data['models'][$this->settings['default_market']] ?? [];
                $modelForms[$slug] = $this->modelSummary($rows, $configuration['name'], $data['leagues'][0]['name'] ?? $configuration['league']);
                if ($slug === array_key_first($this->catalog)) {
                    $modelRows = $rows;
                }
                continue;
            }
            $rows = [];
            foreach ($data['results'] as $row) {
                $pulse[$row['pick']] ??= ['won' => 0, 'total' => 0];
                $pulse[$row['pick']]['won'] += (int) ($row['status'] === 'Won');
                $pulse[$row['pick']]['total']++;
                if ($row['market_id'] === $this->settings['default_market']) {
                    $rows[] = $row;
                }
            }
            $modelForms[$slug] = $this->modelSummary($rows, $configuration['name'], $data['leagues'][0]['name'] ?? $configuration['league']);
            if ($slug === array_key_first($this->catalog)) {
                $modelRows = $rows;
            }
        }
        $selectedModel = is_string($overviewQuery['model_platform'] ?? null) && isset($modelForms[$overviewQuery['model_platform']])
            ? $overviewQuery['model_platform'] : array_key_first($modelForms);
        $platformFilter = is_string($overviewQuery['platform'] ?? null) && isset($this->catalog[$overviewQuery['platform']])
            ? $overviewQuery['platform'] : 'all';
        return ['platforms' => $platforms, 'account' => $account['account'],
            'platform_filter' => $platformFilter, 'selected_model_platform' => $selectedModel,
            'model_summary' => $modelForms[$selectedModel] ?? $this->modelSummary([], '', ''),
            'activity' => $account['activity'], 'quota' => $account['quota'],
            'pulse' => array_map(static fn (array $entry): int => (int) round(100 * $entry['won'] / $entry['total']), $pulse),
            'model_name' => $modelRows[0]['model_name'] ?? 'Unavailable',
            'model_form' => array_column(array_slice($modelRows, 0, 5), 'status')];
    }

    private function modelSummary(array $rows, string $platform, string $league): array
    {
        $form = array_column(array_slice($rows, 0, 5), 'status');
        return ['name' => preg_replace('/\bDemo\s*/i', '', $rows[0]['model_name'] ?? 'Unavailable'),
            'platform' => $platform, 'league' => $rows[0]['league_name'] ?? $league, 'form' => $form,
            'wins' => count(array_filter($form, static fn (string $status): bool => $status === 'Won'))];
    }
}
