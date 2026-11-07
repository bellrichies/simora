<?php

declare(strict_types=1);

$read = static fn (string $key, string $default = ''): string =>
    (string) (getenv($key) !== false ? getenv($key) : ($_ENV[$key] ?? $default));

return [
    'provider' => 'paystack',
    'enabled' => $read('SIMORA_PAYMENTS_ENABLED', 'false') === 'true',
    'mode' => $read('PAYSTACK_MODE', 'test'),
    'account' => $read('PAYSTACK_ACCOUNT', 'unconfigured'),
    'secret_key' => $read('PAYSTACK_SECRET_KEY'),
    'signature_mode' => $read('PAYSTACK_SIGNATURE_MODE', 'timestamped'),
    'webhook_keys_json' => $read('PAYSTACK_WEBHOOK_KEYS', '{}'),
    'callback_url' => $read('PAYSTACK_CALLBACK_URL'),
    'sandbox_approval_expires_at' => $read('SIMORA_SANDBOX_APPROVAL_EXPIRES_AT', '1970-01-01T00:00:00Z'),
    'live_enabled' => false,
    'payouts_enabled' => false,
];
