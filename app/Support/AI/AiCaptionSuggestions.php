<?php

namespace App\Support\AI;

use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use Countable;
use ArrayIterator;
use JsonSerializable;

/**
 * @implements IteratorAggregate<int, AiCaptionSuggestion>
 */
class AiCaptionSuggestions implements Arrayable, IteratorAggregate, Countable, JsonSerializable
{
    /** @param array<int, AiCaptionSuggestion> $items */
    public function __construct(public readonly array $items = []) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** @return array<int, AiCaptionSuggestion> */
    public function all(): array
    {
        return $this->items;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (AiCaptionSuggestion $s) => $s->toArray(), $this->items);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
