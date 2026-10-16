<?php

namespace Tests\Unit\Services\AI;

use App\Enums\Platform;
use App\Models\BrandBrain;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Step 2.6 — regression coverage ensuring banned phrases (brand brain "never say"/"never use"
 * lists plus the global prohibited-topics list) never pass through to the user on any of the
 * three AI surfaces, that platform policy issues are flagged rather than silently rewritten,
 * and that obvious PII/secrets are scrubbed from free text.
 */
class AiGuardrailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_caption_suggestions_mentioning_a_brand_brain_do_not_say_are_rejected(): void
    {
        $brandBrain = BrandBrain::factory()->make(); // do_nots: ['Avoid slang', 'No profanity']

        $suggestions = new AiCaptionSuggestions([
            new AiCaptionSuggestion(caption: 'Check out our new drop, no profanity needed!'),
            new AiCaptionSuggestion(caption: 'A perfectly on-brand caption.'),
        ]);

        $result = (new AiGuardrailService)->filterCaptionSuggestions($brandBrain, $suggestions);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('A perfectly on-brand caption.', $result->suggestions[0]->caption);
    }

    public function test_it_warns_with_a_received_kept_summary_when_every_idea_is_dropped(): void
    {
        $brandBrain = BrandBrain::factory()->make(); // do_nots: ['Avoid slang', 'No profanity']
        Log::spy();

        (new AiGuardrailService)->filterPostIdeas($brandBrain, new AiPostIdeas([
            new AiPostIdea(title: 'Idea', description: 'No profanity, promise'),
        ]), ['client_id' => 7]);

        Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context = []) => $level === 'warning'
            && $message === 'AI guardrail filtered post ideas'
            && $context === ['client_id' => 7, 'received' => 1, 'kept' => 0, 'dropped' => 1]);
    }

    public function test_it_logs_an_info_summary_when_the_provider_returned_nothing(): void
    {
        Log::spy();

        (new AiGuardrailService)->filterTrends(null, new AiTrends);

        Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context = []) => $level === 'info'
            && $message === 'AI guardrail filtered trends'
            && $context['received'] === 0);
    }

    public function test_caption_suggestions_using_a_never_use_hashtag_are_rejected(): void
    {
        $brandBrain = BrandBrain::factory()->make(); // hashtag_policy.never_use: ['#competitor']

        $suggestions = new AiCaptionSuggestions([
            new AiCaptionSuggestion(caption: 'Great post', hashtags: ['#competitor']),
            new AiCaptionSuggestion(caption: 'Another post', hashtags: ['#brand']),
        ]);

        $result = (new AiGuardrailService)->filterCaptionSuggestions($brandBrain, $suggestions);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame(['#brand'], $result->suggestions[0]->hashtags);
    }

    public function test_globally_prohibited_topics_are_rejected_across_all_surfaces(): void
    {
        Config::set('ai.guardrails.prohibited_topics', ['politics']);

        $guardrail = new AiGuardrailService;

        $suggestions = $guardrail->filterCaptionSuggestions(null, new AiCaptionSuggestions([
            new AiCaptionSuggestion(caption: 'Let us talk about politics today'),
            new AiCaptionSuggestion(caption: 'Safe caption'),
        ]));
        $ideas = $guardrail->filterPostIdeas(null, new AiPostIdeas([
            new AiPostIdea(title: 'Politics corner', description: 'A deep dive'),
            new AiPostIdea(title: 'Safe idea', description: 'A deep dive'),
        ]));
        $trends = $guardrail->filterTrends(null, new AiTrends([
            new AiTrend(title: 'Politics trending', summary: 'Summary'),
            new AiTrend(title: 'Safe trend', summary: 'Summary'),
        ]));

        $this->assertCount(1, $suggestions->suggestions);
        $this->assertSame('Safe caption', $suggestions->suggestions[0]->caption);
        $this->assertCount(1, $ideas->ideas);
        $this->assertSame('Safe idea', $ideas->ideas[0]->title);
        $this->assertCount(1, $trends->trends);
        $this->assertSame('Safe trend', $trends->trends[0]->title);
    }

    public function test_scrub_redacts_emails_phone_numbers_and_secrets(): void
    {
        $scrubbed = (new AiGuardrailService)->scrub(
            'Contact jane@example.com or 555-123-4567, key api_1234567890abcdef is leaked.'
        );

        $this->assertStringNotContainsString('jane@example.com', $scrubbed);
        $this->assertStringNotContainsString('555-123-4567', $scrubbed);
        $this->assertStringNotContainsString('api_1234567890abcdef', $scrubbed);
        $this->assertStringContainsString('[redacted-email]', $scrubbed);
        $this->assertStringContainsString('[redacted-secret]', $scrubbed);
    }

    public function test_platform_warnings_flag_links_and_hashtag_overages_instead_of_rewriting(): void
    {
        $suggestion = new AiCaptionSuggestion(
            caption: 'Check this out https://example.com',
            hashtags: array_fill(0, 10, 'tag'),
        );

        $warnings = (new AiGuardrailService)->platformWarnings(Platform::LinkedIn, $suggestion);

        $this->assertNotEmpty($warnings);
        $this->assertSame('Check this out https://example.com', $suggestion->caption);
    }
}
