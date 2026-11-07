<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mail\OpsAlertMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsUploadPostWebhooks;
use Tests\TestCase;

class UploadPostWebhookControllerTest extends TestCase
{
    use RefreshDatabase, SignsUploadPostWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        // The refusal paths raise an ops alert, which emails on-call when a recipient is set.
        Mail::fake();
        config(['observability.alerts.mail_to' => 'oncall@alima.test']);
    }

    /**
     * @return array{0: Post, 1: PostTarget, 2: Organization}
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
        [$post, $target, $org] = $this->createPendingTarget();

        $response = $this->postSignedUploadPostWebhook($org, [
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
        [$post, $target, $org] = $this->createPendingTarget();

        $response = $this->postSignedUploadPostWebhook($org, [
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

    /**
     * The fail-closed rule. A signature is the only authorisation this endpoint has, so a delivery
     * for an organization that has not stored a secret yet — the normal state of a new byo
     * organization — must be refused rather than trusted. It used to be trusted, which let anyone
     * who learned a `job_id` publish or fail a post on that organization.
     */
    public function test_it_refuses_an_unsigned_delivery_when_the_organization_has_no_secret(): void
    {
        [$post, $target] = $this->createPendingTarget();

        $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ])->assertStatus(401);

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Pending, $target->refresh()->status);
    }

    /**
     * Same case, but the delivery *is* signed — with something, since there is no secret to sign
     * with. There is still nothing to verify it against, so it is still refused, and on-call is
     * told, because until the secret is pasted no publish result will be recorded at all.
     */
    public function test_it_refuses_a_signed_delivery_and_alerts_when_the_organization_has_no_secret(): void
    {
        [$post, $target] = $this->createPendingTarget();

        $body = (string) json_encode([
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ]);
        $timestamp = (string) time();

        $this->call('POST', '/webhooks/upload-post', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Upload-Post-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", 'a-secret-we-never-stored'),
            'HTTP_X-Upload-Post-Timestamp' => $timestamp,
        ], $body)->assertStatus(401);

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(PostTargetStatus::Pending, $target->refresh()->status);
        Mail::assertSent(OpsAlertMail::class, fn (OpsAlertMail $mail) => $mail->alertKey === 'webhooks.upload_post.secret_missing');
    }

    /**
     * The local-development escape hatch, asserted so its default is not the only thing keeping
     * the hole shut.
     */
    public function test_the_escape_hatch_accepts_an_unsigned_delivery_and_is_off_by_default(): void
    {
        $this->assertFalse(config('publishing.webhook_allow_unsigned'));

        [$post] = $this->createPendingTarget();
        config(['publishing.webhook_allow_unsigned' => true]);

        $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ])->assertOk();

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    /**
     * A signature header that could not be checked at all (missing, wrong prefix, stale clock) is
     * refused before the target lookup, so an unsigned flood costs no queries.
     */
    public function test_a_malformed_signature_header_is_refused_without_touching_the_target(): void
    {
        [$post, , $org] = $this->createPendingTarget();
        $this->withUploadPostWebhookSecret($org);

        $this->postJson('/webhooks/upload-post', [
            'event' => 'upload_completed',
            'job_id' => 'job-123',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ], [
            'X-Upload-Post-Timestamp' => (string) time(),
            'X-Upload-Post-Signature' => 'md5=whatever',
        ])->assertStatus(401);

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
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
        [, , $org] = $this->createPendingTarget();

        // Properly signed, so the 200 is about the unknown job id (already resolved, or never
        // ours) and not about the signature — the vendor must stop retrying either way.
        $this->postSignedUploadPostWebhook($org, [
            'event' => 'upload_completed',
            'job_id' => 'unknown-id',
            'platform' => 'linkedin',
            'result' => ['success' => true],
        ])->assertOk();
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
