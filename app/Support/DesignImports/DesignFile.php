<?php

namespace App\Support\DesignImports;

/**
 * Step M-5.9 — one importable file listed from a design/cloud source, as shown in the picker.
 */
final readonly class DesignFile
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $mimeType = null,
        public ?string $thumbnailUrl = null,
        public ?int $size = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'thumbnail_url' => $this->thumbnailUrl,
            'size' => $this->size,
        ];
    }
}
