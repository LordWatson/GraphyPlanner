<?php

namespace App\Support\AI\Images;

use JsonSerializable;

/**
 * The structured art direction for one image set (Step 16.1): written once per set by the
 * AiProvider "art director" (DeepSeek, Step 16.5) and reused by every variant ("brief once,
 * render many"). Stored on `ai_image_generations.brief` via toArray()/fromArray().
 */
final readonly class AiImageBrief implements JsonSerializable
{
    /**
     * @param  array<int, array{name: string, hex: string|null}>  $palette  named brand colours
     * @param  array<int, string>  $avoid
     * @param  array<int, int>  $referenceAssetIds
     */
    public function __construct(
        public string $subject,
        public string $composition = '',
        public string $mood = '',
        public array $palette = [],
        public string $style = '',
        public ?string $textOverlay = null,
        public array $avoid = [],
        public array $referenceAssetIds = [],
    ) {}

    /**
     * A copy with the given fields replaced (used for art-direction overrides and guardrails).
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray([...$this->toArray(), ...$changes]);
    }

    /**
     * @return array{subject: string, composition: string, mood: string, palette: array<int, array{name: string, hex: string|null}>, style: string, textOverlay: string|null, avoid: array<int, string>, referenceAssetIds: array<int, int>}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'composition' => $this->composition,
            'mood' => $this->mood,
            'palette' => $this->palette,
            'style' => $this->style,
            'textOverlay' => $this->textOverlay,
            'avoid' => $this->avoid,
            'referenceAssetIds' => $this->referenceAssetIds,
        ];
    }

    /**
     * Tolerant of partial/odd input (cached payloads, vendor JSON): unknown keys are ignored and
     * every field is coerced to its declared type.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $palette = [];

        foreach (is_array($data['palette'] ?? null) ? $data['palette'] : [] as $colour) {
            if (is_string($colour) && trim($colour) !== '') {
                $palette[] = self::parseColour($colour);
            } elseif (is_array($colour) && is_string($colour['name'] ?? null) && trim($colour['name']) !== '') {
                $hex = is_string($colour['hex'] ?? null) ? self::normaliseHex($colour['hex']) : null;
                $palette[] = ['name' => trim($colour['name']), 'hex' => $hex];
            }
        }

        $textOverlay = is_string($data['textOverlay'] ?? null) ? trim($data['textOverlay']) : null;

        return new self(
            subject: trim((string) (is_scalar($data['subject'] ?? null) ? $data['subject'] : '')),
            composition: trim((string) (is_scalar($data['composition'] ?? null) ? $data['composition'] : '')),
            mood: trim((string) (is_scalar($data['mood'] ?? null) ? $data['mood'] : '')),
            palette: $palette,
            style: trim((string) (is_scalar($data['style'] ?? null) ? $data['style'] : '')),
            textOverlay: $textOverlay === '' ? null : $textOverlay,
            avoid: array_values(array_filter(array_map(
                fn ($item) => is_scalar($item) ? trim((string) $item) : '',
                is_array($data['avoid'] ?? null) ? $data['avoid'] : [],
            ))),
            referenceAssetIds: array_values(array_map('intval', array_filter(
                is_array($data['referenceAssetIds'] ?? null) ? $data['referenceAssetIds'] : [],
                'is_numeric',
            ))),
        );
    }

    /**
     * "Teal #205557" / "#205557" / "teal" → a named colour with an optional hex.
     *
     * @return array{name: string, hex: string|null}
     */
    public static function parseColour(string $colour): array
    {
        $colour = trim($colour);

        if (preg_match('/#?\b([0-9a-f]{6}|[0-9a-f]{3})\b/i', $colour, $matches) === 1) {
            $name = trim((string) preg_replace('/#?\b'.preg_quote($matches[1], '/').'\b/i', '', $colour), " \t-–:()");

            return ['name' => $name !== '' ? $name : '#'.strtoupper($matches[1]), 'hex' => self::normaliseHex($matches[1])];
        }

        return ['name' => $colour, 'hex' => null];
    }

    private static function normaliseHex(string $hex): ?string
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-f]{3}$/i', $hex) === 1) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return preg_match('/^[0-9a-f]{6}$/i', $hex) === 1 ? '#'.strtoupper($hex) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
