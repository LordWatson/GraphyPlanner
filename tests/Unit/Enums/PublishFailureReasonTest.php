<?php

namespace Tests\Unit\Enums;

use App\Enums\Platform;
use App\Enums\PublishFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step B-5.3 — each known vendor error string maps to the right plain-language cause.
 */
class PublishFailureReasonTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: PublishFailureReason}>
     */
    public static function errorStrings(): array
    {
        return [
            'expired token' => ['The access token has expired, please reconnect.', PublishFailureReason::TokenExpired],
            'reauth required' => ['tiktok_reconnect_required', PublishFailureReason::TokenExpired],
            'unauthorized' => ['401 Unauthorized', PublishFailureReason::TokenExpired],
            'video too long' => ['Video is longer than the maximum length allowed', PublishFailureReason::VideoTooLong],
            'duration' => ['Clip duration exceeds the platform limit', PublishFailureReason::VideoTooLong],
            'file too large' => ['The file size is too large for this platform', PublishFailureReason::MediaTooLarge],
            'unsupported format' => ['Unsupported media format', PublishFailureReason::UnsupportedMedia],
            'aspect ratio' => ['Invalid aspect ratio for a reel', PublishFailureReason::UnsupportedMedia],
            'link rejected' => ['The link preview could not be generated', PublishFailureReason::LinkRejected],
            'duplicate' => ['This is a duplicate of a recent post', PublishFailureReason::DuplicateContent],
            'rate limited' => ['Rate limit exceeded, try later', PublishFailureReason::RateLimited],
            'permission' => ['403 Forbidden: insufficient permission', PublishFailureReason::PermissionDenied],
            'rollout' => ['Publishing to TikTok is not yet enabled (Step 1.7 rollout).', PublishFailureReason::NotEnabledYet],
            'gibberish' => ['something exploded', PublishFailureReason::Unknown],
            'empty' => ['', PublishFailureReason::Unknown],
        ];
    }

    #[DataProvider('errorStrings')]
    public function test_it_classifies_known_vendor_errors(string $error, PublishFailureReason $expected): void
    {
        $this->assertSame($expected, PublishFailureReason::classify($error, Platform::Instagram));
    }

    public function test_messages_name_the_platform_and_offer_a_fix(): void
    {
        $reason = PublishFailureReason::classify('token expired', Platform::Instagram);

        $this->assertStringContainsString('Instagram', $reason->message(Platform::Instagram));
        $this->assertNotSame('', $reason->fix(Platform::Instagram));
    }

    public function test_reconnect_causes_are_not_offered_a_bare_retry(): void
    {
        $this->assertFalse(PublishFailureReason::TokenExpired->isRetryable());
        $this->assertTrue(PublishFailureReason::TokenExpired->needsReconnect());
        $this->assertFalse(PublishFailureReason::NotEnabledYet->isRetryable());
        $this->assertTrue(PublishFailureReason::MediaTooLarge->isRetryable());
    }

    public function test_describe_returns_the_frontend_payload(): void
    {
        $payload = PublishFailureReason::classify('file size too large', Platform::TikTok)->describe(Platform::TikTok);

        $this->assertSame('media_too_large', $payload['reason']);
        $this->assertArrayHasKey('message', $payload);
        $this->assertArrayHasKey('fix', $payload);
        $this->assertTrue($payload['retryable']);
        $this->assertFalse($payload['needs_reconnect']);
    }
}
