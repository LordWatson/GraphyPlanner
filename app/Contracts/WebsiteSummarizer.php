<?php

namespace App\Contracts;

/**
 * Step B-2.4 — fetches a public web page and returns a plain-text, length-capped summary of its
 * readable content, for the brand-profile AI quick-start to reason over. Kept behind a contract so
 * the SSRF-guarded HTTP implementation can be swapped (or faked in tests) without touching the
 * Action. Implementations must never follow a URL to a private/loopback/link-local address, must
 * cap the download size and time, and must return `null` (never throw) on any failure, so a bad or
 * hostile URL degrades the quick-start to an empty draft rather than breaking onboarding.
 */
interface WebsiteSummarizer
{
    /**
     * Readable text extracted from the page at `$url`, or `null` when it can't be fetched safely.
     */
    public function summarize(string $url): ?string;
}
