<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class Csrf
{
    public function issue(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function verify(?string $expected, ?string $provided): bool
    {
        return is_string($expected) && is_string($provided)
            && preg_match('/^[a-f0-9]{64}$/D', $expected) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $provided) === 1
            && hash_equals($expected, $provided);
    }
}
