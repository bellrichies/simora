<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = max(1, abs($seed) % 2147483647);
    }

    public function integer(int $maximum): int
    {
        $this->state = (int) (($this->state * 48271) % 2147483647);
        return $this->state % $maximum;
    }

    public function shuffled(array $values): array
    {
        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->integer($i + 1);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }
        return $values;
    }
}
