<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Domain\MarketSettlement;
use App\Infrastructure\DemoDatasetGenerator;
use App\Infrastructure\JsonFixtureStore;
use App\Repositories\DemoPlatformDataRepository;
use App\Repositories\UnavailablePlatformDataRepository;
use App\Services\DashboardDataService;
use App\Services\PlatformDetailsService;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$settings = require dirname(__DIR__) . '/config/app.php';
$catalog = require dirname(__DIR__) . '/config/platforms.php';
$store = new JsonFixtureStore(dirname(__DIR__) . '/database/fixtures');
$clock = new class implements \App\Contracts\Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-03T10:00:00Z');
    }
};
$repository = new DemoPlatformDataRepository($store, $clock, $settings, $catalog);
$service = new PlatformDetailsService($repository, $settings);
foreach ($catalog as $slug => $platform) {
    $raw = $store->read($slug);
    $check(count($raw['matches']) === 3060 && count($raw['predictions']) === 5832, 'Normalized fixture counts');
    $check(count($raw['teams']) === 120 && count($raw['weeks']) === 306 && count($raw['leagues']) === 6, 'Catalog relations');
    $snapshot = $repository->find($slug);
    $check(count($snapshot['results']) === 4320, 'Six independent market histories');
    $check($snapshot['upcoming'][0]['match_count'] === count($snapshot['upcoming'][0]['matches']), 'Derived match count');
    $check($snapshot['upcoming'][0]['kickoff_at'] === $snapshot['picks']['Under 2.5'][0]['kickoff_at'], 'Consistent schedule');
    foreach ($snapshot['leagues'] as $league) {
        foreach ($settings['markets'] as $market) {
            $selected = $service->getDetails($slug, ['league' => $league['id'], 'market' => $market['label'], 'show' => '999999']);
            if (in_array($market['id'], ['under_15', 'over_35'], true)) {
                $check($selected['total'] === 0 && $selected['picks'] === [] && $selected['pick'] === null,
                    'Additional canonical markets retain empty histories without invented picks');
                $check($selected['market'] === $market['label'] && $selected['league']['id'] === $league['id'],
                    'Additional markets preserve the requested league and market');
                continue;
            }
            $check($selected['total'] === 120 && $selected['week']['league_id'] === $league['id'], 'League history and schedule');
            $check(count($selected['picks']) === 2 && $selected['pick']['league_id'] === $league['id'], 'League preview');
            $check($selected['pick']['historical_sample_size'] === 160, 'League-specific evidence');
            foreach ($selected['history'] as $row) {
                $check($row['league_id'] === $league['id'], 'History contains only selected league');
            }
        }
    }
    foreach ($settings['markets'] as $market) {
        if (in_array($market['id'], ['under_15', 'over_35'], true)) {
            continue;
        }
        $all = $service->getDetails($slug, ['market' => $market['label'], 'show' => '999999']);
        $check(count($all['history']) === 120 && $all['won'] >= 102 && $all['won'] <= 108, 'High-performance demo profile');
        $check($all['won'] + $all['lost'] === $all['total'] && $all['next_page'] === null, 'Derived totals and exhaustion');
        $check($all['hit_rate'] === (int) round($all['won'] / $all['total'] * 100), 'Derived hit rate');
        $check(count($all['simulation']['points']) === 121, 'One chart point per settled prediction plus origin');
        $net = 0;
        foreach ($all['history'] as $row) {
            $match = $raw['matches'][$row['match_id']];
            $check($row['home_score'] === $match['home_score'] && $row['away_score'] === $match['away_score'], 'Shared match scores');
            $won = MarketSettlement::won($row['market_id'], $row['home_score'], $row['away_score']);
            $check(($row['status'] === 'Won') === $won && $row['pick'] === $market['label'], 'Market-specific stored selection settlement');
            $net += $won ? (int) round(50000 * ($row['odds_hundredths'] - 100) / 100) : -50000;
        }
        $check($net === $all['simulation']['net_minor'] && $net === end($all['simulation']['points']), 'Profit/chart reconciliation');
        $preview = $all['pick'];
        $check($preview['historical_sample_size'] === 160, 'Preview includes 40 calibration plus 120 display records');
        $check(str_contains($preview['reason'], 'of 160 earlier synthetic selections'), 'Explanation derived from evidence');
        // Independently rebuild the first displayed row's historical rate, excluding current/later outcomes.
        $row = $all['history'][0];
        $priorWon = $priorCount = 0;
        foreach ($raw['predictions'] as $forecast) {
            $match = $raw['matches'][$forecast['match_id']];
            if ($raw['weeks'][$match['week_id']]['league_id'] === $all['league']['id'] && $forecast['market_id'] === $market['id'] && $match['state'] === 'settled'
                && $match['kickoff_at'] < $row['kickoff_at']) {
                $priorCount++;
                $priorWon += (int) MarketSettlement::won($market['id'], $match['home_score'], $match['away_score']);
            }
        }
        $check($row['historical_sample_size'] === $priorCount
            && $row['historical_win_rate'] === (int) round(100 * $priorWon / $priorCount), 'Past-only historical rate');
    }
}
$laterClock = new class implements \App\Contracts\Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-03T10:04:00Z');
    }
};
$laterRepository = new DemoPlatformDataRepository($store, $laterClock, $settings, $catalog);
$earlierData = $repository->find('sportybet');
$laterData = $laterRepository->find('sportybet');
$check($laterRepository->listLeagues('sportybet') === $earlierData['leagues'], 'Lightweight catalog matches read model');
$check($laterData['results'] === $earlierData['results'], 'Cache retains historical results');
$check(strtotime($laterData['upcoming'][0]['kickoff_at']) - strtotime($earlierData['upcoming'][0]['kickoff_at']) === 180, 'Cached schedules advance with clock');
$check($laterData['picks']['Under 2.5'][0]['kickoff_at'] === $laterData['upcoming'][0]['kickoff_at'], 'Cached preview and schedule remain synchronized');
$check($laterData['quota'] === $store->read('account')['quota'], 'Account quota is read independently');

$default = $service->getDetails('sportybet', []);
$check(MarketSettlement::won('under_35', 2, 1) && !MarketSettlement::won('under_35', 2, 2), 'Under 3.5 settlement boundary');
$labels = array_column($settings['markets'], 'label');
$check(array_search('Under 3.5', $labels, true) === array_search('Under 2.5', $labels, true) + 1, 'Under 3.5 follows Under 2.5');
$under35 = $service->getDetails('sportybet', ['market' => 'Under 3.5']);
$under35Source = $store->read('sportybet')['predictions'][$under35['pick']['id']];
$check($under35Source['market_id'] === 'under_35'
    && $under35Source['scope'] === 'preview'
    && $under35Source['odds_hundredths'] === $under35['pick']['odds_hundredths'], 'Under 3.5 preview uses its stored prediction and odds');
$under35Next = $service->getDetails('sportybet', ['market' => 'Under 3.5', 'pick' => '2']);
$check($under35Next['pick']['id'] !== $under35['pick']['id']
    && $under35Next['pick']['market_id'] === 'under_35', 'Under 3.5 navigates distinct stored picks');
$check(count($default['history']) === 6 && $default['next_page'] === 2, 'Initial batch');
$check(!$default['locked'] && $default['quota']['used'] === 3, 'Quota from account fixture');
$check(count($default['picks']) === 2 && $default['pick']['home'] === 'Liverpool', 'Existing pick layout data');
$check($service->getDetails('sportybet', ['pick' => '2'])['pick']['home'] === 'Manchester United', 'Pick navigation');
$check($service->getDetails('sportybet', ['pick' => ['bad']])['pick_index'] === 0, 'Invalid pick');
$week = $default['history'][0]['week'];
$filtered = $service->getDetails('sportybet', ['week' => $week, 'status' => 'Won']);
foreach ($filtered['history'] as $row) {
    $check($row['week'] === $week && $row['status'] === 'Won', 'Combined filters');
}
$legacy = $service->getDetails('sportybet', ['round' => str_replace('W', 'R', $week), 'status' => 'Won']);
$check($legacy['history'] === $filtered['history'], 'Legacy week bookmark');
$check($filtered['won'] === $default['won'], 'Table filter does not change model summary');
$expanded = $service->getDetails('sportybet', ['show' => '12']);
$second = $service->getDetails('sportybet', ['page' => '2']);
$check(array_merge($default['history'], $second['history']) === $expanded['history'], 'Stable cumulative/API batching');
$check(count(array_unique(array_column($expanded['history'], 'id'))) === 12, 'No duplicate predictions');
$check($service->getDetails('sportybet', ['page' => '9999'])['page'] === 20, 'Bound page');
$double = $service->getDetails('sportybet', ['stake' => '1000']);
$check($double['simulation']['net_minor'] === $default['simulation']['net_minor'] * 2, 'Stake sensitivity');
$exact = $service->simulate([['status' => 'Won', 'odds_hundredths' => 172], ['status' => 'Lost', 'odds_hundredths' => 180]], 50000);
$check($exact['net_minor'] === -14000 && $exact['roi'] === -14.0, 'Fixed-stake arithmetic');
$bad = $service->getDetails('sportybet', ['market' => ['bad'], 'week' => '<script>', 'stake' => '-1', 'page' => '0']);
$check($bad['market'] === 'Under 2.5' && $bad['week_filter'] === 'all' && $bad['stake_error'] && $bad['page'] === 1, 'Input validation');
$check((new PlatformDetailsService(new UnavailablePlatformDataRepository()))->getDetails('sportybet', [])['history'] === [], 'Unavailable data');
$custom = $settings;
$custom['history_page_size'] = 4;
$custom['stake']['default_minor'] = 25000;
$custom['stake']['max_minor'] = 100000;
$configured = (new PlatformDetailsService($repository, $custom))->getDetails('sportybet', ['stake' => '1500']);
$check(count($configured['history']) === 4 && $configured['stake_minor'] === 25000 && $configured['stake_error'], 'Settings drive limits and page size');
$midnightRepo = new class ($repository) implements \App\Contracts\PlatformDataRepository {
    public function __construct(private DemoPlatformDataRepository $repository) {}
    public function find(string $platform): array
    {
        $data = $this->repository->find($platform);
        $row = array_values(array_filter($data['results'], static fn (array $row): bool => $row['pick'] === 'Under 2.5'))[0];
        $data['results'] = [array_merge($row, ['kickoff_at' => '2026-11-03T23:30:00Z'])];
        return $data;
    }
};
$midnight = (new PlatformDetailsService($midnightRepo))->getDetails('sportybet', [])['history'][0];
$check($midnight['kickoff_date'] === '2026-11-04' && $midnight['kickoff_time_label'] === '00:30', 'Lagos midnight rollover');
$overview = (new DashboardDataService($repository, $store, $catalog, $settings))->getData(true);
$check(count($overview['platforms']) === 3 && isset($overview['pulse']['Under 2.5']), 'Overview aggregates platform data');
$overviewService = new DashboardDataService($repository, $store, $catalog, $settings);
foreach (array_keys($catalog) as $slug) {
    $selectedOverview = $overviewService->getData(true, false, ['model_platform' => $slug, 'platform' => $slug]);
    $expectedForm = array_column($repository->overview($slug)['models'][$settings['default_market']], 'status');
    $check($selectedOverview['model_summary']['form'] === $expectedForm, 'Selected overview model uses its own platform records');
    $check($selectedOverview['platform_filter'] === $slug, 'Overview platform filter preserves valid selection');
    $check($selectedOverview['model_summary']['wins'] === count(array_filter($expectedForm, static fn ($status) => $status === 'Won')), 'Overview model wins agree with displayed form');
}
$invalidOverview = $overviewService->getData(true, false, ['model_platform' => ['invalid'], 'platform' => 'missing']);
$check($invalidOverview['platform_filter'] === 'all' && $invalidOverview['selected_model_platform'] === array_key_first($catalog), 'Invalid overview filters safely use defaults');

$fallbackRepository = new class ($repository) implements \App\Contracts\PlatformDataRepository {
    public function __construct(private DemoPlatformDataRepository $repository) {}
    public function find(string $platform): array { return $this->repository->find($platform); }
};
$fallbackOverview = (new DashboardDataService($fallbackRepository, $store, $catalog, $settings))->getData(true);
$check($overview === $fallbackOverview, 'Compact overview matches full read model');
$laterSummary = $laterRepository->overview('sportybet');
$check($laterSummary['upcoming'][0]['kickoff_at'] === $laterData['upcoming'][0]['kickoff_at'], 'Compact overview schedule follows clock');

$generator = new DemoDatasetGenerator();
$date = new DateTimeImmutable($store->read('sportybet')['source']['generated_at']);
$seed = $store->read('sportybet')['source']['seed'];
$generated = $generator->generate('sportybet', $catalog['sportybet'], $settings, $seed, $date);
$check($generated === $store->read('sportybet'), 'Checked-in fixture reproducibility');
$check($generated === $generator->generate('sportybet', $catalog['sportybet'], $settings, $seed, $date), 'Deterministic generation');
$check($generated !== $generator->generate('sportybet', $catalog['sportybet'], $settings, $seed + 1, $date), 'Seed changes data');
$invalid = $generated;
$firstMatch = array_key_first($invalid['matches']);
$invalid['matches'][$firstMatch]['home_team_id'] = 'missing-team';
try {
    \App\Infrastructure\FixtureValidator::validate($invalid, 'sportybet');
    $check(false, 'Invalid relationship accepted');
} catch (RuntimeException $exception) {
    $check(str_contains($exception->getMessage(), 'match relations'), 'Malformed relationship rejected');
}
try {
    $store->read('../secret');
    $check(false, 'Unsafe fixture name accepted');
} catch (InvalidArgumentException) {
    // Fixture paths are constrained.
}

// A provider may expose more than one league; all board data must stay scoped to the selection.
$multiData = $repository->find('sportybet');
$multiData['leagues'][] = ['id' => 'italy', 'name' => 'Italy'];
$italyResult = $multiData['results'][0];
$italyResult['league_id'] = 'italy';
$italyResult['league_name'] = 'Italy';
$multiData['results'][] = $italyResult;
$italyWeek = $multiData['upcoming'][0];
$italyWeek['league_id'] = 'italy';
$italyWeek['league_name'] = 'Italy';
$multiData['upcoming'][] = $italyWeek;
foreach ($multiData['picks'] as $label => $rows) {
    $italyPick = $rows[0];
    $italyPick['league_id'] = 'italy';
    $italyPick['league_name'] = 'Italy';
    $multiData['picks'][$label][] = $italyPick;
}
$multiRepository = new class($multiData) implements \App\Contracts\PlatformDataRepository {
    public function __construct(private array $data) {}
    public function find(string $platform): array { return $this->data; }
};
$multiService = new PlatformDetailsService($multiRepository, $settings);
$italy = $multiService->getDetails('sportybet', ['league' => 'italy', 'market' => $italyResult['pick']]);
$check($italy['league']['name'] === 'Italy' && $italy['total'] === 1, 'League scopes history');
$check($italy['week']['league_id'] === 'italy' && $italy['pick']['league_id'] === 'italy', 'League scopes schedule and picks');
try {
    $multiService->getDetails('sportybet', ['league' => 'unknown']);
    throw new RuntimeException('Unknown league accepted');
} catch (InvalidArgumentException $exception) {
    // Expected: do not silently display another league.
}

echo "Platform dataset, service and integration-contract tests passed\n";
