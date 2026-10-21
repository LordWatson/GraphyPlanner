<?php

namespace App\Support\Publishing;

use Illuminate\Support\Str;

/**
 * Step M-3.1 — what the vendor tells us about a live post when it confirms delivery: the upload
 * request id (needed to fetch its analytics), the network's own post id and the public URL.
 * Every field is optional; blanks and non-http(s) URLs are dropped.
 */
final readonly class VendorPostRef
{
    public ?string $requestId;

    public ?string $platformPostId;

    public ?string $postUrl;

    public function __construct(mixed $requestId = null, mixed $platformPostId = null, mixed $postUrl = null)
    {
        $this->requestId = self::clean($requestId, 128);
        $this->platformPostId = self::clean($platformPostId, 191);

        $url = self::clean($postUrl, 2048);
        $this->postUrl = $url !== null && Str::startsWith($url, ['https://', 'http://']) ? $url : null;
    }

    /**
     * The non-null fields as `post_targets` columns.
     *
     * @return array<string, string>
     */
    public function toColumns(): array
    {
        return array_filter([
            'vendor_request_id' => $this->requestId,
            'platform_post_id' => $this->platformPostId,
            'post_url' => $this->postUrl,
        ], fn (?string $value) => $value !== null);
    }

    private static function clean(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }
}
