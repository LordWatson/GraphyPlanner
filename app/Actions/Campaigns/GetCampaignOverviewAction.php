<?php

namespace App\Actions\Campaigns;

use App\Enums\BriefStatus;
use App\Enums\ConnectionStatus;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Campaigns\CampaignPresenter;
use Illuminate\Support\Facades\DB;

/**
 * Step C-1.2 — the Overview tab's own data: the production funnel (one grouped count), the next
 * five posts going out, and the brief's sign-off state. Health, readiness and the header counters
 * come from `BuildCampaignWorkspaceHeaderAction`.
 */
class GetCampaignOverviewAction
{
    public const UPCOMING_LIMIT = 5;

    /**
     * @return array{launchChecklist: list<array{key: string, label: string, done: bool, hint: string}>, funnel: array<string, int>, upcoming: list<array<string, mixed>>, brief: array<string, mixed>|null}
     */
    public function __invoke(Campaign $campaign): array
    {
        $byStatus = $campaign->posts()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $sum = fn (array $statuses): int => (int) collect($statuses)->sum(fn (PostStatus $status) => $byStatus->get($status->value, 0));

        $upcomingIds = DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->where('posts.campaign_id', $campaign->id)
            ->whereNull('posts.deleted_at')
            ->whereIn('posts.status', [PostStatus::Scheduled->value, PostStatus::Approved->value])
            ->where('post_targets.scheduled_at_utc', '>=', now())
            ->groupBy('posts.id')
            ->orderByRaw('min(post_targets.scheduled_at_utc)')
            ->limit(self::UPCOMING_LIMIT)
            ->pluck('posts.id')
            ->all();

        $upcoming = array_values(Post::query()
            ->whereIn('id', $upcomingIds)
            ->with(['targets.socialAccount', 'deliverable'])
            ->get()
            ->sortBy(fn (Post $post) => array_search($post->id, $upcomingIds, true))
            ->map(fn (Post $post) => CampaignPresenter::post($post))
            ->all());

        $brief = $campaign->brief;

        return [
            'launchChecklist' => $this->launchChecklist($campaign),
            'funnel' => [
                'planned' => $campaign->deliverables()->count(),
                'in_production' => $sum(PostStatus::preApproval()),
                'approved' => $sum([PostStatus::Approved]),
                'scheduled' => $sum([PostStatus::Scheduled, PostStatus::Publishing]),
                'published' => $sum([PostStatus::Published, PostStatus::PartiallyPublished]),
            ],
            'upcoming' => $upcoming,
            'brief' => $brief === null ? null : [
                'status' => $brief->status->value,
                'status_label' => $brief->status->label(),
                'approved_at' => $brief->approved_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Step C-6.3 — the launch checklist, every item ticked from live state (derived, never stored):
     * brief signed off, the first phase's work approved, every planned platform connected, the
     * campaign link set (when its posts carry one), and every asset requirement met.
     *
     * @return list<array{key: string, label: string, done: bool, hint: string}>
     */
    private function launchChecklist(Campaign $campaign): array
    {
        $firstPhase = $campaign->phases()->first();
        $launchDeliverables = $campaign->deliverables()
            ->when($firstPhase, fn ($query) => $query->where('campaign_phase_id', $firstPhase?->id))
            ->with('post:id,status')
            ->get();
        $launchReady = $launchDeliverables->every(fn ($deliverable) => $deliverable->state()->isReady());

        $platforms = $campaign->deliverables()->get(['platform'])->map(fn ($deliverable) => $deliverable->platform->label())->unique()->values()->all();
        $connected = SocialAccount::query()->withoutOrgScope()
            ->where('client_id', $campaign->client_id)
            ->where('connection_status', ConnectionStatus::Connected)
            ->get(['platform'])
            ->map(fn (SocialAccount $account) => $account->platform->label())
            ->unique()
            ->all();
        $missingPlatforms = array_values(array_diff($platforms, $connected));

        $unmet = $campaign->deliverables()->get(['asset_requirements'])->sum(fn ($deliverable) => $deliverable->unmetRequirementCount());

        return [
            ['key' => 'brief', 'label' => 'Brief signed off', 'done' => $campaign->brief?->status === BriefStatus::Approved, 'hint' => 'The client approves it in their portal.'],
            ['key' => 'launch_posts', 'label' => $firstPhase ? "{$firstPhase->name} posts approved" : 'Planned posts approved', 'done' => $launchDeliverables->isNotEmpty() && $launchReady, 'hint' => $launchDeliverables->isEmpty() ? 'Nothing planned for it yet.' : 'Everything in the first phase approved or further along.'],
            ['key' => 'accounts', 'label' => 'Accounts connected', 'done' => $platforms !== [] && $missingPlatforms === [], 'hint' => $missingPlatforms === [] ? 'Every planned platform has a connected account.' : 'Connect '.implode(', ', $missingPlatforms).'.'],
            ['key' => 'link', 'label' => 'Campaign link set', 'done' => filled($campaign->default_link_url), 'hint' => 'Posts can carry it with UTM tags.'],
            ['key' => 'assets', 'label' => 'Assets ready', 'done' => $unmet === 0, 'hint' => $unmet === 0 ? 'No outstanding asset requirements.' : "{$unmet} asset ".($unmet === 1 ? 'requirement' : 'requirements').' still open.'],
        ];
    }
}
