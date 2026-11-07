<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\AccessException;
use App\Domain\Access\SessionCredentials;
use App\Domain\Identity\SessionToken;
use App\Repositories\AccessRepository;

/** Shared verification against the injected repository in the caller's existing transaction. */
final class SessionAuthorization
{
    public static function owner(
        AccessRepository $repository,
        SessionCredentials $credentials,
        string $permission,
        bool $write
    ): int {
        if (!$credentials->https) {
            throw new AccessException('https_required', 403);
        }
        if (!is_string($credentials->token) || preg_match('/^[a-f0-9]{64}$/D', $credentials->token) !== 1) {
            throw new AccessException('authentication_required', 401);
        }
        $session = $repository->session((new SessionToken())->digest($credentials->token), true);
        if ($session === null) {
            throw new AccessException('authentication_required', 401);
        }
        $owner = (int) $session['user_id'];
        if (!$repository->permitted($owner, $permission)) {
            throw new AccessException('forbidden', 403);
        }
        if (
            $write && (!is_string($session['csrf_token_hash']) || !is_string($credentials->csrfToken)
            || preg_match('/^[a-f0-9]{64}$/D', $credentials->csrfToken) !== 1
            || !hash_equals($session['csrf_token_hash'], hash('sha256', $credentials->csrfToken, true)))
        ) {
            throw new AccessException('csrf_failed', 403);
        }
        return $owner;
    }
}
