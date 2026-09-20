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

    private function createPendingTarget(PostStatus $postStatus = PostStatus::Publishing): array
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => $postStatus]);
        $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::LinkedIn]);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Pending,
            'external_post_id' => 'up-123',
        ]);

        return [$post, $target];
    }

    public function test_a_success_payload_transitions_a_publishing_post_to_published(): void
    {
        [$post, $target] = $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'request_id' => 'up-123',
            'status' => 'success',
        ]);

        $response->assertOk();
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Published, $target->refresh()->status);
    }

    public function test_a_failed_payload_transitions_a_publishing_post_to_failed(): void
    {
        [$post, $target] = $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'request_id' => 'up-123',
            'status' => 'failed',
            'error' => 'Rejected by platform',
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
        config(['services.upload_post.webhook_secret' => 'shh-secret']);

        [$post] = $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'request_id' => 'up-123',
            'status' => 'success',
        ], ['X-Upload-Post-Signature' => 'wrong-signature']);

        $response->assertStatus(401);
        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
    }

    public function test_it_accepts_a_payload_with_a_valid_signature(): void
    {
        config(['services.upload_post.webhook_secret' => 'shh-secret']);

        [$post] = $this->createPendingTarget();

        $payload = ['request_id' => 'up-123', 'status' => 'success'];
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, 'shh-secret');

        $response = $this->call('POST', '/webhooks/upload-post', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Upload-Post-Signature' => $signature,
        ], $body);

        $response->assertOk();
        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_it_acknowledges_a_payload_for_an_unknown_external_post_id_without_error(): void
    {
        $this->createPendingTarget();

        $response = $this->postJson('/webhooks/upload-post', [
            'request_id' => 'unknown-id',
            'status' => 'success',
        ]);

        $response->assertOk();
    }
}
