<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Domain\Access\AccessException;
use App\Domain\Access\SessionCredentials;
use App\Repositories\AccessRepository;
use App\Services\AccessService;
use SimoraTests\Support\AccessTestDatabase;

$db = AccessTestDatabase::connect();
$service = new AccessService(new AccessRepository($db));
try {
    $result = $service->consume(
        new SessionCredentials(
            true,
            getenv('SIMORA_ACCESS_TEST_TOKEN'),
            getenv('SIMORA_ACCESS_TEST_CSRF')
        ),
        'unit.race',
        'global',
        $argv[1],
        1,
        str_repeat('a', 64),
        static function (PDO $db): array {
            usleep(200000);
            $db->prepare('INSERT INTO audit_logs '
                . '(actor_user_id,operation,object_type,object_id,correlation_id,occurred_at) '
                . "VALUES (?,'unit.race.effect','unit','unit','unit',UTC_TIMESTAMP(6))")
                ->execute([(int) getenv('SIMORA_ACCESS_TEST_OWNER')]);
            return ['effect' => 'unit-only'];
        }
    );
    echo json_encode(['status' => 200, 'replayed' => $result['replayed']], JSON_THROW_ON_ERROR);
} catch (AccessException $error) {
    echo json_encode(['status' => $error->status, 'code' => $error->errorCode], JSON_THROW_ON_ERROR);
}
