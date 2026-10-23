<?php

namespace App\Support\Push;

/**
 * Step B-7.4 — an immutable push payload. `toArray()` is the JSON the service worker's `push`
 * handler reads (title/body/url/tag/icon). `tag` lets the browser collapse repeats of the same
 * reminder; `url` is where a tap lands.
 */
final class PushMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url = '/today',
        public readonly ?string $tag = null,
        public readonly ?string $icon = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
            'icon' => $this->icon,
        ], fn ($value) => $value !== null);
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray());
    }
}
