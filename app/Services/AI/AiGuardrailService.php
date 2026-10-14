<?php

namespace App\Services\AI;

use App\Enums\Platform;
use App\Models\BrandBrain;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Support\Facades\Log;

/**
 * Post-generation safety net for every AI surface (Step 2.6): strips any suggestion/idea/trend
 * that mentions a banned phrase (brand brain "never say"/"never use" lists, plus the global
 * `config('ai.guardrails.prohibited_topics')` list) before it's ever returned to a controller,
 * scrubs obvious PII/secrets out of free text before it's logged or sent back to the user, and
 * flags (never silently rewrites) platform policy issues like missing-link-tolerance or
 * hashtag-count overages so the UI can show a clear warning instead.
 */
class AiGuardrailService
{
    /**
     * @return AiCaptionSuggestions filtered copy with any banned-phrase suggestion dropped and
     *                               free-text fields scrubbed of obvious PII/secrets
     */
    public function filterCaptionSuggestions(?BrandBrain $brandBrain, AiCaptionSuggestions $suggestions, array $context = []): AiCaptionSuggestions
    {
        $banned = $this->bannedPhrases($brandBrain);

        $filtered = collect($suggestions->suggestions)
            ->filter(function (AiCaptionSuggestion $suggestion) use ($banned, $context) {
                $haystack = implode(' ', array_filter([
                    $suggestion->caption,
                    implode(' ', $suggestion->hashtags),
                    $suggestion->altText,
                    $suggestion->firstComment,
                ]));

                return $this->passesGuardrail($haystack, $banned, 'caption suggestion', $context);
            })
            ->map(fn (AiCaptionSuggestion $suggestion): AiCaptionSuggestion => new AiCaptionSuggestion(
                caption: $this->scrub($suggestion->caption),
                hashtags: $suggestion->hashtags,
                altText: $suggestion->altText !== null ? $this->scrub($suggestion->altText) : null,
                firstComment: $suggestion->firstComment !== null ? $this->scrub($suggestion->firstComment) : null,
            ))
            ->values()
            ->all();

        return new AiCaptionSuggestions($filtered);
    }

    /**
     * @return AiPostIdeas filtered copy with any banned-phrase idea dropped and free-text fields
     *                     scrubbed of obvious PII/secrets
     */
    public function filterPostIdeas(?BrandBrain $brandBrain, AiPostIdeas $ideas, array $context = []): AiPostIdeas
    {
        $banned = $this->bannedPhrases($brandBrain);

        $filtered = collect($ideas->ideas)
            ->filter(function (AiPostIdea $idea) use ($banned, $context) {
                $haystack = implode(' ', array_filter([$idea->title, $idea->description, $idea->suggestedCaption]));

                return $this->passesGuardrail($haystack, $banned, 'post idea', $context);
            })
            ->map(fn (AiPostIdea $idea): AiPostIdea => new AiPostIdea(
                title: $this->scrub($idea->title),
                description: $this->scrub($idea->description),
                platforms: $idea->platforms,
                suggestedCaption: $idea->suggestedCaption !== null ? $this->scrub($idea->suggestedCaption) : null,
                suggestedPostAt: $idea->suggestedPostAt,
            ))
            ->values()
            ->all();

        return new AiPostIdeas($filtered);
    }

    /**
     * @return AiTrends filtered copy with any banned-phrase trend dropped and free-text fields
     *                   scrubbed of obvious PII/secrets
     */
    public function filterTrends(?BrandBrain $brandBrain, AiTrends $trends, array $context = []): AiTrends
    {
        $banned = $this->bannedPhrases($brandBrain);

        $filtered = collect($trends->trends)
            ->filter(function (AiTrend $trend) use ($banned, $context) {
                $haystack = implode(' ', array_filter([$trend->title, $trend->summary]));

                return $this->passesGuardrail($haystack, $banned, 'trend', $context);
            })
            ->map(fn (AiTrend $trend): AiTrend => new AiTrend(
                title: $this->scrub($trend->title),
                summary: $this->scrub($trend->summary),
                platforms: $trend->platforms,
                confidence: $trend->confidence,
            ))
            ->values()
            ->all();

        return new AiTrends($filtered);
    }

    /**
     * Platform policy checks applied post-generation (Step 2.6): returns human-readable warnings
     * rather than silently editing the suggestion, so the UI can surface them next to the
     * "Apply" action and let a human decide.
     *
     * @return array<int, string>
     */
    public function platformWarnings(Platform $platform, AiCaptionSuggestion $suggestion): array
    {
        $warnings = [];

        if (! $platform->allowsLinksInCaption() && preg_match('/https?:\/\/\S+/i', $suggestion->caption) === 1) {
            $warnings[] = "{$platform->label()} captions generally bury/penalize inline links — consider moving the link to the bio/profile instead.";
        }

        if (count($suggestion->hashtags) > $platform->maxHashtags()) {
            $warnings[] = "{$platform->label()} works best with up to {$platform->maxHashtags()} hashtags — this suggestion has ".count($suggestion->hashtags).'.';
        }

        if (mb_strlen($suggestion->caption) > $platform->captionLimit()) {
            $warnings[] = "Caption exceeds {$platform->label()}'s {$platform->captionLimit()}-character limit.";
        }

        return $warnings;
    }

    /**
     * Redact obvious PII/secrets (emails, phone numbers, bearer-style tokens/API keys, credit
     * card-looking numbers) from free text before it's logged or surfaced back to a user, per
     * Step 2.6: "PII/secrets scrubbed from prompts and logs; redact any incidentally returned
     * secrets."
     */
    public function scrub(string $text): string
    {
        $patterns = [
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[redacted-email]',
            '/\b(?:sk|pk|api|token|bearer)[_-][A-Za-z0-9]{10,}\b/i' => '[redacted-secret]',
            '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/' => '[redacted-card]',
            '/\b(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/' => '[redacted-phone]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    private function bannedPhrases(?BrandBrain $brandBrain): array
    {
        $fromBrandBrain = $brandBrain === null ? [] : array_merge(
            $brandBrain->voice['do_nots'] ?? [],
            $brandBrain->hashtag_policy['never_use'] ?? [],
        );

        $fromConfig = (array) config('ai.guardrails.prohibited_topics', []);

        return collect([...$fromBrandBrain, ...$fromConfig])
            ->filter(fn ($phrase) => is_string($phrase) && trim($phrase) !== '')
            ->map(fn (string $phrase) => trim($phrase))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $bannedPhrases
     * @param  array<string, mixed>  $context
     */
    private function passesGuardrail(string $haystack, array $bannedPhrases, string $kind, array $context): bool
    {
        $matched = $this->matchedBannedPhrase($haystack, $bannedPhrases);

        if ($matched === null) {
            return true;
        }

        Log::warning("AI guardrail rejected a {$kind} for a banned phrase", [...$context, 'phrase' => $matched]);

        return false;
    }

    /**
     * @param  array<int, string>  $bannedPhrases
     */
    private function matchedBannedPhrase(string $haystack, array $bannedPhrases): ?string
    {
        foreach ($bannedPhrases as $phrase) {
            if (mb_stripos($haystack, $phrase) !== false) {
                return $phrase;
            }
        }

        return null;
    }
}
