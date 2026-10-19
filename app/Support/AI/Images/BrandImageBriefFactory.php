<?php

namespace App\Support\AI\Images;

use App\Enums\ReferenceImageRole;
use App\Models\BrandBrain;
use App\Models\Client;
use Illuminate\Support\Str;

/**
 * Builds image briefs straight from the Brand Brain, with no AI call (Step 16.5):
 * - defaults(): the deterministic brief NullAiProvider returns, DeepSeek falls back to, and the
 *   studio pre-fills its art-direction panel with before anything is generated;
 * - finalize(): applies what must never be left to a model on top of any drafted brief: the
 *   person's own overrides, excluded colours, the headline switch, the brand's real reference
 *   images, and the brand's visual do-nots.
 */
class BrandImageBriefFactory
{
    /**
     * Mood, style and composition are short descriptors; the subject is deliberately uncapped so a
     * post's whole caption can steer the image.
     */
    private const FIELD_LIMIT = 400;

    public function defaults(Client $client, ImageBriefInput $input): AiImageBrief
    {
        $brandBrain = $client->brandBrain;
        $visual = $brandBrain->visual ?? [];
        $voice = $brandBrain->voice ?? [];

        // An idea is described by its title + description; a post by its whole caption (its title
        // is only the caption's first line, cut short).
        $subject = match (true) {
            filled($input->description) => trim($input->title.'. '.$input->description),
            filled($input->caption) => trim((string) $input->caption),
            default => trim($input->title),
        };

        $style = filled($visual['imagery_style'] ?? null)
            ? (string) $visual['imagery_style']
            : 'Natural-light lifestyle photography, candid and warm';

        if (filled($visual['typography'] ?? null) && $input->withHeadline) {
            $style .= '. Headline typography: '.$visual['typography'];
        }

        $brief = new AiImageBrief(
            subject: $this->stripHashtags($subject),
            composition: 'One clear focal point, uncluttered background, with calm space for a caption',
            mood: filled($voice['tone'] ?? null) ? (string) $voice['tone'] : 'Warm, optimistic and inviting',
            palette: $this->palette($brandBrain),
            style: $style,
        );

        return $this->finalize($client, $brief, $input);
    }

    public function finalize(Client $client, AiImageBrief $brief, ImageBriefInput $input): AiImageBrief
    {
        $brandBrain = $client->brandBrain;
        $overrides = $input->overrides;
        $changes = [];

        foreach (['subject', 'mood', 'style', 'composition'] as $field) {
            $value = $overrides[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $value = $this->stripHashtags(trim($value));
                $changes[$field] = $field === 'subject' ? $value : Str::limit($value, self::FIELD_LIMIT, '');
            }
        }

        // The palette always comes from the Brand Brain (never invented by a model), minus any
        // colour the person switched off in the studio.
        $excluded = array_map('mb_strtolower', array_map('strval', $overrides['excludedColours'] ?? []));
        $changes['palette'] = array_values(array_filter(
            $this->palette($brandBrain),
            fn (array $colour): bool => ! in_array(mb_strtolower($colour['name']), $excluded, true)
                && ! in_array(mb_strtolower((string) $colour['hex']), $excluded, true),
        ));

        $changes['textOverlay'] = $input->withHeadline
            ? Str::limit(trim((string) ($input->headline ?: $input->title)), 80, '')
            : null;

        $references = $brandBrain->referenceAssets ?? collect();
        $changes['referenceAssetIds'] = $references->pluck('asset_id')->map(fn ($id) => (int) $id)->values()->all();

        $hasLogo = $references->contains(fn ($reference) => $reference->role === ReferenceImageRole::Logo);
        $logoNotes = $brandBrain?->visual['logo_usage_notes'] ?? null;
        $style = $changes['style'] ?? $brief->style;

        if ($hasLogo && filled($logoNotes) && ! str_contains($style, (string) $logoNotes)) {
            $changes['style'] = trim($style.'. Logo usage: '.$logoNotes);
        }

        $changes['avoid'] = array_values(array_unique([
            ...$brief->avoid,
            ...array_map('strval', $brandBrain?->voice['do_nots'] ?? []),
        ]));

        return $brief->with($changes);
    }

    /**
     * @return array<int, array{name: string, hex: string|null}>
     */
    public function palette(?BrandBrain $brandBrain): array
    {
        return collect($brandBrain?->visual['color_palette'] ?? [])
            ->filter(fn (string $colour) => trim($colour) !== '')
            ->map(fn (string $colour): array => AiImageBrief::parseColour($colour))
            ->values()
            ->all();
    }

    /**
     * Hashtags have no visual value and must never steer an image (Step 16.5).
     */
    public function stripHashtags(string $text): string
    {
        $text = (string) preg_replace('/(^|\s)#[\p{L}\p{N}_]+/u', '$1', $text);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $text));
    }
}
