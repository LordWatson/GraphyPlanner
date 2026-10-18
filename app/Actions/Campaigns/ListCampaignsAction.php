<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Organization;
use App\Services\CampaignHealthService;
use App\Services\CampaignReadinessService;
use App\Support\Campaigns\CampaignClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step C-1.1 — the org-wide campaigns hub, filtered by search, client and status in SQL and
 * ordered by start date. Every page carries health, readiness and post counts for its rows,
 * computed per page in batches.
 *
 * Tenant scoping is the `OrganizationScope` on `Campaign` plus an explicit `org_id` filter.
 */
class ListCampaignsAction
{
    public const PER_PAGE = 20;

    public const TIMELINE_DAYS = 90;

    public function __construct(
        private readonly CampaignHealthService $health,
        private readonly CampaignReadinessService $readiness,
    ) {}

    /**
     * @param  array{client_id?: int|null, status?: string|null, search?: string|null}  $filters
     * @return array{page: LengthAwarePaginator<int, Campaign>, health: array<int, array<string, mixed>>, readiness: array<int, array<string, mixed>>, today: CarbonImmutable}
     */
    public function __invoke(Organization $organization, array $filters, int $page = 1): array
    {
        $today = CampaignClock::today($organization);
        $paginator = $this->query($organization, $filters)->paginate(self::PER_PAGE, page: $page);

        /** @var Collection<int, Campaign> $rows */
        $rows = new Collection($paginator->items());

        return [
            'page' => $paginator,
            'health' => array_map(fn ($result) => $result->toArray(), $this->health->computeMany($rows, $today)),
            'readiness' => array_map(fn ($result) => $result->toArray(), $this->readiness->computeMany($rows, $today)),
            'today' => $today,
        ];
    }

    /**
     * Campaigns overlapping the next 90 days (from 14 days ago), for the timeline view. Archived
     * campaigns are left off; the client filter still applies.
     *
     * @param  array{client_id?: int|null}  $filters
     * @return Collection<int, Campaign>
     */
    public function timeline(Organization $organization, array $filters): Collection
    {
        $today = CampaignClock::today($organization);
        $from = $today->subDays(14)->toDateString();
        $to = $today->addDays(self::TIMELINE_DAYS)->toDateString();

        return Campaign::query()
            ->where('org_id', $organization->id)
            ->where('status', '!=', CampaignStatus::Archived)
            ->whereNotNull('start_date')
            ->where('start_date', '<=', $to)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $from))
            ->when($filters['client_id'] ?? null, fn (Builder $query, $clientId) => $query->where('client_id', $clientId))
            ->with('client:id,name')
            ->orderBy('start_date')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Campaign>
     */
    private function query(Organization $organization, array $filters): Builder
    {
        return Campaign::query()
            ->where('org_id', $organization->id)
            ->with(['client:id,name', 'owner:id,name', 'organization:id,default_timezone'])
            ->withCount([
                'posts as posts_total',
                'posts as posts_published' => fn (Builder $query) => $query->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished]),
                'deliverables as deliverables_total',
            ])
            ->when($filters['client_id'] ?? null, fn (Builder $query, $clientId) => $query->where('client_id', $clientId))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, $status) => $query->where('status', $status),
                // Archived campaigns only show up when asked for.
                fn (Builder $query) => $query->where('status', '!=', CampaignStatus::Archived),
            )
            ->orderByRaw('start_date is null')
            ->orderBy('start_date')
            ->orderBy('id');
    }

    /**
     * The hub's StatStrip: live now, launching within 14 days, at risk (red health) and ended this
     * month. Three counting queries plus one batched health pass over open campaigns.
     *
     * @return array{live: int, launching: int, at_risk: int, ended_this_month: int}
     */
    public function stats(Organization $organization): array
    {
        $today = CampaignClock::today($organization);
        $date = $today->toDateString();
        $base = fn () => Campaign::query()->where('org_id', $organization->id)->where('status', '!=', CampaignStatus::Archived);

        $open = $base()->whereIn('status', CampaignStatus::open())->get();
        $health = $this->health->computeMany($open, $today);

        return [
            'live' => $base()->where('start_date', '<=', $date)
                ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $date))
                ->count(),
            'launching' => $base()->where('start_date', '>', $date)->where('start_date', '<=', $today->addDays(14)->toDateString())->count(),
            'at_risk' => count(array_filter($health, fn ($result) => $result->status->value === 'red')),
            'ended_this_month' => $base()->whereBetween('end_date', [$today->startOfMonth()->toDateString(), $today->subDay()->toDateString()])->count(),
        ];
    }
}
