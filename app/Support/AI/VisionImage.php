<?php

namespace App\Support\AI;

use App\Contracts\VisionProvider;

/**
 * Step B-6.2 — the image handed to a {@see VisionProvider} to caption. A tiny
 * immutable value object (raw bytes + MIME type) so the contract doesn't couple to the Image Studio
 * DTOs or to how the bytes were loaded (an uploaded file, a stored Asset).
 */
final readonly class VisionImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {}

    /**
     * The MIME types a vision model can read. A non-image asset (a video) never reaches the provider.
     */
    public function isSupported(): bool
    {
        return in_array(strtolower($this->mimeType), ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/heif'], true);
    }
}
