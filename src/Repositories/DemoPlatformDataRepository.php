<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Clock;
use App\Contracts\PlatformDataRepository;
use App\Domain\MarketSettlement;
use App\Infrastructure\JsonFixtureStore;
use App\Infrastructure\SystemClock;

/** Maps normalized fixtures to the read model a database adapter can return. */
final class DemoPlatformDataRepository implements PlatformDataRepository, \App\Contracts\PlatformLeagueCatalog, \App\Contracts\PlatformOverviewRepository
{
    private JsonFixtureStore $store;
    private Clock $clock;
    private array $settings;
    private array $platforms;
    private array $cache = [];

    public function __construct(?JsonFixtureStore $store = null, ?Clock $clock = null, ?array $settings = null, ?array $platforms = null)
    {
        $this->store = $store ?? new JsonFixtureStore(dirname(__DIR__, 2) . '/database/fixtures');
        $this->clock = $clock ?? new SystemClock();
        $this->settings = $settings ?? require dirname(__DIR__, 2) . '/config/app.php';
        $this->platforms = $platforms ?? require dirname(__DIR__, 2) . '/config/platforms.php';
    }

    public function listLeagues(string $platform): array
    {
        if (!isset($this->platforms[$platform])) {
            return [];
        }
        $fingerprint = hash('sha256', $this->store->fingerprint($platform)
            . hash_file('sha256', dirname(__DIR__) . '/Infrastructure/FixtureValidator.php'));
        $path = $this->cachePath($platform . '-leagues');
        $handle = @fopen($path, 'c+b');
        if ($handle !== false) {
            if (flock($handle, LOCK_SH)) {
                $contents = stream_get_contents($handle);
                flock($handle, LOCK_UN);
                $cached = is_string($contents) ? json_decode($contents, true) : null;
                if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint
                    && is_array($cached['leagues'] ?? null)) {
                    fclose($handle);
                    return $cached['leagues'];
                }
            }
            fclose($handle);
        }
        $leagues = array_values($this->store->read($platform)['leagues']);
        $contents = json_encode(['fingerprint' => $fingerprint, 'leagues' => $leagues]);
        $handle = @fopen($path, 'c+b');
        if ($handle !== false) {
            if (is_string($contents) && flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $contents);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
        return $leagues;
    }

    public function overview(string $platform): array
    {
        if (!isset($this->platforms[$platform])) {
            return ['leagues' => [], 'upcoming' => [], 'pulse' => [], 'models' => []];
        }
        $next = (intdiv($this->clock->now()->getTimestamp(), $this->platforms[$platform]['interval_seconds']) + 1)
            * $this->platforms[$platform]['interval_seconds'];
        $fingerprint = $this->modelFingerprint($platform);
        $path = $this->cachePath($platform . '-overview');
        $handle = @fopen($path, 'rb');
        if ($handle !== false) {
            $contents = false;
            if (flock($handle, LOCK_SH)) {
                $contents = stream_get_contents($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            $cached = is_string($contents) ? json_decode($contents, true) : null;
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint
                && is_int($cached['next'] ?? null) && is_array($cached['data'] ?? null)
                && isset($cached['data']['leagues'], $cached['data']['upcoming'], $cached['data']['pulse'], $cached['data']['models'])) {
                $summary = $cached['data'];
                $delta = $next - $cached['next'];
                foreach ($summary['upcoming'] as &$week) {
                    if ($week['kickoff_at'] !== null) {
                        $week['kickoff_at'] = gmdate(DATE_ATOM, strtotime($week['kickoff_at']) + $delta);
                    }
                }
                unset($week);
                return $summary;
            }
        }
        $data = $this->find($platform);
        $pulse = $models = [];
        foreach ($data['results'] as $row) {
            $pulse[$row['pick']] ??= ['won' => 0, 'total' => 0];
            $pulse[$row['pick']]['won'] += (int) ($row['status'] === 'Won');
            $pulse[$row['pick']]['total']++;
            $market = $row['market_id'];
            if (count($models[$market] ?? []) < 5) {
                $models[$market][] = ['model_name' => $row['model_name'], 'status' => $row['status'], 'league_name' => $row['league_name']];
            }
        }
        $upcoming = [];
        if (isset($data['upcoming'][0])) {
            $week = $data['upcoming'][0];
            $upcoming[] = array_intersect_key($week, array_flip(['league_name', 'week', 'kickoff_at']));
        }
        $summary = ['leagues' => $data['leagues'], 'upcoming' => $upcoming,
            'pulse' => $pulse, 'models' => $models, 'source' => $data['source']];
        $contents = json_encode(['fingerprint' => $fingerprint, 'next' => $next, 'data' => $summary]);
        $handle = @fopen($path, 'c+b');
        if ($handle !== false) {
            if (is_string($contents) && flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $contents);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
        return $summary;
    }

    private function modelFingerprint(string $platform): string
    {
        return hash('sha256', $this->store->fingerprint($platform)
            . hash_file('sha256', __FILE__) . hash_file('sha256', dirname(__DIR__) . '/Infrastructure/FixtureValidator.php')
            . hash_file('sha256', dirname(__DIR__) . '/Domain/MarketSettlement.php')
            . json_encode([$this->settings['markets'], $this->platforms[$platform]], JSON_THROW_ON_ERROR));
    }

    public function find(string $platform): array
    {
        if (isset($this->cache[$platform])) {
            return $this->cache[$platform];
        }
        if (!isset($this->platforms[$platform])) {
            return ['leagues' => [], 'results' => [], 'upcoming' => [], 'picks' => []];
        }
        $next = (intdiv($this->clock->now()->getTimestamp(), $this->platforms[$platform]['interval_seconds']) + 1)
            * $this->platforms[$platform]['interval_seconds'];
        $fingerprint = $this->modelFingerprint($platform);
        $cached = $this->readPersistentCache($platform, $fingerprint);
        if ($cached !== null) {
            $result = $cached['data'];
            $delta = $next - $cached['next'];
            if ($delta !== 0) {
                foreach ($result['upcoming'] as &$week) {
                    foreach ($week['matches'] as &$match) {
                        $match['kickoff_at'] = gmdate(DATE_ATOM, strtotime($match['kickoff_at']) + $delta);
                    }
                    unset($match);
                    if ($week['kickoff_at'] !== null) {
                        $week['kickoff_at'] = gmdate(DATE_ATOM, strtotime($week['kickoff_at']) + $delta);
                    }
                }
                unset($week);
                foreach ($result['picks'] as &$marketPicks) {
                    foreach ($marketPicks as &$pick) {
                        $pick['kickoff_at'] = gmdate(DATE_ATOM, strtotime($pick['kickoff_at']) + $delta);
                    }
                    unset($pick);
                }
                unset($marketPicks);
            }
            $result['quota'] = $this->store->read('account')['quota'];
            return $this->cache[$platform] = $result;
        }
        $data = $this->store->read($platform);
        $markets = array_column($this->settings['markets'], 'label', 'id');
        $results = $picks = $upcoming = $tallies = [];
        $predictions = array_values($data['predictions']);
        // Calibration precedes displayed history; rates never include a later result.
        usort($predictions, static fn (array $a, array $b): int =>
            strcmp($data['matches'][$a['match_id']]['kickoff_at'] ?? '9999',
                $data['matches'][$b['match_id']]['kickoff_at'] ?? '9999') ?: strcmp($a['id'], $b['id']));
        foreach ($predictions as $prediction) {
            $match = $data['matches'][$prediction['match_id']];
            $week = $data['weeks'][$match['week_id']];
            $home = $data['teams'][$match['home_team_id']];
            $away = $data['teams'][$match['away_team_id']];
            $marketId = $prediction['market_id'];
            if (!isset($markets[$marketId])) {
                throw new \RuntimeException('Fixture market is not configured.');
            }
            $market = $markets[$marketId];
            $model = $data['models'][$prediction['model_id']];
            $league = $data['leagues'][$week['league_id']];
            $key = $week['league_id'] . ':' . $prediction['model_id'] . ':' . $marketId;
            $tally = $tallies[$key] ?? ['won' => 0, 'total' => 0];
            $rate = $tally['total'] ? (int) round(100 * $tally['won'] / $tally['total']) : null;
            $row = ['id' => $prediction['id'], 'match_id' => $match['id'], 'week_id' => $week['id'],
                'market_id' => $marketId, 'model_id' => $model['id'], 'model_name' => $model['name'],
                'league_id' => $league['id'], 'league_name' => $league['name'],
                'week' => 'W' . $week['number'], 'home' => $home['name'], 'away' => $away['name'],
                'home_code' => $home['code'], 'away_code' => $away['code'],
                'home_kit_id' => $home['kit_id'], 'away_kit_id' => $away['kit_id'],
                'home_team_id' => $home['id'], 'away_team_id' => $away['id'],
                'home_score' => $match['home_score'], 'away_score' => $match['away_score'],
                'pick' => $market, 'odds_hundredths' => $prediction['odds_hundredths'],
                'kickoff_at' => $match['kickoff_at'], 'created_at' => $prediction['created_at'],
                'historical_win_rate' => $rate, 'historical_sample_size' => $tally['total']];
            if ($match['state'] === 'settled') {
                $won = MarketSettlement::won($marketId, $match['home_score'], $match['away_score']);
                $tallies[$key] = ['won' => $tally['won'] + (int) $won, 'total' => $tally['total'] + 1];
                if ($prediction['scope'] === 'history') {
                    $row['status'] = $won ? 'Won' : 'Lost';
                    $results[] = $row;
                }
                continue;
            }
            $row['market'] = $market;
            $row['win_rate'] = $rate;
            $row['home_kit'] = $home['shirt_asset'];
            $row['away_kit'] = $away['shirt_asset'];
            $row['kickoff_at'] = gmdate(DATE_ATOM, $next + $match['kickoff_offset_seconds']);
            $row['reason'] = sprintf('%s: %s v %s, %s at %.2f. This market won %d of %d earlier synthetic selections (%d%%).',
                $model['name'], $home['code'], $away['code'], $market, $prediction['odds_hundredths'] / 100,
                $tally['won'], $tally['total'], $rate ?? 0);
            $picks[$market][] = $row;
        }
        usort($results, static fn (array $a, array $b): int =>
            strcmp($b['kickoff_at'], $a['kickoff_at']) ?: strcmp($a['id'], $b['id']));
        foreach ($data['weeks'] as $week) {
            if ($week['state'] !== 'scheduled') {
                continue;
            }
            $league = $data['leagues'][$week['league_id']];
            $matches = [];
            foreach ($data['matches'] as $match) {
                if ($match['week_id'] !== $week['id']) {
                    continue;
                }
                $matches[] = ['id' => $match['id'], 'home' => $data['teams'][$match['home_team_id']]['name'],
                    'away' => $data['teams'][$match['away_team_id']]['name'],
                    'kickoff_at' => gmdate(DATE_ATOM, $next + $match['kickoff_offset_seconds'])];
            }
            $upcoming[] = ['id' => $week['id'], 'league_id' => $league['id'],
                'league_name' => $league['name'], 'week' => 'Week ' . $week['number'],
                'season' => $week['season'], 'kickoff_at' => $matches[0]['kickoff_at'] ?? null,
                'match_count' => count($matches), 'matches' => $matches];
        }
        $result = ['leagues' => array_values($data['leagues']), 'results' => $results, 'upcoming' => $upcoming,
            'picks' => $picks, 'source' => $data['source']];
        $this->writePersistentCache($platform, $fingerprint, $next, $result);
        $result['quota'] = $this->store->read('account')['quota'];
        return $this->cache[$platform] = $result;
    }

    private function cachePath(string $platform): string
    {
        // Isolate checkouts and keep the cache outside the document root.
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simora-' . hash('sha256', dirname(__DIR__, 2))
            . '-' . $platform . '.json';
    }

    private function readPersistentCache(string $platform, string $fingerprint): ?array
    {
        $handle = @fopen($this->cachePath($platform), 'rb');
        if ($handle === false) {
            return null;
        }
        $contents = false;
        if (flock($handle, LOCK_SH)) {
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        $cached = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($cached) || ($cached['fingerprint'] ?? null) !== $fingerprint
            || !is_int($cached['next'] ?? null) || !is_array($cached['data'] ?? null)
            || !isset($cached['data']['leagues'], $cached['data']['results'], $cached['data']['upcoming'],
                $cached['data']['picks'], $cached['data']['source'])) {
            return null;
        }
        return $cached;
    }

    private function writePersistentCache(string $platform, string $fingerprint, int $next, array $data): void
    {
        $contents = json_encode(['fingerprint' => $fingerprint, 'next' => $next, 'data' => $data]);
        if (!is_string($contents)) {
            return;
        }
        $handle = @fopen($this->cachePath($platform), 'c+b');
        if ($handle === false) {
            return;
        }
        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $contents);
            fflush($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}
