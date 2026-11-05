<?php

declare(strict_types=1);

namespace App\Contracts;

/** Optional lightweight league discovery for platform selectors. */
interface PlatformLeagueCatalog
{
    /** @return array<int, array{id: string, name: string}> */
    public function listLeagues(string $platform): array;
}
