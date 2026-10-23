<?php

namespace Database\Seeders\Concerns;

use App\Actions\Campaigns\SeedStarterCampaignTemplatesAction;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalMode;
use App\Enums\BriefStatus;
use App\Enums\CampaignBillingType;
use App\Enums\CampaignColour;
use App\Enums\CampaignMetric;
use App\Enums\CampaignObjective;
use App\Enums\CampaignReportStatus;
use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\AiUsageLog;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\CampaignKpiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Campaigns enhancement C-9.1 — a realistic, idempotent campaign set for a demo client: a live
 * campaign with an approved brief, four phases, deliverables in every state, KPI targets and a
 * conversation; a paused campaign; and a completed campaign with a shared wrap report. Used by
 * `DemoContentSeeder` and the frozen `PreviewSeeder` fixture.
 */
trait SeedsCampaignShowcase
{
    /**
     * @param  Collection<int, SocialAccount>  $accounts
     */
    protected function seedCampaignShowcase(Client $client, User $owner, Collection $accounts, string $prefix): void
    {
        $instagram = $accounts->first(fn (SocialAccount $account) => $account->platform === Platform::Instagram) ?? $accounts->first();
        $linkedin = $accounts->first(fn (SocialAccount $account) => $account->platform === Platform::LinkedIn) ?? $instagram;

        if ($instagram === null) {
            return;
        }

        (new SeedStarterCampaignTemplatesAction)($client->organization);

        $live = $this->showcaseCampaign($client, $owner, "{$prefix} Spring Launch", [
            'code' => strtoupper(Str::substr(Str::slug($prefix, ''), 0, 3)).'SPR',
            'status' => CampaignStatus::Active,
            'objective' => CampaignObjective::Launch,
            'colour' => CampaignColour::Teal,
            'platforms' => array_values(array_unique([Platform::Instagram->value, $linkedin?->platform->value ?? Platform::Instagram->value])),
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->addDays(21)->toDateString(),
            'goal' => 'Launch the spring range and get 40 posts out on time.',
            'hashtags' => ['SpringLaunch', Str::studly($prefix)],
            'default_link_url' => 'https://example.com/spring',
            'utm_campaign' => 'spring-launch',
            'billing_type' => CampaignBillingType::FixedFee,
            'fee_amount' => 3000,
        ]);

        $this->approvedBrief($live, $owner);
        $phases = $this->showcasePhases($live);

        $states = [
            ['Teaser story: something’s coming', 0, -5, PostStatus::Published],
            ['Teaser reel: first look', 0, -3, PostStatus::Published],
            ['Launch day reel', 1, 1, PostStatus::Scheduled],
            ['Launch announcement', 1, 1, PostStatus::Approved],
            ['Launch carousel: the range', 1, 3, PostStatus::WaitingClient],
            ['Behind the scenes', 2, 6, PostStatus::InternalReview],
            ['Customer stories #1', 2, 9, PostStatus::Draft],
            ['Customer stories #2', 2, 12, null],
            ['How it’s made', 2, 15, null],
            ['Thank-you post', 3, 19, null],
        ];

        foreach ($states as $index => [$title, $phase, $day, $status]) {
            $account = $index % 3 === 1 && $linkedin ? $linkedin : $instagram;
            $deliverable = CampaignDeliverable::updateOrCreate(
                ['campaign_id' => $live->id, 'title' => $title],
                [
                    'campaign_phase_id' => $phases[$phase]->id,
                    'platform' => $account->platform,
                    'format' => $index % 2 === 0 ? 'reel' : 'carousel',
                    'planned_for' => now()->addDays($day)->toDateString(),
                    'planned_time' => '10:00',
                    'assignee_id' => $owner->id,
                    'asset_requirements' => $index === 7 ? [['label' => '1 × 9:16 video', 'met' => false], ['label' => 'Product shot', 'met' => true]] : null,
                    'sort' => $index + 1,
                ],
            );

            if ($status !== null) {
                $post = $this->showcasePost($live, $owner, $account, $title, $status, $day);
                $deliverable->update(['post_id' => $post->id]);
            }
        }

        foreach ([[CampaignMetric::PostsPublished, 10], [CampaignMetric::OnTimeRate, 90], [CampaignMetric::ApprovalTurnaround, 24], [CampaignMetric::Reach, 50000]] as $sort => [$metric, $target]) {
            $live->kpis()->updateOrCreate(['metric' => $metric], ['target' => $target, 'sort' => $sort + 1]);
        }

        // C-8.3: the campaign AI calls behind its brief and plan, so profitability shows AI spend.
        if (! AiUsageLog::query()->withoutGlobalScopes()->where('campaign_id', $live->id)->exists()) {
            foreach (['draftCampaignBrief', 'planCampaign', 'planCampaign'] as $method) {
                AiUsageLog::query()->withoutGlobalScopes()->create([
                    'org_id' => $live->org_id,
                    'client_id' => $live->client_id,
                    'campaign_id' => $live->id,
                    'user_id' => $owner->id,
                    'provider' => 'deepseek',
                    'model' => 'deepseek-flash',
                    'method' => $method,
                    'succeeded' => true,
                    'cost_estimate' => 0.002,
                    'duration_ms' => 2400,
                ]);
            }
        }

        if (! $live->comments()->exists()) {
            $live->comments()->create(['user_id' => $owner->id, 'body' => 'Launch day assets are in. Shout if anything needs swapping.', 'internal_only' => false]);
            $live->comments()->create(['user_id' => $owner->id, 'body' => 'Internal: chase the product shot for the How it’s made post.', 'internal_only' => true]);
        }

        $paused = $this->showcaseCampaign($client, $owner, "{$prefix} Pop-up Tour", [
            'status' => CampaignStatus::Paused,
            'objective' => CampaignObjective::Community,
            'colour' => CampaignColour::Lilac,
            'platforms' => [Platform::Instagram->value],
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDays(25)->toDateString(),
            'goal' => 'Fill every pop-up date. On hold while venues are confirmed.',
        ]);
        $this->showcasePost($paused, $owner, $instagram, 'Pop-up dates announced', PostStatus::Approved, 4);

        $wrapped = $this->showcaseCampaign($client, $owner, "{$prefix} Winter Sale", [
            'status' => CampaignStatus::Completed,
            'objective' => CampaignObjective::Sales,
            'colour' => CampaignColour::Electric,
            'platforms' => [Platform::Instagram->value],
            'start_date' => now()->subDays(60)->toDateString(),
            'end_date' => now()->subDays(46)->toDateString(),
            'goal' => 'Clear winter stock in two weeks.',
        ]);
        $this->approvedBrief($wrapped, $owner);

        foreach (['Sale is on', 'Pick of the sale', 'Last chance'] as $index => $title) {
            $post = $this->showcasePost($wrapped, $owner, $instagram, $title, PostStatus::Published, -58 + ($index * 5));
            CampaignDeliverable::updateOrCreate(['campaign_id' => $wrapped->id, 'title' => $title], [
                'platform' => $instagram->platform,
                'planned_for' => now()->addDays(-58 + ($index * 5))->toDateString(),
                'planned_time' => '10:00',
                'post_id' => $post->id,
                'sort' => $index + 1,
            ]);
        }

        $wrapped->kpis()->updateOrCreate(['metric' => CampaignMetric::PostsPublished], ['target' => 3, 'sort' => 1]);
        $wrapped->kpis()->updateOrCreate(['metric' => CampaignMetric::OnTimeRate], ['target' => 90, 'sort' => 2]);

        $wrapped->report()->updateOrCreate([], [
            'summary' => 'Every sale post went out on time and the client signed each off first time.',
            'highlights' => ['3 of 3 posts published on schedule', 'Every post approved without changes', 'Last-chance story drove the busiest day of the sale'],
            'learnings' => 'Short countdown stories outperformed static posts. We’ll lead with them next season.',
            'next_steps' => 'Plan the spring launch with a teaser phase twice as long.',
            'kpi_snapshot' => app(CampaignKpiService::class)->results($wrapped->fresh() ?? $wrapped),
            'status' => CampaignReportStatus::Shared,
            'version' => 1,
            'shared_at' => now()->subDays(40),
            'shared_by' => $owner->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function showcaseCampaign(Client $client, User $owner, string $name, array $attributes): Campaign
    {
        return Campaign::updateOrCreate(
            ['org_id' => $client->org_id, 'client_id' => $client->id, 'name' => $name],
            [...$attributes, 'owner_id' => $owner->id],
        );
    }

    /**
     * @return list<CampaignPhase>
     */
    private function showcasePhases(Campaign $campaign): array
    {
        $start = Carbon::parse((string) $campaign->start_date?->toDateString());
        $split = [['Teaser', 0, 7, CampaignColour::Lilac], ['Launch', 7, 5, CampaignColour::Teal], ['Sustain', 12, 12, CampaignColour::Electric], ['Wrap', 24, 5, CampaignColour::Lime]];
        $phases = [];

        foreach ($split as $sort => [$name, $offset, $length, $colour]) {
            $phases[] = CampaignPhase::updateOrCreate(['campaign_id' => $campaign->id, 'name' => $name], [
                'starts_on' => $start->copy()->addDays($offset)->toDateString(),
                'ends_on' => $start->copy()->addDays($offset + $length - 1)->toDateString(),
                'colour' => $colour,
                'sort' => $sort + 1,
            ]);
        }

        return $phases;
    }

    private function approvedBrief(Campaign $campaign, User $owner): void
    {
        $brief = CampaignBrief::updateOrCreate(['campaign_id' => $campaign->id], [
            'background' => "{$campaign->client->name} has something new to say this season.",
            'objective_detail' => (string) $campaign->goal,
            'audience' => 'Existing fans and their friends, 25–45, who follow the brand for ideas, not ads.',
            'key_messages' => ['Made to last', 'New for this season', 'Available now'],
            'call_to_action' => 'Shop the range',
            'mandatories' => ['Price shown on product posts'],
            'avoid' => ['Discount talk before launch day'],
            'success_measures' => 'Every post out on time; first-time approval above 80%.',
            'status' => BriefStatus::Approved,
            'approved_at' => now()->subDays(10),
            'approved_by' => $owner->id,
        ]);

        if (! $brief->revisions()->exists()) {
            $content = $brief->content();
            $brief->revisions()->create([
                'number' => 1,
                'content' => $content,
                'content_hash' => CampaignBrief::hashContent($content),
                'sent_by' => $owner->id,
                'sent_at' => now()->subDays(12),
                'decision' => ApprovalDecision::Approved,
                'decided_by' => $owner->id,
                'decided_at' => now()->subDays(10),
            ]);
        }
    }

    private function showcasePost(Campaign $campaign, User $owner, SocialAccount $account, string $caption, PostStatus $status, int $day): Post
    {
        $post = Post::updateOrCreate(
            ['org_id' => $campaign->org_id, 'client_id' => $campaign->client_id, 'campaign_id' => $campaign->id, 'master_caption' => $caption],
            ['created_by' => $owner->id, 'status' => $status, 'approval_mode' => ApprovalMode::ClientRequired, 'hashtags' => $campaign->hashtags ?? []],
        );

        $at = now()->addDays($day)->setTime(10, 0);

        PostTarget::updateOrCreate(['post_id' => $post->id, 'social_account_id' => $account->id], [
            'scheduled_local_date' => $at->toDateString(),
            'scheduled_local_time' => '10:00:00',
            'scheduled_at_utc' => $at->copy()->setTimezone($account->timezone)->utc(),
        ]);

        if (in_array($status, [PostStatus::Published, PostStatus::Approved, PostStatus::Scheduled], true)) {
            $history = [[PostStatus::Draft, PostStatus::WaitingClient, $at->copy()->subDays(2)], [PostStatus::WaitingClient, PostStatus::Approved, $at->copy()->subDays(1)->subHours(6)]];

            if ($status === PostStatus::Published) {
                $history[] = [PostStatus::Publishing, PostStatus::Published, $at->copy()->addMinutes(2)];
            }

            foreach ($history as [$from, $to, $when]) {
                $log = PostActivityLog::firstOrCreate(['post_id' => $post->id, 'from_status' => $from, 'to_status' => $to], ['user_id' => $owner->id]);

                // Backdate so turnaround and on-time figures read like a real campaign.
                if ($log->wasRecentlyCreated) {
                    $log->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
                }
            }
        }

        return $post;
    }
}
