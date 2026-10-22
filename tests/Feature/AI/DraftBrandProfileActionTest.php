<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DraftBrandProfileAction;
use App\Contracts\AiProvider;
use App\Contracts\WebsiteSummarizer;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Post;
use App\Services\Web\HttpWebsiteSummarizer;
use App\Support\AI\AiBrandProfileDraft;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use App\Support\AI\BrandProfileInput;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageBriefInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step B-2.4 — the brand-profile AI quick-start Action.
 */
class DraftBrandProfileActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fake provider that records the input it received and returns a canned draft.
     */
    private function fakeProvider(AiBrandProfileDraft $returns, ?\Closure $capture = null): AiProvider
    {
        return new class($returns, $capture) implements AiProvider
        {
            public function __construct(
                private AiBrandProfileDraft $returns,
                private ?\Closure $capture,
            ) {}

            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions;
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas;
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends;
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }

            public function draftBrandProfile(BrandProfileInput $input): AiBrandProfileDraft
            {
                if ($this->capture !== null) {
                    ($this->capture)($input);
                }

                return $this->returns;
            }
        };
    }

    private function filledDraft(): AiBrandProfileDraft
    {
        return AiBrandProfileDraft::fromArray([
            'voice' => ['tone' => 'Warm', 'personality' => 'Friendly', 'do_nots' => ['Hard selling']],
            'audience' => ['description' => 'Locals', 'demographics' => '25-45', 'pain_points' => ['No time']],
            'offer' => ['value_proposition' => 'Real bread', 'key_products' => ['Sourdough'], 'pricing_notes' => null],
            'content_pillars' => ['Behind the counter', 'Today bake'],
        ], succeeded: true, provider: 'deepseek', model: 'deepseek-chat');
    }

    public function test_it_returns_a_draft_without_persisting_anything(): void
    {
        $client = Client::factory()->primary()->create(['name' => 'Sourdough & Co.']);
        $brain = BrandBrain::create(['client_id' => $client->id]);

        $this->app->instance(AiProvider::class, $this->fakeProvider($this->filledDraft()));

        $draft = $this->app->make(DraftBrandProfileAction::class)($client, [
            'name' => 'Sourdough & Co.',
            'one_liner' => 'A neighbourhood bakery.',
            'location' => 'Bristol, UK',
        ]);

        $this->assertTrue($draft->succeeded);
        $this->assertSame('Warm', $draft->voice['tone']);
        $this->assertSame(['Behind the counter', 'Today bake'], $draft->contentPillars);

        // Nothing is written to the Brand Brain until the owner accepts.
        $brain->refresh();
        $this->assertNull($brain->voice);
        $this->assertNull($brain->content_pillars);

        // The call is metered.
        $this->assertDatabaseHas('ai_usage_logs', [
            'client_id' => $client->id,
            'method' => 'draftBrandProfile',
            'provider' => 'deepseek',
            'succeeded' => true,
        ]);
    }

    public function test_a_provider_failure_falls_back_to_an_empty_draft_and_writes_nothing(): void
    {
        $client = Client::factory()->primary()->create();
        BrandBrain::create(['client_id' => $client->id]);

        // The Null provider (AI off, or a failed vendor call) returns an empty draft.
        $this->app->instance(AiProvider::class, $this->fakeProvider(AiBrandProfileDraft::empty()));

        $draft = $this->app->make(DraftBrandProfileAction::class)($client, ['name' => 'Anything']);

        $this->assertFalse($draft->succeeded);
        $this->assertTrue($draft->isEmpty());

        // Even a failed call is recorded (for metering), flagged as not succeeded.
        $this->assertDatabaseHas('ai_usage_logs', [
            'client_id' => $client->id,
            'method' => 'draftBrandProfile',
            'succeeded' => false,
        ]);
    }

    public function test_a_website_is_summarized_and_passed_to_the_provider(): void
    {
        $client = Client::factory()->primary()->create();

        $this->app->instance(WebsiteSummarizer::class, new class implements WebsiteSummarizer
        {
            public function summarize(string $url): ?string
            {
                return 'We bake real sourdough in Bristol.';
            }
        });

        $captured = null;
        $this->app->instance(AiProvider::class, $this->fakeProvider(
            $this->filledDraft(),
            function (BrandProfileInput $input) use (&$captured) {
                $captured = $input;
            },
        ));

        $this->app->make(DraftBrandProfileAction::class)($client, [
            'name' => 'Sourdough & Co.',
            'website' => 'https://sourdough.test',
        ]);

        $this->assertNotNull($captured);
        $this->assertSame('We bake real sourdough in Bristol.', $captured->websiteText);
    }

    public function test_an_ssrf_blocked_url_yields_no_website_text(): void
    {
        Http::fake();
        $client = Client::factory()->primary()->create();

        // The real, SSRF-guarded summarizer refuses a link-local metadata address.
        $this->app->instance(WebsiteSummarizer::class, new HttpWebsiteSummarizer);

        $captured = null;
        $this->app->instance(AiProvider::class, $this->fakeProvider(
            AiBrandProfileDraft::empty(),
            function (BrandProfileInput $input) use (&$captured) {
                $captured = $input;
            },
        ));

        $this->app->make(DraftBrandProfileAction::class)($client, [
            'name' => 'Sneaky',
            'website' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $this->assertNotNull($captured);
        $this->assertNull($captured->websiteText);
        Http::assertNothingSent();
    }
}
