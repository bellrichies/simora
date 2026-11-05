<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PlatformDataRepository;

/** No provider has been approved or configured. */
final class UnavailablePlatformDataRepository implements PlatformDataRepository
{
    public function find(string $platform): array
    {
        return ['leagues' => [], 'results' => []];
    }
}
