<?php

declare(strict_types=1);

namespace App\Infrastructure;

/** Fail safely before malformed local data reaches templates or JSON responses. */
final class FixtureValidator
{
    public static function validate(array $data, string $name): void
    {
        if ($name === 'account') {
            self::require(isset($data['account'], $data['quota'], $data['activity']), 'account');
            foreach (['name', 'initial', 'plan'] as $field) {
                self::require(is_string($data['account'][$field] ?? null), 'account.' . $field);
            }
            foreach (['wallet_minor', 'active_plans', 'referrals'] as $field) {
                self::require(is_int($data['account'][$field] ?? null) && $data['account'][$field] >= 0, 'account.' . $field);
            }
            foreach (['used', 'limit'] as $field) {
                self::require(is_int($data['quota'][$field] ?? null) && $data['quota'][$field] >= 0, 'quota.' . $field);
            }
            self::require(is_array($data['activity']), 'activity');
            foreach ($data['activity'] as $activity) {
                foreach (['title', 'description', 'time_label'] as $field) {
                    self::require(is_string($activity[$field] ?? null), 'activity.' . $field);
                }
            }
            return;
        }
        foreach (['source', 'leagues', 'models', 'teams', 'weeks', 'matches', 'predictions'] as $field) {
            self::require(is_array($data[$field] ?? null), $field);
        }
        self::require(($data['platform_id'] ?? null) === $name, 'platform_id');
        foreach (['kind', 'profile', 'generated_at', 'notice'] as $field) {
            self::require(is_string($data['source'][$field] ?? null), 'source.' . $field);
        }
        self::require(is_int($data['source']['seed'] ?? null), 'source.seed');
        foreach ($data['leagues'] as $id => $league) {
            self::require(is_string($id) && ($league['id'] ?? null) === $id
                && is_string($league['name'] ?? null) && $league['name'] !== '', 'league');
        }
        foreach ($data['teams'] as $id => $team) {
            self::require(($team['id'] ?? null) === $id, 'team.id');
            foreach (['name', 'code', 'kit_id', 'shirt_asset'] as $field) {
                self::require(is_string($team[$field] ?? null), 'team.' . $field);
            }
            self::require(in_array($team['kit_id'], ['white', 'blue', 'red', 'claret', 'orange', 'gold',
                'red-white', 'blue-white', 'red-black', 'red-blue'], true), 'team.kit_id');
            self::require((bool) preg_match('#^/assets/kits/[a-z0-9-]+\.svg$#D', $team['shirt_asset']), 'team.shirt_asset');
        }
        foreach ($data['models'] as $id => $model) {
            self::require(($model['id'] ?? null) === $id && is_string($model['name'] ?? null), 'model');
        }
        foreach ($data['weeks'] as $id => $week) {
            self::require(($week['id'] ?? null) === $id && isset($data['leagues'][$week['league_id'] ?? ''])
                && is_int($week['number'] ?? null) && $week['number'] > 0 && is_string($week['season'] ?? null)
                && in_array($week['state'] ?? null, ['settled', 'scheduled'], true), 'week');
        }
        foreach ($data['matches'] as $id => $match) {
            self::require(($match['id'] ?? null) === $id && isset($data['weeks'][$match['week_id'] ?? ''],
                $data['teams'][$match['home_team_id'] ?? ''], $data['teams'][$match['away_team_id'] ?? ''])
                && $match['home_team_id'] !== $match['away_team_id'], 'match relations');
            self::require(in_array($match['state'] ?? null, ['settled', 'scheduled'], true), 'match.state');
            if ($match['state'] === 'settled') {
                self::require(is_string($match['kickoff_at'] ?? null) && strtotime($match['kickoff_at']) !== false
                    && is_int($match['home_score'] ?? null) && $match['home_score'] >= 0
                    && is_int($match['away_score'] ?? null) && $match['away_score'] >= 0, 'settled match');
            } else {
                self::require(($match['home_score'] ?? null) === null && ($match['away_score'] ?? null) === null
                    && is_int($match['kickoff_offset_seconds'] ?? null) && $match['kickoff_offset_seconds'] >= 0, 'scheduled match');
            }
        }
        foreach ($data['predictions'] as $id => $prediction) {
            self::require(($prediction['id'] ?? null) === $id && isset($data['matches'][$prediction['match_id'] ?? ''],
                $data['models'][$prediction['model_id'] ?? '']) && is_string($prediction['market_id'] ?? null)
                && is_int($prediction['odds_hundredths'] ?? null) && $prediction['odds_hundredths'] > 100
                && in_array($prediction['scope'] ?? null, ['calibration', 'history', 'preview'], true)
                && is_string($prediction['created_at'] ?? null) && strtotime($prediction['created_at']) !== false, 'prediction');
            $match = $data['matches'][$prediction['match_id']];
            self::require(($prediction['scope'] === 'preview') === ($match['state'] === 'scheduled'), 'prediction scope');
            if ($match['state'] === 'settled') {
                self::require(strtotime($prediction['created_at']) < strtotime($match['kickoff_at']), 'prediction chronology');
            }
        }
    }

    private static function require(bool $valid, string $field): void
    {
        if (!$valid) {
            throw new \RuntimeException('Invalid fixture field: ' . $field);
        }
    }
}
