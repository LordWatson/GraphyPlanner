<?php

namespace App\Support\AI;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class AiCaptionSuggestion implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $caption,
        /** @var array<int, string> */
        public readonly array $hashtags = [],
        public readonly ?string $altText = null,
        public readonly ?string $firstComment = null,
    ) {
    }

    /** @return array{caption: string, hashtags: array<int, string>, altText: ?string, firstComment: ?string} */
    public function toArray(): array
    {
        return [
            'caption' => $this->caption,
            'hashtags' => array_values($this->hashtags),
            'altText' => $this->altText,
            'firstComment' => $this->firstComment,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
