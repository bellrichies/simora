<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\MarketSettlement;

/** Deliberately outcome-selected synthetic fixtures for exercising a successful UI state. */
final class DemoDatasetGenerator
{
    public function generate(string $slug, array $platform, array $settings, int $seed, \DateTimeImmutable $asOf): array
    {
        $definitions = [
            'england' => 'England League', 'italy' => 'Italy League', 'spain' => 'Spain League',
            'germany' => 'Germany League', 'france' => 'France League', 'turkey' => 'Turkey League',
        ];
        $combined = null;
        foreach ($definitions as $code => $name) {
            // Preserve the original IDs for existing first-league bookmarks.
            $first = $code === 'england';
            $part = $this->generateSingle($first ? $slug : $slug . '-' . $code,
                array_merge($platform, ['league' => $name]), $settings, $seed, $asOf);
            if ($first) {
                $combined = $part;
                $combined['schema_version'] = 3;
                $combined['leagues'] = [$part['league']['id'] => $part['league']];
                unset($combined['league']);
                continue;
            }
            $combined['leagues'][$part['league']['id']] = $part['league'];
            // Synthetic team names avoid presenting English clubs as another country's league.
            foreach ($part['teams'] as $id => $team) {
                $newId = $code . '-' . $id;
                $team['id'] = $newId;
                $team['name'] = ucfirst($code) . ' Demo Club ' . (count($combined['teams']) - 19);
                $team['code'] = strtoupper(substr($code, 0, 2)) . sprintf('%02d', count($combined['teams']) - 19);
                $combined['teams'][$newId] = $team;
            }
            foreach ($part['matches'] as &$match) {
                $match['home_team_id'] = $code . '-' . $match['home_team_id'];
                $match['away_team_id'] = $code . '-' . $match['away_team_id'];
            }
            unset($match);
            foreach (['weeks', 'matches', 'predictions'] as $collection) {
                $combined[$collection] += $part[$collection];
            }
        }
        return $combined;
    }

    private function generateSingle(string $slug, array $platform, array $settings, int $seed, \DateTimeImmutable $asOf): array
    {
        $random = new SeededRandom($seed + (int) sprintf('%u', crc32($slug)));
        $teams = [];
        $definitions = [
            ['liv', 'Liverpool', 'LIV', 'red', 'liverpool'],
            ['for', 'Nottingham Forest', 'FOR', 'red', 'nottingham'],
            ['mun', 'Manchester United', 'MUN', 'red', 'nottingham'],
            ['bou', 'Bournemouth', 'BOU', 'red-black', 'liverpool'],
            ['ful', 'Fulham', 'FUL', 'white', 'nottingham'],
            ['brn', 'Brentford', 'BRN', 'red-white', 'liverpool'],
            ['cry', 'Crystal Palace', 'CRY', 'red-blue', 'nottingham'],
            ['eve', 'Everton', 'EVE', 'blue', 'liverpool'],
            ['wol', 'Wolves', 'WOL', 'gold', 'nottingham'],
            ['whu', 'West Ham', 'WHU', 'claret', 'liverpool'],
            ['bha', 'Brighton', 'BHA', 'blue-white', 'nottingham'],
            ['shu', 'Sheffield United', 'SHU', 'red-white', 'liverpool'],
            ['lut', 'Luton Town', 'LUT', 'orange', 'nottingham'],
            ['bur', 'Burnley', 'BUR', 'claret', 'liverpool'],
            ['lee', 'Leeds United', 'LEE', 'white', 'nottingham'],
            ['nor', 'Norwich City', 'NOR', 'gold', 'liverpool'],
            ['avl', 'Aston Villa', 'AVL', 'claret', 'nottingham'],
            ['che', 'Chelsea', 'CHE', 'blue', 'liverpool'],
            ['ars', 'Arsenal', 'ARS', 'red-white', 'nottingham'],
            ['mci', 'Manchester City', 'MCI', 'blue', 'liverpool'],
        ];
        foreach ($definitions as [$id, $name, $code, $kit, $shirt]) {
            $teams[$id] = ['id' => $id, 'name' => $name, 'code' => $code,
                'kit_id' => $kit, 'shirt_asset' => '/assets/kits/' . $shirt . '.svg'];
        }
        $year = (int) $asOf->format('Y') - ((int) $asOf->format('n') < 7 ? 1 : 0);
        $leagueId = $slug . '-league';
        $season = $year . '/' . substr((string) ($year + 1), -2);
        $matches = [];
        $weeks = [];
        $teamIds = array_keys($teams);
        $scores = [[1, 0], [0, 0], [1, 1], [2, 0], [2, 1], [2, 2], [3, 0], [1, 3]];
        // Fixed generation time makes page reloads and subsequent API pages stable.
        $last = $asOf->setTimezone(new \DateTimeZone('UTC'))->setTime(12, 0)->modify('-1 day');
        for ($week = 1; $week <= 50; $week++) {
            $weekId = $slug . '-' . $year . '-w' . $week;
            $weeks[$weekId] = ['id' => $weekId, 'league_id' => $leagueId, 'number' => $week,
                'season' => $season, 'state' => 'settled'];
            $pairings = $random->shuffled($teamIds);
            for ($i = 0; $i < 10; $i++) {
                [$homeScore, $awayScore] = $scores[$random->integer(count($scores))];
                $id = $weekId . '-m' . ($i + 1);
                $offset = ((50 - $week) * 10 + 9 - $i) * $platform['interval_seconds'];
                $matches[$id] = ['id' => $id, 'week_id' => $weekId,
                    'home_team_id' => $pairings[$i * 2], 'away_team_id' => $pairings[$i * 2 + 1],
                    'kickoff_at' => $last->modify('-' . $offset . ' seconds')->format(DATE_ATOM),
                    'state' => 'settled', 'home_score' => $homeScore, 'away_score' => $awayScore];
            }
        }
        $predictions = [];
        foreach ($settings['markets'] as $marketIndex => $market) {
            foreach ([['calibration', 0, 100, 40, 34], ['history', 100, 400, 120, 102 + ($marketIndex + $seed) % 7]] as [$scope, $start, $length, $count, $wins]) {
                $winning = $losing = [];
                foreach (array_slice($matches, $start, $length, true) as $id => $match) {
                    if (MarketSettlement::won($market['id'], $match['home_score'], $match['away_score'])) {
                        $winning[] = $id;
                    } else {
                        $losing[] = $id;
                    }
                }
                if (count($winning) < $wins || count($losing) < $count - $wins) {
                    throw new \RuntimeException('Insufficient demo match candidates.');
                }
                $selected = array_merge(array_slice($random->shuffled($winning), 0, $wins),
                    array_slice($random->shuffled($losing), 0, $count - $wins));
                foreach ($selected as $matchId) {
                    $id = $matchId . '-' . $market['id'];
                    $predictions[$id] = ['id' => $id, 'match_id' => $matchId, 'market_id' => $market['id'],
                        'model_id' => 'simora-demo-v2', 'scope' => $scope,
                        'odds_hundredths' => 165 + $random->integer(76),
                        'created_at' => (new \DateTimeImmutable($matches[$matchId]['kickoff_at']))->modify('-60 seconds')->format(DATE_ATOM)];
                }
            }
        }
        $weekId = $slug . '-' . $year . '-w51';
        $weeks[$weekId] = ['id' => $weekId, 'league_id' => $leagueId, 'number' => 51,
            'season' => $season, 'state' => 'scheduled'];
        for ($i = 0; $i < 10; $i++) {
            $id = $weekId . '-m' . ($i + 1);
            $matches[$id] = ['id' => $id, 'week_id' => $weekId,
                'home_team_id' => $teamIds[$i * 2], 'away_team_id' => $teamIds[$i * 2 + 1],
                'kickoff_at' => null, 'kickoff_offset_seconds' => 0, 'state' => 'scheduled',
                'home_score' => null, 'away_score' => null];
            if ($i >= 2) {
                continue;
            }
            foreach ($settings['markets'] as $market) {
                $predictionId = $id . '-' . $market['id'];
                $predictions[$predictionId] = ['id' => $predictionId, 'match_id' => $id,
                    'market_id' => $market['id'], 'model_id' => 'simora-demo-v2', 'scope' => 'preview',
                    'odds_hundredths' => 175 + $random->integer(65), 'created_at' => $asOf->format(DATE_ATOM)];
            }
        }
        return ['schema_version' => 2, 'source' => ['kind' => 'synthetic', 'profile' => 'high-performance-demo',
            'seed' => $seed, 'generated_at' => $asOf->format(DATE_ATOM),
            'notice' => 'Synthetic high-performance demo. Outcome-selected examples, not real model results.'],
            'platform_id' => $slug, 'league' => ['id' => $leagueId, 'name' => $platform['league']],
            'models' => ['simora-demo-v2' => ['id' => 'simora-demo-v2', 'name' => 'Simora Demo Model']],
            'teams' => $teams, 'weeks' => $weeks, 'matches' => $matches, 'predictions' => $predictions];
    }
}
