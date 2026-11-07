<?php

namespace App\Support\Branding;

use App\Services\Branding\BrandingResolver;

/**
 * Step GTM-3.1 — the resolved branding for one client-facing surface: who the client thinks they
 * are dealing with. Built once per request by {@see BrandingResolver} and
 * passed down, so no template re-derives it and no surface can disagree with another.
 *
 * `isWhiteLabel` false means Alima's own chrome: it is what a plan without `Feature::WhiteLabel`
 * resolves to, what an agency that hasn't set anything up resolves to, and the only state the
 * **product** chrome (the agency's own studio shell) is ever rendered in — brand guidelines §3:
 * Signal Orange and the Alima marks are ours, not a client's.
 */
final class Branding
{
    /** Deep Teal — the accent on every client-facing surface when nobody has overridden it. */
    public const DEFAULT_ACCENT = '#205557';

    public function __construct(
        public readonly bool $isWhiteLabel,
        public readonly string $name,
        public readonly string $accent,
        public readonly ?string $logoUrl = null,
        public readonly ?string $senderName = null,
        public readonly ?string $replyTo = null,
        public readonly ?string $footer = null,
    ) {}

    /**
     * Alima's own chrome.
     */
    public static function alima(): self
    {
        return new self(
            isWhiteLabel: false,
            name: (string) config('app.name'),
            accent: self::DEFAULT_ACCENT,
        );
    }

    /**
     * What a client-facing page needs. Shared with Inertia and embedded in mail views.
     *
     * @return array{is_white_label: bool, name: string, accent: string, logo_url: string|null, footer: string|null}
     */
    public function toArray(): array
    {
        return [
            'is_white_label' => $this->isWhiteLabel,
            'name' => $this->name,
            'accent' => $this->accent,
            'logo_url' => $this->logoUrl,
            'footer' => $this->footer,
        ];
    }
}
