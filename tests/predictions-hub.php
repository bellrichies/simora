<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});
$settings = require dirname(__DIR__) . '/config/app.php';
$catalog = require dirname(__DIR__) . '/config/platforms.php';
$clock = new class implements App\Contracts\Clock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-04T12:00:00Z'); }
};
$repository = new App\Repositories\DemoPlatformDataRepository(new App\Infrastructure\JsonFixtureStore(dirname(__DIR__) . '/database/fixtures'), $clock, $settings, $catalog);
$service = new App\Services\PredictionsHubService($repository, $clock, $catalog, $settings);
$check = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$page = $service->getPage([]);
$check(count($page['cards']) === 3 && count($page['history']) === 6, 'Hub renders all platforms and recent results');
foreach ($page['cards'] as $card) {
    $check($card['wins'] + $card['losses'] === $card['total'], 'Settlement totals reconcile');
    $check($card['rate'] === round(100 * $card['wins'] / $card['total'], 1), 'Hit rate reconciles');
    $check($card['sparkline'] !== '' && count($card['form']) === 5, 'Chart and recent form populated');
}
$next = $service->getPage(['page' => '2']);
$check(array_intersect(array_column($page['history'], 'id'), array_column($next['history'], 'id')) === [], 'Pagination does not repeat predictions');
$league = $repository->find('betking')['leagues'][1]['id'];
$filtered = $service->getPage(['league_betking' => $league, 'platform' => 'betking', 'market' => 'under_25', 'window' => '7']);
foreach ($filtered['history'] as $row) {
    $check($row['platform_id'] === 'betking' && $row['league_id'] === $league && $row['market_id'] === 'under_25', 'Filters retain matching records only');
    $check(strtotime($row['kickoff_at']) >= $clock->now()->modify('-7 days')->getTimestamp(), 'Window boundary respected');
}
$check($filtered['total'] > 0 && $filtered['total'] <= $page['total'], 'Filtered history has results');
$focused = $service->getPage(['focus' => 'betking', 'league_betking' => $league, 'market' => 'under_25']);
$check($focused['preview']['slug'] === 'betking' && $focused['preview']['league_id'] === $league && $focused['preview']['market'] === 'under_25', 'Focused preview follows platform, league and market');
$check(count($focused['preview']['rows']) <= 100, 'Replay sample is bounded');
foreach ($focused['preview']['rows'] as $row) {
    $check($row['league_id'] === $league && $row['market_id'] === 'under_25' && in_array($row['status'], ['Won', 'Lost'], true) && strtotime($row['kickoff_at']) <= $clock->now()->getTimestamp(), 'Replay contains only settled matching past outcomes');
}
$check($service->getPage(['focus' => '../invalid'])['focus'] === $page['focus'], 'Invalid focused platform falls back safely');
$invalid = $service->getPage(['market' => ['invalid'], 'platform' => '../bad', 'window' => '8', 'page' => ['2'], 'league_betking' => ['bad']]);
$check($invalid === $page, 'Invalid query fields safely use defaults');
$futureClock = new class implements App\Contracts\Clock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2030-01-01T00:00:00Z'); }
};
$empty = (new App\Services\PredictionsHubService($repository, $futureClock, $catalog, $settings))->getPage([]);
$check($empty['total'] === 0 && $empty['history'] === [] && $empty['cards'][0]['rate'] === null && $empty['cards'][0]['sparkline'] === '', 'Empty windows stay unknown rather than zero hit rate');
echo "Predictions hub tests passed\n";
