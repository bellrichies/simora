<?php

declare(strict_types=1);

namespace App\Domain\Experience;

use RuntimeException;

final class ExperienceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly int $retryAfter = 60
    ) {
        parent::__construct($errorCode);
    }
}
