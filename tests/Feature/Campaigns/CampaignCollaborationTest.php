<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Mail\CampaignPostsReadyMail;
use App\Mail\CampaignReportSharedMail;
use App\Models\Campaign;
use App\Models\Post;
use App\Notifications\CampaignCommentAdded;
use App\Notifications\CampaignReviewSubmitted;
use App\Notifications\PostApprovalDecided;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement C-4.x / C-5.x / C-7.x — working with the client: bulk moves send one
 * email, the portal's batch review records fresh decisions and refuses stale ones with one studio
 * notification, mentions only reach staff, and a shared report's numbers are frozen.
 */
class CampaignCollaborationTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        $this->client->update(['approval_email' => 'approvals@client.test']);
        $this->campaign = $this->makeCampaign(['status' => CampaignStatus::Active]);
        Mail::fake();
        Notification::fake();
    }

    private function makePost(PostStatus $status, ?Campaign $campaign = null): Post
    {
        return Post::factory()->for($this->client)->create(['campaign_id' => ($campaign ?? $this->campaign)->id, 'status' => $status]);
    }

    public function test_sending_a_batch_to_the_client_queues_one_email(): void
    {
        $posts = collect([$this->makePost(PostStatus::InternalReview), $this->makePost(PostStatus::InternalReview), $this->makePost(PostStatus::InternalReview)]);
        $published = $this->makePost(PostStatus::Published);

        $this->actingAs($this->strategist())->postJson(route('campaigns.posts.bulk', $this->campaign), [
            'action' => 'send_to_client',
            'post_ids' => [...$posts->pluck('id'), $published->id],
        ])->assertOk()
            ->assertJsonCount(3, 'done')
            ->assertJsonPath('skipped.0.id', $published->id);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(CampaignPostsReadyMail::class, fn ($mail) => $mail->hasTo('approvals@client.test'));
        $this->assertSame(3, Post::query()->where('status', PostStatus::WaitingClient)->count());
    }

    public function test_a_bulk_action_skips_posts_from_another_campaign(): void
    {
        $other = $this->makeCampaign();
        $foreign = $this->makePost(PostStatus::Draft, $other);

        $this->actingAs($this->strategist())->postJson(route('campaigns.posts.bulk', $this->campaign), [
            'action' => 'submit',
            'post_ids' => [$foreign->id],
        ])->assertOk()->assertJsonPath('skipped.0.reason', 'Not part of this campaign.');

        $this->assertSame(PostStatus::Draft, $foreign->refresh()->status);
    }

    public function test_a_designer_cannot_bulk_reassign_work(): void
    {
        $this->actingAs($this->designer())->postJson(route('campaigns.posts.bulk', $this->campaign), [
            'action' => 'assign',
            'post_ids' => [$this->makePost(PostStatus::Draft)->id],
            'value' => $this->designer()->id,
        ])->assertForbidden();
    }

    public function test_a_batch_review_records_fresh_decisions_and_refuses_stale_ones(): void
    {
        $fresh = $this->makePost(PostStatus::WaitingClient);
        $changed = $this->makePost(PostStatus::WaitingClient);
        $seenChanged = $changed->updated_at->copy()->subMinute()->toIso8601String();

        $this->actingAs($this->reviewer)->post(route('portal.campaigns.review.submit', $this->campaign), [
            'decisions' => [
                ['post_id' => $fresh->id, 'decision' => 'approved', 'seen_at' => $fresh->updated_at->toIso8601String()],
                ['post_id' => $changed->id, 'decision' => 'approved', 'seen_at' => $seenChanged],
            ],
        ])->assertRedirect(route('portal.campaigns.review', $this->campaign));

        $this->assertSame(PostStatus::Approved, $fresh->refresh()->status);
        $this->assertSame(PostStatus::WaitingClient, $changed->refresh()->status);

        Notification::assertSentTo($this->owner(), CampaignReviewSubmitted::class);
        Notification::assertSentTimes(CampaignReviewSubmitted::class, 2);
        Notification::assertNotSentTo($this->owner(), PostApprovalDecided::class);
    }

    public function test_a_clean_batch_review_returns_to_the_campaign(): void
    {
        $post = $this->makePost(PostStatus::WaitingClient);

        $this->actingAs($this->reviewer)->post(route('portal.campaigns.review.submit', $this->campaign), [
            'decisions' => [['post_id' => $post->id, 'decision' => 'changes_requested', 'comment' => 'Warmer, please.', 'seen_at' => $post->updated_at->toIso8601String()]],
        ])->assertRedirect(route('portal.campaigns.show', $this->campaign));

        $this->assertSame(PostStatus::ChangesRequested, $post->refresh()->status);
    }

    public function test_mentions_only_notify_staff_and_internal_comments_skip_the_client(): void
    {
        $designer = $this->designer();
        $body = "@[{$designer->name}]({$designer->id}) and @[Client]({$this->reviewer->id}) — new cut is ready.";

        $this->actingAs($this->strategist())->post(route('campaigns.comments.store', $this->campaign), ['body' => $body, 'internal_only' => true])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($designer, CampaignCommentAdded::class);
        Notification::assertNotSentTo($this->reviewer, CampaignCommentAdded::class);
    }

    public function test_a_client_cannot_post_an_internal_comment(): void
    {
        $this->actingAs($this->reviewer)->post(route('portal.campaigns.comments.store', $this->campaign), ['body' => 'Looks great.', 'internal_only' => true])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('campaign_comments', ['campaign_id' => $this->campaign->id, 'body' => 'Looks great.', 'internal_only' => false]);
        Notification::assertSentTo($this->owner(), CampaignCommentAdded::class);
    }

    public function test_a_shared_report_freezes_its_numbers_and_emails_the_client(): void
    {
        $this->actingAs($this->strategist())->put(route('campaigns.kpis.update', $this->campaign), ['kpis' => [['metric' => 'posts_published', 'target' => 10]]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->strategist())->put(route('campaigns.report.update', $this->campaign), ['summary' => 'A strong launch.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->strategist())->post(route('campaigns.report.share', $this->campaign))->assertSessionHasNoErrors();

        $report = $this->campaign->report()->firstOrFail();
        $this->assertSame(10.0, (float) $report->kpi_snapshot[0]['target']);
        Mail::assertQueued(CampaignReportSharedMail::class, fn ($mail) => $mail->hasTo($this->reviewer->email));

        $this->actingAs($this->strategist())->put(route('campaigns.kpis.update', $this->campaign), ['kpis' => [['metric' => 'posts_published', 'target' => 99]]]);

        $this->assertSame(10.0, (float) $report->refresh()->kpi_snapshot[0]['target']);
        $this->assertArrayNotHasKey('fee_amount', $report->kpi_snapshot[0]);
    }

    public function test_a_report_without_a_summary_cannot_be_shared(): void
    {
        $this->actingAs($this->strategist())->post(route('campaigns.report.share', $this->campaign))->assertSessionHasErrors('summary');
        Mail::assertNothingQueued();
    }
}
