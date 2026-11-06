<?php

declare(strict_types=1);

namespace SimoraTests\Support;

use App\Contracts\Clock;
use DateTimeImmutable;

final class ModelHealthTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
