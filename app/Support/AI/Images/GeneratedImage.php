<?php

namespace App\Support\AI\Images;

/**
 * One image returned by an App\Contracts\ImageProvider (Step 16.1). `bytes` never leaves the
 * backend: it is stored via AssetStorage::storeContents() and only ever served as a URL.
 */
final readonly class GeneratedImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
        public int $width,
        public int $height,
        public string $provider,
        public string $model,
        public ?string $providerRequestId = null,
        public float $costEstimate = 0.0,
    ) {}

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }
}
