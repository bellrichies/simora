<?php

declare(strict_types=1);

namespace App;

use App\Services\PlatformDetailsService;

final class PredictionsController
{
    public function __construct(private PlatformDetailsService $service, private array $platforms)
    {
    }

    public function index(string $platform): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->error(405, 'Method not allowed.');
            return;
        }
        if (!isset($this->platforms[$platform])) {
            $this->error(404, 'Platform not found.');
            return;
        }
        $query = $_GET;
        unset($query['show']);
        try {
            $details = $this->service->getDetails($platform, $query);
        } catch (\InvalidArgumentException $exception) {
            $this->error(404, 'League not found.');
            return;
        }
        $rows = array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
            'id', 'match_id', 'week_id', 'league_id', 'league_name', 'market_id', 'model_id', 'model_name', 'home_team_id', 'away_team_id',
            'week', 'home', 'away', 'pick', 'odds_hundredths', 'home_score', 'away_score', 'status',
            'home_code', 'away_code', 'home_kit_id', 'away_kit_id',
            'historical_win_rate', 'historical_sample_size', 'kickoff_at', 'kickoff_label',
            'kickoff_date', 'kickoff_date_label', 'kickoff_time_label',
        ])), $details['history']);
        echo json_encode(['data' => $rows, 'error' => null, 'meta' => [
            'page' => $details['page'], 'next_page' => $details['next_page'],
            'total' => $details['total'], 'page_size' => $details['page_size'],
            'demo' => ($details['source']['kind'] ?? null) === 'synthetic', 'source' => $details['source'],
        ]], JSON_THROW_ON_ERROR);
    }

    private function error(int $status, string $message): void
    {
        http_response_code($status);
        echo json_encode(['data' => null, 'error' => $message, 'meta' => null], JSON_THROW_ON_ERROR);
    }
}
