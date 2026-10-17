<?php

namespace App\Support\AI\Images;

use App\Enums\ReferenceImageRole;

/**
 * One already-normalised reference image (≤1024px, Step 16.2) handed to an image provider.
 */
final readonly class ReferenceImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
        public ReferenceImageRole $role,
    ) {}
}
