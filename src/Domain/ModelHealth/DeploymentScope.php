<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use App\Domain\MarketCatalog;
use InvalidArgumentException;

final class DeploymentScope
{
    public function __construct(
        public readonly string $partition,
        public readonly ?string $platform = null,
        public readonly ?string $league = null,
        public readonly ?string $market = null
    ) {
        if (
            !in_array($partition, ['authorized_live', 'approved_backfill'], true)
            || ($league !== null && $platform === null)
            || ($market !== null && !isset(MarketCatalog::all()[$market]))
        ) {
            throw new InvalidArgumentException('Invalid release partition or scope.');
        }
        foreach ([$platform, $league] as $id) {
            if ($id !== null && ($id === '' || strlen($id) > 128)) {
                throw new InvalidArgumentException('Invalid scope identity.');
            }
        }
    }

    public function key(): string
    {
        return hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
    }

    public function overlaps(self $other): bool
    {
        if ($this->partition !== $other->partition) {
            return false;
        }
        foreach (['platform', 'league', 'market'] as $field) {
            if ($this->$field !== null && $other->$field !== null && $this->$field !== $other->$field) {
                return false;
            }
        }
        return true;
    }

    public function contains(self $other): bool
    {
        if ($this->partition !== $other->partition) {
            return false;
        }
        foreach (['platform', 'league', 'market'] as $field) {
            if ($this->$field !== null && $this->$field !== $other->$field) {
                return false;
            }
        }
        return true;
    }
}
