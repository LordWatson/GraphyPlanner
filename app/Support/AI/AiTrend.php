<?php

namespace App\Support\AI;

use App\Enums\Platform;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class AiTrend implements Arrayable, JsonSerializable
{
    /**
     * @param array<int, Platform> $platforms
     */
    public function __construct(
        public readonly string $topic,
        public readonly ?string $description = null,
        public readonly array $platforms = [],
        public readonly ?float $confidence = null, // 0..1 optional
    ) {
    }

    /** @return array{topic: string, description: ?string, platforms: array<int, string>, confidence: ?float} */
    public function toArray(): array
    {
        return [
            'topic' => $this->topic,
            'description' => $this->description,
            'platforms' => array_map(fn (Platform $p) => $p->value, $this->platforms),
            'confidence' => $this->confidence,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
