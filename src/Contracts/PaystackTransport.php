<?php

declare(strict_types=1);

namespace App\Contracts;

interface PaystackTransport
{
    /** Only initialize and verify are permitted. Transport must validate TLS, bound responses and never log secrets. */
    public function request(string $method, string $path, array $body = []): array;
}
