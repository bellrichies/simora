<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $db = SimoraTests\Support\AccessTestDatabase::connect();
    $api = App\Infrastructure\ExperienceServiceFactory::create($db, $input['config']);
    $result = $api->handle(
        'POST',
        '/api/v1/support/tickets',
        [],
        json_encode($input['body'], JSON_THROW_ON_ERROR),
        new App\Domain\Access\SessionCredentials(true, $input['token'], $input['csrf'])
    );
    echo json_encode(['status' => $result->status], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'isolated_experience_worker_failed');
    exit(1);
}
