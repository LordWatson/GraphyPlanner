<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadPostWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Post, 1: \App\Models\PostTarget, 2: Organization}
     */
    private function createPendingTarget(PostStatus $postStatus = PostStatus::Publishing): array
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => $postStatus]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'job-123',
        ]);

        return [$post, $target, $org];
    }

    public function test_a_success_payload_transitions_a_publishing_post_to_published(): void
    {
        [$post, $target] = $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ]);

        $response->assertOk();
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $target->refresh()->status);
    }

    public function test_a_failed_payload_transitions_a_publishing_post_to_failed(): void
    {
        [$post, $target] = $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => false, 'error' => 'Rejected by platform'],
        ]);

        $response->assertOk();
        $post->refresh();
        $target->refresh();

        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(PostTargetStatus::Failed, $target->status);
        $this->assertSame('Rejected by platform', $target->error);
    }

    public function test_it_rejects_a_payload_with_an_invalid_signature_when_a_secret_is_configured(): void
    {
        [$post, , $org] = $this->createPendingTarget();
        $org->update(['upload_post_webhook_secret' => 'shh-secret']);

        $response = $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ], [
            'X-Upload-Post-Signature' => 'sha256=wrong-signature',
            'X-Upload-Post-Timestamp' => (string) time(),
        ]);

        $response->assertStatus(401);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }

    public function test_it_accepts_a_payload_with_a_valid_signature(): void
    {
        [$post, , $org] = $this->createPendingTarget();
        $org->update(['upload_post_webhook_secret' => 'shh-secret']);

        $payload = [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ];
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", 'shh-secret');

        $response = $this->call('POST', '/webhooks/upload-post', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Upload-Post-Signature' => $signature,
            'HTTP_X-Upload-Post-Timestamp' => $timestamp,
        ], $body);

        $response->assertOk();
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_it_rejects_a_payload_with_a_stale_timestamp(): void
    {
        [$post, , $org] = $this->createPendingTarget();
        $org->update(['upload_post_webhook_secret' => 'shh-secret']);

        $payload = [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ];
        $body = json_encode($payload);
        $timestamp = (string) (time() - 3600);
        $signature = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", 'shh-secret');

        $response = $this->call('POST', '/webhooks/upload-post', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Upload-Post-Signature' => $signature,
            'HTTP_X-Upload-Post-Timestamp' => $timestamp,
        ], $body);

        $response->assertStatus(401);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }

    public function test_it_acknowledges_a_payload_for_an_unknown_job_id_without_error(): void
    {
        $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'unknown-id',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ]);

        $response->assertOk();
    }

    public function test_it_acknowledges_unrelated_events_without_action(): void
    {
        [$post] = $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'event' => 'social_account_connected',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
        ]);

        $response->assertOk();
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }
}
