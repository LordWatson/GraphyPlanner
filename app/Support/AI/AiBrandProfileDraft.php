<?php

namespace App\Support\AI;

use Illuminate\Support\Arr;

/**
 * Step B-2.4 — a suggested Brand Brain the owner can accept or edit in the onboarding quick-start.
 * It mirrors the slices of `BrandBrain` the quick-start fills (voice, audience, offer, content
 * pillars), never the whole model. Nothing here is persisted until the owner accepts — acceptance
 * goes through `UpdateBrandBrainAction`.
 *
 * `succeeded` records whether a real provider produced this draft: the Null provider (AI off) and a
 * failed vendor call both return `empty()`, which the wizard shows as a friendly "fill it in
 * yourself" fallback rather than a blocked step.
 */
final class AiBrandProfileDraft
{
    /**
     * @param  array{tone: ?string, personality: ?string, do_nots: list<string>}  $voice
     * @param  array{description: ?string, demographics: ?string, pain_points: list<string>}  $audience
     * @param  array{value_proposition: ?string, key_products: list<string>, pricing_notes: ?string}  $offer
     * @param  list<string>  $contentPillars
     */
    public function __construct(
        public readonly array $voice,
        public readonly array $audience,
        public readonly array $offer,
        public readonly array $contentPillars,
        public readonly bool $succeeded = false,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
    ) {}

    public static function empty(): self
    {
        return new self(
            voice: ['tone' => null, 'personality' => null, 'do_nots' => []],
            audience: ['description' => null, 'demographics' => null, 'pain_points' => []],
            offer: ['value_proposition' => null, 'key_products' => [], 'pricing_notes' => null],
            contentPillars: [],
            succeeded: false,
        );
    }

    /**
     * Build from a loosely-typed vendor payload, coercing every field so a malformed model response
     * can never produce an invalid draft (missing keys become null/empty, scalars become strings).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $succeeded = true, ?string $provider = null, ?string $model = null): self
    {
        $voice = is_array($data['voice'] ?? null) ? $data['voice'] : [];
        $audience = is_array($data['audience'] ?? null) ? $data['audience'] : [];
        $offer = is_array($data['offer'] ?? null) ? $data['offer'] : [];

        return new self(
            voice: [
                'tone' => self::str($voice['tone'] ?? null),
                'personality' => self::str($voice['personality'] ?? null),
                'do_nots' => self::list($voice['do_nots'] ?? []),
            ],
            audience: [
                'description' => self::str($audience['description'] ?? null),
                'demographics' => self::str($audience['demographics'] ?? null),
                'pain_points' => self::list($audience['pain_points'] ?? []),
            ],
            offer: [
                'value_proposition' => self::str($offer['value_proposition'] ?? null),
                'key_products' => self::list($offer['key_products'] ?? []),
                'pricing_notes' => self::str($offer['pricing_notes'] ?? null),
            ],
            contentPillars: self::list($data['content_pillars'] ?? []),
            succeeded: $succeeded,
            provider: $provider,
            model: $model,
        );
    }

    /**
     * Whether every field is blank — true for the Null provider or a failed call.
     */
    public function isEmpty(): bool
    {
        return $this->voice['tone'] === null
            && $this->voice['personality'] === null
            && $this->voice['do_nots'] === []
            && $this->audience['description'] === null
            && $this->audience['demographics'] === null
            && $this->audience['pain_points'] === []
            && $this->offer['value_proposition'] === null
            && $this->offer['key_products'] === []
            && $this->offer['pricing_notes'] === null
            && $this->contentPillars === [];
    }

    /**
     * Shaped for the onboarding wizard's editable form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'voice' => $this->voice,
            'audience' => $this->audience,
            'offer' => $this->offer,
            'content_pillars' => $this->contentPillars,
            'succeeded' => $this->succeeded,
        ];
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) || is_numeric($item) ? trim((string) $item) : '',
            Arr::flatten($value),
        ), fn (string $item) => $item !== ''));
    }
}
