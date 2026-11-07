<?php

declare(strict_types=1);

$read = static fn (string $key, string $default = ''): string =>
    (string) (getenv($key) !== false ? getenv($key) : ($_ENV[$key] ?? $default));
$features = [];
foreach (['referrals', 'reviews', 'support', 'chat'] as $feature) {
    $features[$feature] = $read('SIMORA_' . strtoupper($feature) . '_ENABLED', 'false') === 'true';
}
return [
    'features' => $features,
    'approval_reference' => $read('SIMORA_EXPERIENCE_APPROVAL_REFERENCE'),
    'privacy_reference' => $read('SIMORA_EXPERIENCE_PRIVACY_REFERENCE'),
    'moderation_reference' => $read('SIMORA_EXPERIENCE_MODERATION_REFERENCE'),
    'retention_reference' => $read('SIMORA_EXPERIENCE_RETENTION_REFERENCE'),
    'moderation_owner' => $read('SIMORA_CHAT_MODERATION_OWNER'),
    'privacy_owner' => $read('SIMORA_CHAT_PRIVACY_OWNER'),
    'abuse_process_reference' => $read('SIMORA_CHAT_ABUSE_PROCESS_REFERENCE'),
    'rights_process_reference' => $read('SIMORA_CHAT_RIGHTS_PROCESS_REFERENCE'),
    'expires_at' => $read('SIMORA_EXPERIENCE_APPROVAL_EXPIRES_AT', '1970-01-01T00:00:00Z'),
    'write_limit_minute' => (int) $read('SIMORA_EXPERIENCE_WRITES_MINUTE', '5'),
    'read_limit_minute' => (int) $read('SIMORA_EXPERIENCE_READS_MINUTE', '60'),
    'tickets_limit_day' => (int) $read('SIMORA_EXPERIENCE_TICKETS_DAY', '5'),
];
