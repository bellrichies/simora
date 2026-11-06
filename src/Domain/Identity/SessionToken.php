<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class SessionToken
{
    public function issue(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function digest(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid session token.');
        }
        return hash('sha256', $token, true);
    }
}
