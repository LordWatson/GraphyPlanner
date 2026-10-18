<?php

namespace App\Actions\Campaigns;

use App\Contracts\CampaignFeatures;
use App\Enums\CampaignFeature;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignShareLink;
use App\Models\User;
use App\Services\CampaignHealthService;
use App\Services\CampaignReadinessService;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step C-1.2 — the props every campaign workspace tab shares through `CampaignLayout`: the
 * campaign (via `CampaignPresenter`), its derived health and readiness, the viewer's abilities,
 * and the tab counters. Counters come from one `withCount` query.
 *
 * Tabs whose phase hasn't shipped stay hidden (not disabled) — every tab listed here exists.
 */
class BuildCampaignWorkspaceHeaderAction
{
    public const TABS = ['overview', 'brief', 'plan', 'board', 'assets', 'approvals', 'results', 'activity'];

    public function __construct(
        private readonly CampaignHealthService $health,
        private readonly CampaignReadinessService $readiness,
        private readonly CampaignFeatures $features,
    ) {}

    /**
     * @return array{campaign: array<string, mixed>, tab: string, tabs: list<string>, can: array<string, bool>, counts: array<string, int>, shareLinks: list<array<string, mixed>>}
     */
    public function __invoke(Campaign $campaign, User $user, string $tab): array
    {
        $campaign->loadMissing(['client', 'owner:id,name', 'organization:id,default_timezone,default_currency']);
        $today = CampaignClock::today($campaign);

        $counts = Campaign::query()->withoutOrgScope()
            ->whereKey($campaign->id)
            ->withCount([
                'posts',
                'deliverables',
                'phases',
                'assets',
                'posts as awaiting_client_count' => fn (Builder $query) => $query->where('status', PostStatus::WaitingClient),
                'posts as in_review_count' => fn (Builder $query) => $query->where('status', PostStatus::InternalReview),
                'posts as scheduled_count' => fn (Builder $query) => $query->where('status', PostStatus::Scheduled),
            ])
            ->firstOrFail();

        $can = [
            'update' => $user->can('update', $campaign),
            'delete' => $user->can('delete', $campaign),
            'transition' => $user->can('transition', $campaign),
            'produce' => $user->can('produce', $campaign),
            'viewBilling' => $user->can('viewBilling', $campaign),
            'useAi' => (bool) config('ai.enabled', false) && in_array($user->role, [Role::Owner, Role::Strategist], true),
        ];
        // C-9.4: gated features fold into `can`, so pages never read the feature set directly.
        $features = $this->features->all($user->organization);
        $can['saveTemplate'] = $can['update'] && $features[CampaignFeature::Templates->value];
        $can['aiPlanner'] = $can['update'] && $can['useAi'] && $features[CampaignFeature::AiPlanner->value];
        $can['wrapReports'] = $features[CampaignFeature::WrapReports->value];
        $can['invoice'] = $can['viewBilling'] && $features[CampaignFeature::Invoicing->value];

        return [
            'campaign' => CampaignPresenter::forStaff($campaign, $user, $today, [
                'health' => $this->health->compute($campaign, $today)->toArray(),
                'readiness' => $this->readiness->compute($campaign, $today)->toArray(),
            ]),
            'tab' => $tab,
            'tabs' => self::TABS,
            'can' => $can,
            'counts' => [
                'posts' => (int) $counts->posts_count,
                'deliverables' => (int) $counts->deliverables_count,
                'phases' => (int) $counts->phases_count,
                'assets' => (int) $counts->assets_count,
                'awaitingClient' => (int) $counts->getAttribute('awaiting_client_count'),
                'inReview' => (int) $counts->getAttribute('in_review_count'),
                'scheduled' => (int) $counts->getAttribute('scheduled_count'),
            ],
            // C-5.4: live preview links (never the token itself, which is shown once on creation).
            'shareLinks' => $can['update'] ? array_values($campaign->shareLinks()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->with('creator:id,name')
                ->latest('id')
                ->get()
                ->map(fn (CampaignShareLink $link) => [
                    'id' => $link->id,
                    'created_by' => $link->creator?->name,
                    'created_at' => $link->created_at?->toIso8601String(),
                    'expires_at' => $link->expires_at->toIso8601String(),
                    'view_count' => $link->view_count,
                    'last_viewed_at' => $link->last_viewed_at?->toIso8601String(),
                ])
                ->all()) : [],
        ];
    }
}
