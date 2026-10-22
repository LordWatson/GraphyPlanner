<?php

namespace App\Enums;

/**
 * Step B-5.3 — a plain-language reading of a vendor/platform publish error. Social platforms (via
 * Upload-Post) return terse, inconsistent error strings; this enum classifies them into a handful
 * of causes a business owner can act on, each with human copy and a concrete fix. The raw error is
 * still stored on the target — this only shapes what the user reads and whether a retry is offered.
 */
enum PublishFailureReason: string
{
    case TokenExpired = 'token_expired';
    case VideoTooLong = 'video_too_long';
    case MediaTooLarge = 'media_too_large';
    case UnsupportedMedia = 'unsupported_media';
    case LinkRejected = 'link_rejected';
    case DuplicateContent = 'duplicate_content';
    case RateLimited = 'rate_limited';
    case PermissionDenied = 'permission_denied';
    case NotEnabledYet = 'not_enabled_yet';
    case Unknown = 'unknown';

    /**
     * Map a raw vendor error string to a cause. Order matters: more specific signals are matched
     * before broader ones (an "unauthorized" that mentions "rate" is a rate limit, etc.).
     */
    public static function classify(?string $error, ?Platform $platform = null): self
    {
        $text = mb_strtolower(trim((string) $error));

        if ($text === '') {
            return self::Unknown;
        }

        return match (true) {
            self::matches($text, ['not yet enabled', 'rollout']) => self::NotEnabledYet,
            self::matches($text, ['rate limit', 'rate-limit', 'too many request', 'throttle', '429']) => self::RateLimited,
            self::matches($text, ['expired', 'reconnect', 're-auth', 'reauth', 'token', 'unauthorized', 'not authorized', '401', 'invalid credential', 'access revoked']) => self::TokenExpired,
            self::matches($text, ['too long', 'duration', 'exceeds the maximum length', 'longer than', 'max length', 'video length']) => self::VideoTooLong,
            self::matches($text, ['too large', 'file size', 'size exceeds', 'exceeds the maximum size', 'max size', 'over the limit']) => self::MediaTooLarge,
            self::matches($text, ['unsupported', 'not supported', 'invalid format', 'invalid media', 'codec', 'aspect ratio', 'resolution']) => self::UnsupportedMedia,
            self::matches($text, ['link', 'url preview', 'link preview', 'blocked url', 'domain']) => self::LinkRejected,
            self::matches($text, ['duplicate', 'already posted', 'already published', 'spam']) => self::DuplicateContent,
            self::matches($text, ['permission', 'forbidden', '403', 'not allowed', 'scope', 'insufficient']) => self::PermissionDenied,
            default => self::Unknown,
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private static function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What happened, in the brand's voice — plain, warm, no vendor jargon.
     */
    public function message(?Platform $platform = null): string
    {
        $name = $platform?->label() ?? 'The platform';

        return match ($this) {
            self::TokenExpired => "{$name} needs you to reconnect your account before this can go out.",
            self::VideoTooLong => "Your video is longer than {$name} allows.",
            self::MediaTooLarge => "Your file is larger than {$name} accepts.",
            self::UnsupportedMedia => "{$name} didn't accept this media — the format, size or shape isn't supported.",
            self::LinkRejected => "{$name} wouldn't accept the link in this post.",
            self::DuplicateContent => "{$name} rejected this as a duplicate of something posted recently.",
            self::RateLimited => "{$name} is rate-limiting your account right now.",
            self::PermissionDenied => "{$name} wouldn't allow this post — your account may be missing a permission.",
            self::NotEnabledYet => "Publishing to {$name} isn't switched on yet.",
            self::Unknown => "{$name} rejected this post.",
        };
    }

    /**
     * The one thing to do next.
     */
    public function fix(?Platform $platform = null): string
    {
        return match ($this) {
            self::TokenExpired => 'Reconnect the account, then retry.',
            self::VideoTooLong => 'Trim the video and try again.',
            self::MediaTooLarge => 'Use a smaller file and try again.',
            self::UnsupportedMedia => 'Swap the media for a supported format and try again.',
            self::LinkRejected => 'Remove or change the link, then try again.',
            self::DuplicateContent => 'Change the caption or media, then try again.',
            self::RateLimited => 'Wait a little while, then retry.',
            self::PermissionDenied => 'Reconnect the account to refresh its permissions, then retry.',
            self::NotEnabledYet => 'Nothing to do — we\'ll switch it on soon.',
            self::Unknown => 'Try again, or reconnect the account if it keeps failing.',
        };
    }

    /**
     * Whether a plain "Retry" will plausibly help. A reconnect-first cause (expired token, missing
     * permission) or an un-enabled platform points the user elsewhere instead.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TokenExpired, self::PermissionDenied, self::NotEnabledYet => false,
            default => true,
        };
    }

    /**
     * Whether the fix is to reconnect the account (drives the UI's "Reconnect" affordance).
     */
    public function needsReconnect(): bool
    {
        return in_array($this, [self::TokenExpired, self::PermissionDenied], true);
    }

    /**
     * The frontend/notification payload for one failed channel.
     *
     * @return array{reason: string, message: string, fix: string, retryable: bool, needs_reconnect: bool}
     */
    public function describe(?Platform $platform = null): array
    {
        return [
            'reason' => $this->value,
            'message' => $this->message($platform),
            'fix' => $this->fix($platform),
            'retryable' => $this->isRetryable(),
            'needs_reconnect' => $this->needsReconnect(),
        ];
    }
}
