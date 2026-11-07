<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\SessionCredentials;
use App\Domain\Experience\ExperienceException;
use App\Domain\Experience\ExperiencePolicy;
use App\Repositories\AccessRepository;
use App\Repositories\ExperienceRepository;

final class ExperienceContext
{
    public function __construct(
        public readonly ExperienceRepository $repository,
        public readonly AccessRepository $access,
        public readonly ExperiencePolicy $policy
    ) {
    }

    public function run(
        SessionCredentials $credentials,
        string $module,
        string $permission,
        bool $write,
        callable $work
    ): mixed {
        return $this->repository->transaction(function () use ($credentials, $module, $permission, $write, $work) {
            $actor = SessionAuthorization::owner($this->access, $credentials, $permission, $write);
            $user = $this->repository->owner($actor);
            if ($user === null || ($write && $user['email_verified_at'] === null)) {
                throw new ExperienceException('verified_account_required', 403);
            }
            $this->policy->requireModule($module, $this->repository->now());
            if (!$write) {
                $this->repository->rate($actor, 'experience.read', 60, $this->policy->limit('read_limit_minute'));
                $this->repository->audit($actor, 'experience.read', $module, 'scope', bin2hex(random_bytes(16)));
            }
            return $work($actor);
        });
    }

    public function write(int $actor, string $operation, string $type, string $id, string $key): void
    {
        $this->repository->rate($actor, 'experience.write', 60, $this->policy->limit('write_limit_minute'));
        $this->repository->audit($actor, 'experience.write', $type, $id, $key);
        $this->repository->audit($actor, $operation, $type, $id, $key);
    }

    public function notify(int $owner, int $actor, string $key, string $type, array $payload): void
    {
        $this->access->publishNotification($owner, $actor, $key, $type, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
