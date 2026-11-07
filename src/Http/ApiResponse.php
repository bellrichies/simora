<?php

declare(strict_types=1);

namespace App\Http;

final class ApiResponse
{
    public function __construct(public int $status, public array $body, public array $headers = [])
    {
    }

    public function send(bool $head = false): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (!$head) {
            echo json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
    }
}
