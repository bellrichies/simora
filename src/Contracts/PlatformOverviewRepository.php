<?php

declare(strict_types=1);

namespace App\Contracts;

/** Optional compact data for overview schedules and aggregate statistics. */
interface PlatformOverviewRepository
{
    public function overview(string $platform): array;
}
