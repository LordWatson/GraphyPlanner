<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\RecommendPostsAction;
use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Enums\AiTextJobKind;
use App\Enums\AiTextJobStatus;
use App\Enums\Role;
use App\Jobs\RunAiTextJob;
use App\Models\AiTextJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\BrandFitScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Step M-7.2 (dev plan I-9.2) — captions/trends/ideas run on the queue and return a request id the
 * UI polls, so a slow vendor never blocks the HTTP request or trips a gateway timeout.
 */
class AiAsyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
    }

    public function test_the_request_returns_immediately_without_running_the_provider(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->postJson(route('posts.ai.captions', $post));

        $response->assertAccepted()->assertJsonPath('status', 'queued');
        Queue::assertPushed(RunAiTextJob::class);

        $this->assertDatabaseHas('ai_text_jobs', [
            'id' => $response->json('request_id'),
            'kind' => 'captions',
            'status' => 'queued',
            'post_id' => $post->id,
        ]);
    }

    public function test_dispatch_is_idempotent_on_the_request_id(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $id = (string) Str::uuid();

        $this->actingAs($owner)->postJson(route('clients.ai.trends', $client), ['request_id' => $id, 'platforms' => ['instagram']])->assertAccepted();
        $this->actingAs($owner)->postJson(route('clients.ai.trends', $client), ['request_id' => $id, 'platforms' => ['instagram']])->assertAccepted();

        $this->assertDatabaseCount('ai_text_jobs', 1);
        Queue::assertPushed(RunAiTextJob::class, 1);
    }

    public function test_the_worker_fills_the_result_the_poll_reads(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $job = AiTextJob::query()->create([
            'org_id' => $org->id,
            'user_id' => $owner->id,
            'client_id' => $client->id,
            'kind' => AiTextJobKind::Ideas,
            'status' => AiTextJobStatus::Queued,
        ]);

        (new RunAiTextJob($job->id))->handle(
            app(SuggestCaptionsForPostAction::class),
            app(DiscoverTrendsAction::class),
            app(RecommendPostsAction::class),
            app(AiGuardrailService::class),
            app(BrandFitScorer::class),
        );

        $poll = $this->actingAs($owner)->getJson(route('ai-text-jobs.status', $job->id));
        $poll->assertOk()->assertJsonPath('status', 'completed')->assertJsonStructure(['result' => ['ideas']]);
    }

    public function test_a_cancelled_job_is_skipped_by_the_worker(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $job = AiTextJob::query()->create([
            'org_id' => $org->id,
            'user_id' => $owner->id,
            'client_id' => $client->id,
            'kind' => AiTextJobKind::Trends,
            'status' => AiTextJobStatus::Queued,
        ]);

        // The user cancels before a worker picks it up.
        $this->actingAs($owner)->postJson(route('ai-text-jobs.cancel', $job->id))->assertOk()->assertJsonPath('status', 'cancelled');

        (new RunAiTextJob($job->id))->handle(
            app(SuggestCaptionsForPostAction::class),
            app(DiscoverTrendsAction::class),
            app(RecommendPostsAction::class),
            app(AiGuardrailService::class),
            app(BrandFitScorer::class),
        );

        $job->refresh();
        $this->assertSame(AiTextJobStatus::Cancelled, $job->status);
        $this->assertNull($job->result);
    }
}
