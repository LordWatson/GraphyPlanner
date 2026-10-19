<?php

namespace App\Support\Publishing;

/**
 * One format a platform target can take (feed, reel, story, carousel, short, ...), read from
 * `config/platforms.php`. See that file's header for what each field means.
 */
final readonly class FormatSpec
{
    /**
     * @param  list<string>  $media  the media kinds it accepts (`image`, `video`)
     * @param  array{0: float, 1: float}|null  $aspect  accepted width ÷ height range
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $media,
        public int $minItems,
        public int $maxItems,
        public ?array $aspect,
        public ?string $cropTo,
        public ?string $fillScreen,
        public bool $vertical,
        public ?int $minVideoSeconds,
        public ?int $maxVideoSeconds,
        public ?int $maxImageMb,
        public ?int $maxVideoMb,
        public ?string $vendorMediaType,
        public ?string $vendorMediaTypeVideo,
        public string $vendorMediaTypeField,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $aspect = $config['aspect'] ?? null;

        return new self(
            key: $key,
            label: (string) ($config['label'] ?? ucfirst($key)),
            media: array_values(array_map('strval', (array) ($config['media'] ?? []))),
            minItems: (int) ($config['min_items'] ?? 0),
            maxItems: (int) ($config['max_items'] ?? 1),
            aspect: is_array($aspect) && count($aspect) === 2 ? [(float) $aspect[0], (float) $aspect[1]] : null,
            cropTo: isset($config['crop_to']) ? (string) $config['crop_to'] : null,
            fillScreen: isset($config['fill_screen']) ? (string) $config['fill_screen'] : null,
            vertical: (bool) ($config['vertical'] ?? false),
            minVideoSeconds: isset($config['min_video_seconds']) ? (int) $config['min_video_seconds'] : null,
            maxVideoSeconds: isset($config['max_video_seconds']) ? (int) $config['max_video_seconds'] : null,
            maxImageMb: isset($config['max_image_mb']) ? (int) $config['max_image_mb'] : null,
            maxVideoMb: isset($config['max_video_mb']) ? (int) $config['max_video_mb'] : null,
            vendorMediaType: isset($config['vendor_media_type']) ? (string) $config['vendor_media_type'] : null,
            vendorMediaTypeVideo: isset($config['vendor_media_type_video']) ? (string) $config['vendor_media_type_video'] : null,
            vendorMediaTypeField: (string) ($config['vendor_media_type_field'] ?? 'media_type'),
        );
    }

    public function accepts(string $kind): bool
    {
        return in_array($kind, $this->media, true);
    }

    public function requiresMedia(): bool
    {
        return $this->minItems > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'media' => $this->media,
            'min_items' => $this->minItems,
            'max_items' => $this->maxItems,
            'aspect' => $this->aspect,
            'crop_to' => $this->cropTo,
            'fill_screen' => $this->fillScreen,
            'vertical' => $this->vertical,
            'min_video_seconds' => $this->minVideoSeconds,
            'max_video_seconds' => $this->maxVideoSeconds,
            'max_image_mb' => $this->maxImageMb,
            'max_video_mb' => $this->maxVideoMb,
        ];
    }
}
