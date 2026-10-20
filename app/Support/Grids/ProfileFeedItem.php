<?php

namespace App\Support\Grids;

/**
 * Step 18.3 — one post already on the profile. `mediaType` uses Meta's values (`IMAGE`,
 * `CAROUSEL_ALBUM`, `VIDEO`); `thumbnailUrl` is the image the grid shows for it.
 */
final readonly class ProfileFeedItem
{
    public function __construct(
        public string $id,
        public string $mediaType,
        public ?string $thumbnailUrl,
        public ?string $permalink,
        public ?string $publishedAt,
    ) {}

    /**
     * @return array{id: string, media_type: string, thumbnail_url: string|null, permalink: string|null, published_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_type' => $this->mediaType,
            'thumbnail_url' => $this->thumbnailUrl,
            'permalink' => $this->permalink,
            'published_at' => $this->publishedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            mediaType: (string) ($data['media_type'] ?? 'IMAGE'),
            thumbnailUrl: isset($data['thumbnail_url']) ? (string) $data['thumbnail_url'] : null,
            permalink: isset($data['permalink']) ? (string) $data['permalink'] : null,
            publishedAt: isset($data['published_at']) ? (string) $data['published_at'] : null,
        );
    }
}
