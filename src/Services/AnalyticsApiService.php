<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\MarketCatalog;
use InvalidArgumentException;

/** Public catalog only. Production analytics remain gated on canonical persisted read models. */
final class AnalyticsApiService
{
    public function __construct(private array $platforms)
    {
    }

    public function markets(): array
    {
        $rows = [];
        foreach (MarketCatalog::all() as $id => $market) {
            $rows[] = ['id' => $id, 'rule_version' => 1] + $market;
        }
        return $rows;
    }

    public function platforms(): array
    {
        $rows = [];
        foreach ($this->platforms as $slug => $platform) {
            $rows[] = ['id' => $slug, 'slug' => $slug, 'name' => $platform['name'],
                'availability_state' => 'unavailable'];
        }
        return $rows;
    }

    public function hasPlatform(string $slug): bool
    {
        return isset($this->platforms[$slug]);
    }

    public function validateBest(array $query): void
    {
        $allowed = ['platforms', 'leagues', 'markets', 'from', 'to', 'as_of',
            'model_mode', 'ranking_mode', 'model_code', 'limit'];
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown query parameter.');
        }
        foreach ($query as $value) {
            if (!is_string($value) || strlen($value) > 2048) {
                throw new InvalidArgumentException('Query parameters must be bounded strings.');
            }
        }
        if (!in_array($query['model_mode'] ?? null, ['manual', 'validated_best', 'compare'], true)) {
            throw new InvalidArgumentException('A valid model_mode is required.');
        }
        if (!in_array($query['ranking_mode'] ?? null, ['best_value', 'highest_probability', 'balanced'], true)) {
            throw new InvalidArgumentException('A valid ranking_mode is required.');
        }
        if (($query['model_mode'] ?? null) === 'manual') {
            if (!in_array($query['model_code'] ?? null, ['spe', 'mlpe', 'beve'], true)) {
                throw new InvalidArgumentException('Manual mode requires model_code.');
            }
        } elseif (isset($query['model_code'])) {
            throw new InvalidArgumentException('model_code is only valid in manual mode.');
        }
        $catalogs = ['platforms' => array_keys($this->platforms), 'markets' => array_keys(MarketCatalog::all())];
        foreach ($catalogs as $key => $ids) {
            if (isset($query[$key]) && array_diff(explode(',', $query[$key]), $ids) !== []) {
                throw new InvalidArgumentException('Invalid ' . $key . '.');
            }
        }
        if (!isset($query['platforms'], $query['leagues'], $query['from'], $query['to'])) {
            throw new InvalidArgumentException('platforms, leagues, from and to define the required scope.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,128}(?:,[a-zA-Z0-9_.:-]{1,128}){0,49}$/D', $query['leagues'])) {
            throw new InvalidArgumentException('Invalid leagues.');
        }
        foreach (['from', 'to', 'as_of'] as $key) {
            if (isset($query[$key]) && !self::validTime($query[$key])) {
                throw new InvalidArgumentException($key . ' must be a valid UTC timestamp.');
            }
        }
        if (strtotime($query['from']) >= strtotime($query['to'])) {
            throw new InvalidArgumentException('from must precede to.');
        }
        if (isset($query['limit']) && !preg_match('/^(?:[1-9]|[1-9][0-9]|100)$/D', $query['limit'])) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }
    }

    private static function validTime(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }
}
