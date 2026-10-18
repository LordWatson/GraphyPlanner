<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignBillingType;
use App\Enums\CampaignStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignPhase;
use App\Models\CampaignTemplate;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Campaigns\Concerns\BuildsCampaigns;
use Tests\TestCase;

/**
 * Campaigns enhancement C-6.x / C-8.2 / C-3.4 — running a campaign: pause and resume touch exactly
 * the campaign's scheduled posts, shifting keeps local posting times across DST and leaves
 * published work alone, invoicing asks before billing twice and never edits a finalized invoice,
 * and templates round-trip without leaking between organizations.
 */
class CampaignRunTest extends TestCase
{
    use BuildsCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCampaignOrg();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    }

    private function postFor(?Campaign $campaign, PostStatus $status, string $date = '2026-10-22', string $timezone = 'Europe/London'): Post
    {
        $post = Post::factory()->for($this->client)->create(['campaign_id' => $campaign?->id, 'status' => $status]);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::Instagram, 'timezone' => $timezone]);
        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'scheduled_local_date' => $date,
            'scheduled_local_time' => '09:00:00',
            'scheduled_at_utc' => CarbonImmutable::parse("{$date} 09:00:00", $timezone)->utc(),
        ]);

        return $post;
    }

    public function test_pausing_unschedules_exactly_the_campaigns_scheduled_posts(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active]);
        $scheduled = $this->postFor($campaign, PostStatus::Scheduled);
        $published = $this->postFor($campaign, PostStatus::Published);
        $draft = $this->postFor($campaign, PostStatus::Draft);
        $elsewhere = $this->postFor(null, PostStatus::Scheduled);

        $this->actingAs($this->strategist())->post(route('campaigns.pause', $campaign))->assertSessionHasNoErrors();

        $this->assertSame(CampaignStatus::Paused, $campaign->refresh()->status);
        $this->assertSame(PostStatus::Approved, $scheduled->refresh()->status);
        $this->assertNull($scheduled->publish_dispatch_id);
        $this->assertSame(PostStatus::Published, $published->refresh()->status);
        $this->assertSame(PostStatus::Draft, $draft->refresh()->status);
        $this->assertSame(PostStatus::Scheduled, $elsewhere->refresh()->status);
        $this->assertDatabaseCount('post_approvals', 0);

        $log = $campaign->activityLogs()->where('event', 'status.changed')->where('to', CampaignStatus::Paused->value)->firstOrFail();
        $this->assertSame([$scheduled->id], $log->meta['paused_post_ids']);
    }

    public function test_a_designer_cannot_pause_a_campaign(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active]);

        $this->actingAs($this->designer())->post(route('campaigns.pause', $campaign))->assertForbidden();
        $this->assertSame(CampaignStatus::Active, $campaign->refresh()->status);
    }

    public function test_only_an_active_campaign_can_be_paused(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Planning]);

        $this->actingAs($this->owner())->post(route('campaigns.pause', $campaign))->assertSessionHasErrors('to');
        $this->assertSame(CampaignStatus::Planning, $campaign->refresh()->status);
    }

    public function test_resuming_without_rescheduling_leaves_the_posts_approved(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active]);
        $post = $this->postFor($campaign, PostStatus::Scheduled);
        $this->actingAs($this->owner())->post(route('campaigns.pause', $campaign));

        $this->actingAs($this->owner())->post(route('campaigns.resume', $campaign), ['mode' => 'none'])->assertSessionHasNoErrors();

        $this->assertSame(CampaignStatus::Active, $campaign->refresh()->status);
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $campaign->id, 'event' => 'campaign.resumed']);
    }

    public function test_resume_keep_skips_a_post_whose_slot_has_passed(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active]);
        $post = $this->postFor($campaign, PostStatus::Scheduled, '2026-10-20');
        $this->actingAs($this->owner())->post(route('campaigns.pause', $campaign));

        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'UTC'));
        $this->actingAs($this->owner())->post(route('campaigns.resume', $campaign), ['mode' => 'keep']);

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        $log = $campaign->activityLogs()->where('event', 'campaign.resumed')->firstOrFail();
        $this->assertSame([$post->id], array_column($log->meta['skipped'], 'id'));
    }

    public function test_resume_rejects_an_unknown_mode(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Paused]);

        $this->actingAs($this->owner())->post(route('campaigns.resume', $campaign), ['mode' => 'sideways'])->assertSessionHasErrors('mode');
        $this->assertSame(CampaignStatus::Paused, $campaign->refresh()->status);
    }

    public function test_shifting_keeps_local_times_across_dst_and_leaves_published_posts_alone(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active, 'start_date' => '2026-10-20', 'end_date' => '2026-11-10']);
        $phase = CampaignPhase::factory()->for($campaign)->create(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-27']);
        $london = $this->postFor($campaign, PostStatus::Draft, '2026-10-22', 'Europe/London');
        $dubai = $this->postFor($campaign, PostStatus::Draft, '2026-10-22', 'Asia/Dubai');
        $published = $this->postFor($campaign, PostStatus::Published, '2026-10-22');

        $this->actingAs($this->owner())->post(route('campaigns.shift', $campaign), ['days' => 7])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-27', $campaign->refresh()->start_date->toDateString());
        $this->assertSame('2026-10-27', $phase->refresh()->starts_on->toDateString());

        // London leaves BST on 25 October: 09:00 local was 08:00 UTC and is now 09:00 UTC.
        $londonTarget = $london->targets()->firstOrFail();
        $this->assertSame('2026-10-29', $londonTarget->scheduled_local_date->toDateString());
        $this->assertSame('2026-10-29 09:00:00', $londonTarget->scheduled_at_utc->toDateTimeString());

        $dubaiTarget = $dubai->targets()->firstOrFail();
        $this->assertSame('2026-10-29 05:00:00', $dubaiTarget->scheduled_at_utc->toDateTimeString());

        $this->assertSame('2026-10-22', $published->targets()->firstOrFail()->scheduled_local_date->toDateString());
    }

    public function test_the_shift_preview_writes_nothing(): void
    {
        $campaign = $this->makeCampaign(['status' => CampaignStatus::Active, 'start_date' => '2026-10-20', 'end_date' => '2026-11-10']);
        $this->postFor($campaign, PostStatus::Draft);
        $this->postFor($campaign, PostStatus::Published);

        $this->actingAs($this->owner())->getJson(route('campaigns.shift.preview', [$campaign, 'days' => 7]))
            ->assertOk()
            ->assertJsonPath('campaign.from', '2026-10-27')
            ->assertJsonCount(1, 'posts')
            ->assertJsonCount(1, 'blocked');

        $this->assertSame('2026-10-20', $campaign->refresh()->start_date->toDateString());
    }

    public function test_invoicing_twice_needs_confirmation(): void
    {
        $campaign = $this->makeCampaign(['billing_type' => CampaignBillingType::FixedFee, 'fee_amount' => 1200]);

        $this->actingAs($this->owner())->post(route('campaigns.invoice', $campaign))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('invoice_items', ['campaign_id' => $campaign->id]);

        $this->actingAs($this->owner())->post(route('campaigns.invoice', $campaign))->assertSessionHasErrors('confirm');
        $this->assertDatabaseCount('invoice_items', 1);

        $this->actingAs($this->owner())->post(route('campaigns.invoice', $campaign), ['confirm' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_invoicing_never_touches_a_finalized_invoice(): void
    {
        $campaign = $this->makeCampaign(['billing_type' => CampaignBillingType::FixedFee, 'fee_amount' => 1200]);
        $finalized = Invoice::factory()->for($this->client)->create(['status' => InvoiceStatus::Draft, 'is_finalized' => true]);

        $this->actingAs($this->owner())->post(route('campaigns.invoice', $campaign))->assertSessionHasNoErrors();

        $item = $campaign->invoiceItems()->firstOrFail();
        $this->assertNotSame($finalized->id, $item->invoice_id);
    }

    public function test_invoicing_needs_a_fixed_fee_and_the_right_role(): void
    {
        $hourly = $this->makeCampaign(['billing_type' => CampaignBillingType::IncludedInRetainer, 'fee_amount' => null]);
        $this->actingAs($this->owner())->post(route('campaigns.invoice', $hourly))->assertSessionHasErrors('fee_amount');

        $fixed = $this->makeCampaign(['billing_type' => CampaignBillingType::FixedFee, 'fee_amount' => 500]);
        $this->actingAs($this->designer())->post(route('campaigns.invoice', $fixed))->assertForbidden();
        $this->assertDatabaseCount('invoice_items', 0);
    }

    public function test_a_campaign_round_trips_through_a_template(): void
    {
        $campaign = $this->makeCampaign(['start_date' => '2026-11-02', 'end_date' => '2026-11-29']);
        CampaignPhase::factory()->for($campaign)->create(['name' => 'Teaser', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-08']);

        $this->actingAs($this->strategist())->post(route('campaigns.template.store', $campaign), ['name' => 'Our launch'])->assertSessionHasNoErrors();
        $template = CampaignTemplate::query()->where('name', 'Our launch')->firstOrFail();

        $this->actingAs($this->strategist())->post(route('campaign-templates.use', $template), [
            'client_id' => $this->client->id,
            'name' => 'Spring launch',
            'start_date' => '2027-03-01',
        ])->assertSessionHasNoErrors();

        $created = Campaign::query()->where('name', 'Spring launch')->firstOrFail();
        $this->assertSame('2027-03-01', $created->start_date->toDateString());
        $this->assertSame('2027-03-28', $created->end_date->toDateString());
        $this->assertSame('2027-03-01', $created->phases()->where('name', 'Teaser')->firstOrFail()->starts_on->toDateString());
    }

    public function test_another_organizations_template_cannot_be_used_or_seen(): void
    {
        $foreignOrg = Organization::factory()->create();
        $theirs = CampaignTemplate::query()->withoutGlobalScopes()->create(['org_id' => $foreignOrg->id, 'name' => 'Theirs', 'duration_days' => 14, 'is_starter' => false, 'phases' => [], 'deliverables' => []]);

        $this->actingAs($this->owner())->post(route('campaign-templates.use', $theirs), [
            'client_id' => $this->client->id,
            'name' => 'Stolen',
            'start_date' => '2027-01-01',
        ])->assertForbidden();

        $this->actingAs($this->owner())->get(route('campaign-templates.index'))
            ->assertOk()
            ->assertDontSee('Theirs');
        $this->assertDatabaseMissing('campaigns', ['name' => 'Stolen']);
    }
}
