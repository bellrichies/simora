<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\SessionCredentials;

final class ReferralService
{
    public function __construct(private readonly ExperienceContext $context)
    {
    }

    public function read(SessionCredentials $credentials): array
    {
        return $this->context->run($credentials, 'referrals', 'referrals.read', false, function (int $actor) {
            $code = $this->context->repository->referralCode($actor);
            return ['code' => $code === null || $code['revoked_at'] !== null ? null : $code['code'],
                'registered_count' => $this->context->repository->referralCount($actor),
                'attribution_enabled' => false, 'rewards_enabled' => false, 'payouts_enabled' => false];
        });
    }

    public function createCode(SessionCredentials $credentials): array
    {
        return $this->context->run($credentials, 'referrals', 'referrals.create', true, function (int $actor) {
            $r = $this->context->repository;
            $row = $r->referralCode($actor);
            if ($row === null) {
                $row = $r->createReferralCode($actor, $this->context->policy->reference());
                $this->context->write(
                    $actor,
                    'referral.code.created',
                    'referral_code',
                    (string) $row['id'],
                    'code:create'
                );
            }
            return ['code' => $row['revoked_at'] === null ? $row['code'] : null, 'attribution_enabled' => false,
                'rewards_enabled' => false, 'payouts_enabled' => false];
        });
    }
}
