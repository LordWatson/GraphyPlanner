<?php

namespace App\Support\Grids;

/**
 * Step 18.3 — a profile's recent feed as the grid preview reads it. `source` says where it came
 * from: `meta` (the Graph API, the whole profile), `app` (only posts sent from Alima) or `none`.
 * Carries no token and nothing the client mustn't see.
 */
final readonly class ProfileFeed
{
    public const SOURCE_META = 'meta';

    public const SOURCE_APP = 'app';

    public const SOURCE_NONE = 'none';

    /**
     * @param  list<ProfileFeedItem>  $items  newest first
     */
    public function __construct(
        public string $username,
        public ?string $profilePictureUrl,
        public ?int $mediaCount,
        public array $items,
        public string $source,
    ) {}

    public static function none(string $username): self
    {
        return new self($username, null, null, [], self::SOURCE_NONE);
    }

    /**
     * @return array{username: string, profile_picture_url: string|null, media_count: int|null, source: string, items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'profile_picture_url' => $this->profilePictureUrl,
            'media_count' => $this->mediaCount,
            'source' => $this->source,
            'items' => array_map(fn (ProfileFeedItem $item) => $item->toArray(), $this->items),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            username: (string) ($data['username'] ?? ''),
            profilePictureUrl: isset($data['profile_picture_url']) ? (string) $data['profile_picture_url'] : null,
            mediaCount: isset($data['media_count']) ? (int) $data['media_count'] : null,
            items: array_values(array_map(fn (array $item) => ProfileFeedItem::fromArray($item), (array) ($data['items'] ?? []))),
            source: (string) ($data['source'] ?? self::SOURCE_NONE),
        );
    }
}
