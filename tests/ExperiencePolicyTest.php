<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\Experience\ExperienceException;
use App\Domain\Experience\ExperiencePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExperiencePolicyTest extends TestCase
{
    public static function invalidPublicText(): array
    {
        return [['<script>alert(1)</script>'], ['Contact me@example.invalid'], ['Phone 08012345678'],
            ['https://example.invalid'], ["\0secret"], ["\u{00A0}\u{200B}\u{00A0}"], ["\xFF"]];
    }

    #[DataProvider('invalidPublicText')]
    public function testPublicTextRedactionAndEncoding(string $text): void
    {
        $this->expectException(ExperienceException::class);
        ExperiencePolicy::text($text, 1, 1000, true);
    }

    public function testPrivateSupportRemainsPlainTextAndUnicodeLengthIsExact(): void
    {
        self::assertSame('private@example.invalid', ExperiencePolicy::text('private@example.invalid', 1, 100, false));
        self::assertSame('é🙂', ExperiencePolicy::text("\u{00A0}é🙂\u{00A0}", 2, 2));
        $this->expectException(ExperienceException::class);
        ExperiencePolicy::text('é🙂x', 1, 2);
    }

    public function testMissingPolicyReferencesCannotEnableFeatures(): void
    {
        $policy = new ExperiencePolicy(['features' => ['reviews' => true]]);
        $this->expectException(ExperienceException::class);
        $policy->requireModule('reviews', '2026-11-06 12:00:00');
    }

    public function testUnknownFeaturesAndChangedFingerprintsFailClosed(): void
    {
        try {
            (new ExperiencePolicy([]))->requireModule('unapproved', '2026-11-06 12:00:00');
            self::fail('Unknown feature enabled.');
        } catch (ExperienceException $error) {
            self::assertSame('experience_feature_disabled', $error->errorCode);
        }
        $this->expectException(ExperienceException::class);
        ExperiencePolicy::replay(['fingerprint' => str_repeat('a', 64)], str_repeat('b', 64));
    }
}
