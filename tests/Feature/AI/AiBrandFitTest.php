<?php

namespace Tests\Feature\AI;

use App\Enums\Role;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step M-7.4 (dev plan Step 2.12) — caption suggestions carry a 0–100 brand-fit score, and a
 * suggestion using a "never use" phrase is filtered out before it is ever shown.
 */
class AiBrandFitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
        Config::set('ai.deepseek.api_key', 'sk-test');
        Config::set('ai.deepseek.base_url', 'https://api.deepseek.test');
    }

    public function test_suggestions_carry_a_brand_fit_score_and_a_banned_one_is_filtered_out(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode([
                        'suggestions' => [
                            // Uses a "never use" phrase — must be dropped by the guardrail.
                            ['caption' => 'Get it cheap today!', 'hashtags' => ['graphy']],
                            // On-brand — kept and scored.
                            ['caption' => 'Education is at our heart.', 'hashtags' => ['graphy']],
                        ],
                    ])],
                ]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 30],
            ], 200),
        ]);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        BrandBrain::factory()->for($client)->create([
            'hashtag_policy' => ['always_use' => ['graphy'], 'never_use' => ['cheap'], 'rotation_notes' => null],
            'content_pillars' => ['Education'],
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $dispatch = $this->actingAs($owner)->postJson(route('posts.ai.captions', $post))->assertAccepted();

        $poll = $this->actingAs($owner)->getJson(route('ai-text-jobs.status', $dispatch->json('request_id')));
        $poll->assertOk()->assertJsonPath('status', 'completed');

        $suggestions = $poll->json('result.suggestions');
        $brandFit = $poll->json('result.brandFit');

        // The "cheap" suggestion was filtered out; only the on-brand one survives.
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('Education', $suggestions[0]['caption']);

        // It carries a brand-fit score, and the on-brand caption scores well.
        $this->assertCount(1, $brandFit);
        $this->assertGreaterThanOrEqual(75, $brandFit[0]['score']);
    }
}
