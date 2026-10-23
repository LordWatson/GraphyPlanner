<?php

namespace App\Services\AI;

use App\Models\BrandBrain;
use App\Support\AI\AiCaptionSuggestion;

/**
 * Step M-7.4 (dev plan Step 2.12 "Tone & brand-fit score") — a deterministic 0–100 score for how
 * well a caption suggestion fits the client's Brand Brain, with a one-line reason. A cheap heuristic
 * (no extra vendor call): it rewards the brand's always-use hashtags and content pillars and
 * punishes a phrase the brand says it never uses, so "on-brand" is tangible rather than just
 * trusted. A `weNeverSay` phrase scores low here even though the guardrail already drops such a
 * suggestion before it is shown.
 */
class BrandFitScorer
{
    private const BASE = 62;

    /**
     * @return array{score: int, reason: string}
     */
    public function score(?BrandBrain $brain, AiCaptionSuggestion $suggestion): array
    {
        $caption = $suggestion->caption;
        $haystack = mb_strtolower($caption.' '.implode(' ', $suggestion->hashtags));

        if ($brain === null) {
            // No brand brain to judge against — a neutral, honest middle score.
            return ['score' => 55, 'reason' => 'No Brand Brain yet to score against'];
        }

        $score = self::BASE;
        $reasons = [];

        $hashtagPolicy = $brain->hashtag_policy ?? [];
        $alwaysUse = array_filter(array_map('strval', $hashtagPolicy['always_use'] ?? []));
        $neverUse = array_filter(array_map('strval', $hashtagPolicy['never_use'] ?? []));
        $doNots = array_filter(array_map('strval', ($brain->voice ?? [])['do_nots'] ?? []));
        $pillars = array_filter(array_map('strval', $brain->content_pillars ?? []));

        // Always-use hashtags present (the clearest on-brand signal).
        if ($alwaysUse !== []) {
            $present = array_filter($alwaysUse, fn (string $tag) => str_contains($haystack, mb_strtolower(ltrim($tag, '#'))));

            if ($present !== []) {
                $score += 18;
                $reasons[] = 'Includes your always-use hashtags';
            } else {
                $score -= 8;
                $reasons[] = 'Missing your always-use hashtags';
            }
        }

        // Speaks to a content pillar.
        foreach ($pillars as $pillar) {
            if ($this->mentions($haystack, $pillar)) {
                $score += 15;
                $reasons[] = 'On pillar: '.$pillar;
                break;
            }
        }

        // A phrase the brand never uses — the strongest negative (and already guardrail-dropped).
        foreach ([...$neverUse, ...$doNots] as $banned) {
            if (str_contains($haystack, mb_strtolower($banned))) {
                $score -= 45;
                $reasons = ['Uses a phrase the brand avoids: '.$banned];
                break;
            }
        }

        // A caption with no substance reads as filler.
        if (mb_strlen(trim($caption)) < 20) {
            $score -= 6;
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'reason' => $reasons === [] ? 'In the brand voice' : $reasons[0],
        ];
    }

    /**
     * A loose keyword match: does the text mention a meaningful word from the pillar phrase?
     */
    private function mentions(string $haystack, string $pillar): bool
    {
        foreach (preg_split('/\s+/', mb_strtolower(trim($pillar))) ?: [] as $word) {
            if (mb_strlen($word) >= 4 && str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }
}
