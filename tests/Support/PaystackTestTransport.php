<?php

declare(strict_types=1);

namespace SimoraTests\Support;

use App\Contracts\PaystackTransport;

/** Contract fixtures only; never used for application payment verification. */
final class PaystackTestTransport implements PaystackTransport
{
    public array $response = [];
    public array $calls = [];

    public function request(string $method, string $path, ?array $body = null): array
    {
        $this->calls[] = [$method, $path, $body];
        return $this->response;
    }
}
