<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use InvalidArgumentException;

final class Credentials
{
    public function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || preg_match('/[^\x21-\x7e]/', $email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        return $email;
    }

    public function hashPassword(string $password): string
    {
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new InvalidArgumentException('Password length must be between 12 and 1024 bytes.');
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            throw new \RuntimeException('Password hashing unavailable.');
        }
        return $hash;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return strlen($password) <= 1024 && password_verify($password, $hash);
    }
}
