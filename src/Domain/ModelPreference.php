<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/** Validation and precedence only; a preference never grants access to a model. */
final class ModelPreference
{
    public static function validate(array $input, array $platforms): array
    {
        $allowed = ['platform', 'market', 'model_mode', 'model_code', 'expected_version'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown preference field.');
        }
        $platform = $input['platform'] ?? null;
        $market = $input['market'] ?? null;
        if ($platform !== null && (!is_string($platform) || !isset($platforms[$platform]))) {
            throw new InvalidArgumentException('Unknown platform.');
        }
        if ($market !== null && (!is_string($market) || !isset(MarketCatalog::all()[$market]))) {
            throw new InvalidArgumentException('Unknown market.');
        }
        $mode = $input['model_mode'] ?? null;
        if (!in_array($mode, ['manual', 'validated_best', 'compare'], true)) {
            throw new InvalidArgumentException('Invalid model_mode.');
        }
        $model = $input['model_code'] ?? null;
        if ($mode === 'manual' && !in_array($model, ['spe', 'mlpe', 'beve'], true)) {
            throw new InvalidArgumentException('Manual mode requires a canonical model_code.');
        }
        if ($mode !== 'manual' && $model !== null) {
            throw new InvalidArgumentException('model_code is only valid in manual mode.');
        }
        if (!is_int($input['expected_version'] ?? null) || $input['expected_version'] < 0) {
            throw new InvalidArgumentException('expected_version must be a non-negative integer.');
        }
        return [
            'scope_key' => self::scope($platform, $market),
            'model_mode' => $mode,
            'model_code' => $model,
            'expected_version' => $input['expected_version'],
        ];
    }

    /** Rows must already be restricted to the authenticated owner. */
    public static function resolve(array $rows, ?string $platform, ?string $market): ?array
    {
        $keys = [self::scope($platform, $market)];
        if ($market !== null) {
            $keys[] = self::scope(null, $market);
        }
        if ($platform !== null) {
            $keys[] = self::scope($platform, null);
        }
        $keys[] = 'global';
        foreach (array_unique($keys) as $key) {
            foreach ($rows as $row) {
                if (($row['scope_key'] ?? null) === $key) {
                    return $row;
                }
            }
        }
        return null;
    }

    private static function scope(?string $platform, ?string $market): string
    {
        $parts = [];
        if ($platform !== null) {
            $parts[] = 'platform:' . $platform;
        }
        if ($market !== null) {
            $parts[] = 'market:' . $market;
        }
        return $parts === [] ? 'global' : implode('|', $parts);
    }
}
