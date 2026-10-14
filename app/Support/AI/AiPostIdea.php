<?php

namespace App\Support\AI;

use App\Enums\Platform;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class AiPostIdea implements Arrayable, JsonSerializable
{
    /**
     * @param array<int, Platform> $platforms
     * @param array<int, string> $tags
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly array $platforms = [],
        public readonly array $tags = [],
    ) {
    }

    /** @return array{title: string, description: ?string, platforms: array<int, string>, tags: array<int, string>} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'platforms' => array_map(fn (Platform $p) => $p->value, $this->platforms),
            'tags' => array_values($this->tags),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
