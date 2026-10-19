<?php

namespace Tests\Feature\Publishing;

use App\Actions\Posts\ReschedulePostAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Publishing\UploadPostAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Step I-3.6 / M-2.5 — a post the vendor is holding for later (channels sent with a future
 * `scheduled_date`) is cancelled there before it's unscheduled, edited, moved, paused or shifted,
 * and re-submitted afterwards — so it never double-posts, and nothing changes when the vendor
 * says it's too late.
 */
class VendorHeldPostsTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->org->update(['upload_post_key' => 'org-key']);
        Bus::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    }

    /**
     * A `publishing` post whose two channels are both held by the vendor until 22 October.
     */
    private function heldPost(?Campaign $campaign = null): Post
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'campaign_id' => $campaign?->id,
            'status' => PostStatus::Publishing,
            'master_caption' => 'Original caption',
        ]);

        foreach (['job-ig' => Platform::Instagram, 'job-li' => Platform::LinkedIn] as $job => $platform) {
            $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => $platform, 'timezone' => 'Europe/London']);
            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
                'status' => PostTargetStatus::Pending,
                'external_post_id' => $job,
                'publish_attempt_id' => 'attempt-'.$job,
                'publish_requested_at' => now(),
                'scheduled_local_date' => '2026-10-22',
                'scheduled_local_time' => '09:00:00',
                'scheduled_at_utc' => CarbonImmutable::parse('2026-10-22 09:00:00', 'Europe/London')->utc(),
            ]);
        }

        return $post->refresh();
    }

    private function vendorCancels(int $status = 200): void
    {
        Http::fake(['*/uploadposts/schedule/*' => Http::response(
            $status === 200 ? ['success' => true, 'credits_refunded' => 1] : ['success' => false, 'error' => 'scheduled_post_in_progress'],
            $status,
        )]);
    }

    public function test_the_adapter_cancels_through_the_schedule_endpoint_and_only_trusts_a_confirmed_cancel(): void
    {
        $target = $this->heldPost()->targets()->with('socialAccount.organization')->firstOrFail();

        Http::fake(['*/uploadposts/schedule/*' => Http::sequence()
            ->push(['success' => true], 200)
            ->push(['success' => false, 'error_code' => 'scheduled_post_in_progress'], 409)
            ->push(['success' => false, 'error' => 'Job not found'], 404)
            ->push(['error' => 'boom'], 500)]);

        $this->assertTrue((new UploadPostAdapter)->cancel($target)->cancelled);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/uploadposts/schedule/job-ig')
            && $request->hasHeader('Authorization', 'Apikey org-key'));

        foreach ([true, true, false] as $tooLate) {
            $result = (new UploadPostAdapter)->cancel($target);
            $this->assertFalse($result->cancelled, 'Only a confirmed cancel counts.');
            $this->assertSame($tooLate, $result->tooLate);
        }
    }

    public function test_unscheduling_cancels_every_held_channel_and_resets_them(): void
    {
        $this->vendorCancels();
        $post = $this->heldPost();

        app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $this->owner(), 'Hold on.');

        Http::assertSentCount(2);
        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertNull($post->publish_dispatch_id);
        $post->targets->each(function (PostTarget $target) {
            $this->assertSame(PostTargetStatus::Pending, $target->status);
            $this->assertNull($target->external_post_id);
            $this->assertNull($target->publish_attempt_id);
        });
        $this->assertDatabaseCount('post_approvals', 0);
    }

    public function test_nothing_changes_when_the_vendor_says_its_too_late(): void
    {
        $this->vendorCancels(409);
        $post = $this->heldPost();

        try {
            app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $this->owner());
            $this->fail('Expected the unschedule to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('can’t be changed now', $e->errors()['to'][0]);
        }

        $this->assertSame(PostStatus::Publishing, $post->refresh()->status);
        $this->assertSame(['job-ig', 'job-li'], $post->targets->pluck('external_post_id')->sort()->values()->all());
    }

    public function test_a_post_with_a_channel_already_out_cannot_be_unscheduled(): void
    {
        $post = $this->heldPost();
        $post->targets()->first()->update(['status' => PostTargetStatus::Published]);

        $this->assertFalse($post->refresh()->canBeRecalled());
        $this->assertFalse($this->owner()->can('transition', [$post, PostStatus::Approved]));

        $this->expectException(ValidationException::class);
        app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $this->owner());
    }

    public function test_editing_a_held_post_cancels_then_resubmits_it_with_the_new_content(): void
    {
        $this->vendorCancels();
        $post = $this->heldPost();

        $this->actingAs($this->owner())->put(route('posts.update', $post), ['master_caption' => 'Fixed caption'])
            ->assertSessionHasNoErrors();

        Http::assertSentCount(2);
        $this->assertSame('Fixed caption', $post->refresh()->master_caption);
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($job) => $job instanceof PublishPostTargetJob));
        $post->targets->each(fn (PostTarget $target) => $this->assertNotNull($target->publish_attempt_id));
    }

    public function test_an_edit_is_refused_when_the_vendor_wont_release_the_post(): void
    {
        $this->vendorCancels(409);
        $post = $this->heldPost();

        $this->actingAs($this->owner())->put(route('posts.update', $post), ['master_caption' => 'Too late'])
            ->assertSessionHasErrors('targets');

        $this->assertSame('Original caption', $post->refresh()->master_caption);
        Bus::assertNothingBatched();
    }

    public function test_moving_a_held_post_resubmits_it_at_the_new_time(): void
    {
        $this->vendorCancels();
        $post = $this->heldPost();

        app(ReschedulePostAction::class)($post, 2, $this->owner());

        Http::assertSentCount(2);
        $this->assertSame('2026-10-24', $post->refresh()->targets->first()->scheduled_local_date->toDateString());
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 2);
    }

    public function test_pausing_a_campaign_cancels_its_vendor_held_posts(): void
    {
        $this->vendorCancels();
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active]);
        $held = $this->heldPost($campaign);

        $this->actingAs($this->strategist())->post(route('campaigns.pause', $campaign))->assertSessionHasNoErrors();

        Http::assertSentCount(2);
        $this->assertSame(PostStatus::Approved, $held->refresh()->status);
        $log = $campaign->activityLogs()->where('event', 'status.changed')->where('to', CampaignStatus::Paused->value)->firstOrFail();
        $this->assertSame([$held->id], $log->meta['paused_post_ids']);
    }

    public function test_shifting_a_campaign_recalls_and_resubmits_its_vendor_held_posts(): void
    {
        $this->vendorCancels();
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active, 'start_date' => '2026-10-20', 'end_date' => '2026-11-10']);
        $held = $this->heldPost($campaign);

        $this->actingAs($this->owner())->post(route('campaigns.shift', $campaign), ['days' => 7])->assertSessionHasNoErrors();

        Http::assertSentCount(2);
        $this->assertSame('2026-10-29', $held->refresh()->targets->first()->scheduled_local_date->toDateString());
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 2);
    }
}
