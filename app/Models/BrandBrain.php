<?php

namespace App\Models;

use Database\Factories\BrandBrainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property array{tone: ?string, personality: ?string, do_nots: array<int, string>}|null $voice
 * @property array{description: ?string, demographics: ?string, pain_points: array<int, string>}|null $audience
 * @property array{value_proposition: ?string, key_products: array<int, string>, pricing_notes: ?string}|null $offer
 * @property array{color_palette: array<int, string>, typography: ?string, imagery_style: ?string, logo_usage_notes: ?string}|null $visual
 * @property array{allowed_genres: array<int, string>, disallowed_genres: array<int, string>, notes: ?string}|null $music_policy
 * @property array{always_use: array<int, string>, never_use: array<int, string>, rotation_notes: ?string}|null $hashtag_policy
 * @property array<int, string>|null $content_pillars
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'client_id', 'voice', 'audience', 'offer', 'visual', 'music_policy', 'hashtag_policy', 'content_pillars',
])]
class BrandBrain extends Model
{
    /** @use HasFactory<BrandBrainFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voice' => 'array',
            'audience' => 'array',
            'offer' => 'array',
            'visual' => 'array',
            'music_policy' => 'array',
            'hashtag_policy' => 'array',
            'content_pillars' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Render the brand brain as a Grok-ready context payload: a human-readable markdown
     * briefing plus the raw structured JSON, so Phase 2 caption assistance can consume either.
     *
     * @return array{markdown: string, json: array<string, mixed>}
     */
    public function toGrokContext(): array
    {
        $voice = $this->voice ?? [];
        $audience = $this->audience ?? [];
        $offer = $this->offer ?? [];
        $visual = $this->visual ?? [];
        $musicPolicy = $this->music_policy ?? [];
        $hashtagPolicy = $this->hashtag_policy ?? [];
        $contentPillars = $this->content_pillars ?? [];

        $lines = [
            '# Brand Brain: '.($this->client?->name ?? 'Unknown client'),
            '',
            '## Voice',
            '- Tone: '.($voice['tone'] ?? '—'),
            '- Personality: '.($voice['personality'] ?? '—'),
            '- Do not: '.$this->listOrDash($voice['do_nots'] ?? []),
            '',
            '## Audience',
            '- Description: '.($audience['description'] ?? '—'),
            '- Demographics: '.($audience['demographics'] ?? '—'),
            '- Pain points: '.$this->listOrDash($audience['pain_points'] ?? []),
            '',
            '## Offer',
            '- Value proposition: '.($offer['value_proposition'] ?? '—'),
            '- Key products/services: '.$this->listOrDash($offer['key_products'] ?? []),
            '- Pricing notes: '.($offer['pricing_notes'] ?? '—'),
            '',
            '## Visual',
            '- Color palette: '.$this->listOrDash($visual['color_palette'] ?? []),
            '- Typography: '.($visual['typography'] ?? '—'),
            '- Imagery style: '.($visual['imagery_style'] ?? '—'),
            '- Logo usage notes: '.($visual['logo_usage_notes'] ?? '—'),
            '',
            '## Music policy',
            '- Allowed genres: '.$this->listOrDash($musicPolicy['allowed_genres'] ?? []),
            '- Disallowed genres: '.$this->listOrDash($musicPolicy['disallowed_genres'] ?? []),
            '- Notes: '.($musicPolicy['notes'] ?? '—'),
            '',
            '## Hashtag policy',
            '- Always use: '.$this->listOrDash($hashtagPolicy['always_use'] ?? []),
            '- Never use: '.$this->listOrDash($hashtagPolicy['never_use'] ?? []),
            '- Rotation notes: '.($hashtagPolicy['rotation_notes'] ?? '—'),
            '',
            '## Content pillars',
            $this->listOrDash($contentPillars),
        ];

        return [
            'markdown' => implode("\n", $lines),
            'json' => [
                'voice' => $voice,
                'audience' => $audience,
                'offer' => $offer,
                'visual' => $visual,
                'music_policy' => $musicPolicy,
                'hashtag_policy' => $hashtagPolicy,
                'content_pillars' => $contentPillars,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $items
     */
    private function listOrDash(array $items): string
    {
        return $items === [] ? '—' : implode(', ', $items);
    }
}
