<?php

declare(strict_types=1);

namespace App\Domain\Access;

final class AccessException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }
}
