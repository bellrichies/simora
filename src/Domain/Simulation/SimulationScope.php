<?php

declare(strict_types=1);

namespace App\Domain\Simulation;

use App\Domain\MarketCatalog;
use InvalidArgumentException;

final class SimulationScope
{
    public function __construct(
        public readonly string $modelCode,
        public readonly string $modelVersionId,
        public readonly string $platformId,
        public readonly string $leagueId,
        public readonly string $marketId,
        public readonly string $partition
    ) {
        foreach ([$modelVersionId, $platformId, $leagueId] as $id) {
            if (preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $id) !== 1) {
                throw new InvalidArgumentException('Invalid canonical scope identifier.');
            }
        }
        if (
            !in_array($modelCode, ['spe', 'mlpe', 'beve'], true)
            || !isset(MarketCatalog::all()[$marketId])
            || !in_array($partition, ['authorized_live', 'approved_backfill'], true)
        ) {
            throw new InvalidArgumentException('Invalid simulation model, market or partition.');
        }
    }

    public function fields(): array
    {
        return ['model_code' => $this->modelCode, 'model_version_id' => $this->modelVersionId,
            'platform_id' => $this->platformId, 'league_id' => $this->leagueId,
            'market_id' => $this->marketId, 'dataset_partition' => $this->partition];
    }
}
