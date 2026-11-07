<?php

declare(strict_types=1);

namespace App\Domain\Access;

/** Construct at the transport boundary. No owner ID or plan from the request is accepted. */
final class SessionCredentials
{
    public function __construct(
        public readonly bool $https,
        public readonly ?string $token,
        public readonly ?string $csrfToken = null
    ) {
    }
}
