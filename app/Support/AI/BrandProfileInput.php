<?php

namespace App\Support\AI;

/**
 * Step B-2.4 — the raw facts a business owner gives the AI quick-start: their brand name, a
 * one-line "what you do", where they are, and (optionally) the readable text already fetched from
 * their website or pulled from a social bio. The website is summarised *before* this DTO is built
 * (through `App\Contracts\WebsiteSummarizer`, SSRF-guarded), so the provider only ever sees plain
 * text — never a URL it might fetch itself.
 */
final class BrandProfileInput
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $oneLiner = null,
        public readonly ?string $location = null,
        public readonly ?string $websiteText = null,
        public readonly ?string $bioText = null,
    ) {}
}
