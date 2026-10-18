<?php

namespace Tests\Unit\Services\Publishing;

use App\Enums\Platform;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Publishing\UploadPostAdapter;
use App\Support\Publishing\VendorHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step I-1.3 — vendor HTTP timeouts/retries (`VendorHttp`): reads retry on 5xx, the publish POST
 * retries only on 429 (a 5xx may already have posted), and the target's attempt id travels as
 * the idempotency key.
 */
class UploadPostAdapterResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_checks_retry_on_a_server_error(): void
    {
        Http::fake([
            '*/uploadposts/status*' => Http::sequence()
                ->push(['error' => 'busy'], 503)
                ->push(['status' => 'completed'], 200),
        ]);

        [, $account] = $this->postAndAccount();

        $result = (new UploadPostAdapter)->checkStatus($account, 'job-1');

        $this->assertNotNull($result);
        $this->assertTrue($result->ok);
        Http::assertSentCount(2);
    }

    public function test_a_publish_post_is_not_retried_on_a_server_error(): void
    {
        Http::fake(['*/upload_text' => Http::response(['error' => 'Bad gateway'], 502)]);

        [$post, $account] = $this->postAndAccount();

        $result = (new UploadPostAdapter)->publish($post, new Collection([$account]))->first();

        $this->assertFalse($result->ok);
        Http::assertSentCount(1);
    }

    public function test_a_publish_post_retries_on_429_and_sends_the_attempt_id_as_idempotency_key(): void
    {
        Http::fake([
            '*/upload_text' => Http::sequence()
                ->push(['error' => 'Too many requests'], 429)
                ->push(['request_id' => 'req-1'], 200),
        ]);

        [$post, $account] = $this->postAndAccount(attemptId: 'attempt-123');

        $result = (new UploadPostAdapter)->publish($post, new Collection([$account]))->first();

        $this->assertTrue($result->ok);
        $this->assertSame('req-1', $result->externalPostId);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', 'attempt-123'));
    }

    public function test_a_past_due_target_is_published_immediately_without_a_scheduled_date(): void
    {
        Http::fake(['*/upload_text' => Http::response(['request_id' => 'req-1'], 200)]);

        [$post, $account] = $this->postAndAccount(scheduledAt: now()->subHour()->toDateTimeString());

        (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(fn (Request $request) => ! array_key_exists('scheduled_date', $request->data()));
    }

    public function test_backoff_grows_exponentially_with_jitter(): void
    {
        config(['publishing.http.retry_base_ms' => 100]);

        $first = VendorHttp::backoffMs(1);
        $third = VendorHttp::backoffMs(3);

        $this->assertGreaterThanOrEqual(100, $first);
        $this->assertLessThanOrEqual(200, $first);
        $this->assertGreaterThanOrEqual(400, $third);
        $this->assertLessThanOrEqual(500, $third);
    }

    /**
     * @return array{0: Post, 1: SocialAccount}
     */
    private function postAndAccount(?string $attemptId = null, ?string $scheduledAt = null): array
    {
        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::LinkedIn,
            'external_account_id' => 'ext-acct-1',
        ]);
        $post = Post::factory()->create(['client_id' => $account->client_id, 'master_caption' => 'Hello']);
        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'scheduled_at_utc' => $scheduledAt,
            'publish_attempt_id' => $attemptId,
        ]);
        $post->load('targets', 'assets');

        return [$post, $account];
    }
}
