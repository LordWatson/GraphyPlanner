<?php

namespace App\Enums;

use App\Support\AI\Images\BrandImageBriefFactory;
use App\Support\AI\Images\BusinessImagePresetComposer;
use App\Support\AI\Images\GenerateImageSetData;

/**
 * Step B-6.5 — one-tap Image Studio presets for a small business/creator. Each preset is a starting
 * point that turns a single line of text (a special, the hours, an offer, a quote) into art
 * direction for the shared Image Studio engine (Phase 16). The preset decides the subject,
 * composition and — for the typographic cards — the style; the brand's palette and typography are
 * always layered on by {@see BrandImageBriefFactory}, never invented here.
 *
 * The actual {@see GenerateImageSetData} is assembled by
 * {@see BusinessImagePresetComposer} (unit tested).
 */
enum BusinessImagePreset: string
{
    case TodaysSpecial = 'todays_special';
    case OpeningHours = 'opening_hours';
    case SaleAnnouncement = 'sale_announcement';
    case QuoteCard = 'quote_card';

    public function label(): string
    {
        return match ($this) {
            self::TodaysSpecial => "Today's special",
            self::OpeningHours => 'Opening hours',
            self::SaleAnnouncement => 'Sale announcement',
            self::QuoteCard => 'Quote card',
        };
    }

    /** One short line under the preset's name on its card. */
    public function tagline(): string
    {
        return match ($this) {
            self::TodaysSpecial => "Show off what's on today.",
            self::OpeningHours => 'A clean card with your hours.',
            self::SaleAnnouncement => 'Make an offer impossible to miss.',
            self::QuoteCard => 'A shareable line in your style.',
        };
    }

    /** The label on the single text field in the preset's dialog. */
    public function fieldLabel(): string
    {
        return match ($this) {
            self::TodaysSpecial => "What's the special?",
            self::OpeningHours => 'Your opening hours',
            self::SaleAnnouncement => "What's the offer?",
            self::QuoteCard => 'The quote',
        };
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::TodaysSpecial => 'e.g. Sourdough & sea-salt caramel buns',
            self::OpeningHours => 'e.g. Mon–Sat · 8am–4pm',
            self::SaleAnnouncement => 'e.g. 20% off all cakes this weekend',
            self::QuoteCard => 'e.g. Good bread takes time.',
        };
    }

    /**
     * The feed-square format suits every preset by default; the person can still change it in the
     * dialog (Story, Portrait, …).
     */
    public function defaultFormat(): ImageAspectRatio
    {
        return ImageAspectRatio::Square_1_1;
    }

    /**
     * The preset's art-direction overrides for the given brand and text. Only `subject` and
     * `composition` are set for the photographic preset, so the brand's own imagery style is kept;
     * the typographic cards override `style` too (a text card must not be rendered as a photo).
     *
     * @return array{subject: string, composition: string, style?: string, mood?: string}
     */
    public function artDirection(string $brandName, string $text): array
    {
        $brand = trim($brandName) !== '' ? trim($brandName) : 'the brand';

        return match ($this) {
            self::TodaysSpecial => [
                'subject' => "A mouth-watering photograph of {$text} — today's special at {$brand} — freshly served and styled for a social post.",
                'composition' => 'The item as a single clear hero, shot close and appetising, with calm empty space near the top for a short headline.',
            ],
            self::OpeningHours => [
                'subject' => "A clean, modern \"opening hours\" card for {$brand}.",
                'composition' => 'A centred typographic layout with generous margins and clear space for the hours as the headline.',
                'style' => 'Minimal flat graphic design, bold legible type and plenty of negative space — a designed card, not a photograph.',
            ],
            self::SaleAnnouncement => [
                'subject' => "An eye-catching sale-announcement graphic for {$brand} promoting: {$text}.",
                'composition' => 'A bold, high-impact layout built around one large headline, with room beneath for a short call to action.',
                'style' => 'Vibrant, high-energy promotional graphic design with strong contrast — a designed card, not a photograph.',
                'mood' => 'Exciting and upbeat',
            ],
            self::QuoteCard => [
                'subject' => "A minimal, shareable quote card for {$brand}.",
                'composition' => 'The quote centred as the hero in large type, framed by generous negative space.',
                'style' => 'Elegant minimal typographic design, calm and refined — a designed card, not a photograph.',
            ],
        };
    }
}
