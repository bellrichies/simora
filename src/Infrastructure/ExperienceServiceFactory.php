<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Experience\ExperiencePolicy;
use App\Domain\Reviews\ReviewPolicy;
use App\Http\ExperienceController;
use App\Repositories\AccessRepository;
use App\Repositories\ChatRepository;
use App\Repositories\ExperienceRepository;
use App\Services\ChatService;
use App\Services\ExperienceContext;
use App\Services\ReferralService;
use App\Services\ReviewService;
use App\Services\SupportService;
use PDO;

final class ExperienceServiceFactory
{
    public static function create(PDO $db, array $config): ExperienceController
    {
        $context = new ExperienceContext(
            new ExperienceRepository($db),
            new AccessRepository($db),
            new ExperiencePolicy($config)
        );
        return new ExperienceController(
            new ReferralService($context),
            new ReviewService($context, new ReviewPolicy()),
            new SupportService($context),
            new ChatService($context, new ChatRepository($db))
        );
    }
}
