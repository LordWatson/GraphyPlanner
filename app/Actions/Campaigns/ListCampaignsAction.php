<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Organization;
use App\Services\CampaignHealthService;
use App\Services\CampaignReadinessService;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step C-1.1 — the org-wide campaigns hub. Client, status, timeline state, owner and objective
 * filter in SQL; health is derived (plan §1.3.2), so when it's filtered on, health is computed
 * for the SQL-filtered set (batched: a fixed handful of queries) before paging. Every page carries
 * health, readiness and post counts for its rows, computed per page in batches.
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
     * @param  array{client_id?: int|null, status?: string|null, timeline?: string|null, owner_id?: int|null, objective?: string|null, health?: string|null, search?: string|null, sort?: string|null}  $filters
     * @return array{page: LengthAwarePaginator<int, Campaign>, health: array<int, array<string, mixed>>, readiness: array<int, array<string, mixed>>, today: CarbonImmutable}
     */
    public function __invoke(Organization $organization, array $filters, int $page = 1): array
    {
        $today = CampaignClock::today($organization);
        $query = $this->query($organization, $filters, $today);

        if (filled($filters['health'] ?? null)) {
            $all = $query->get();
            $health = $this->health->computeMany($all, $today);
            $matching = $all->filter(fn (Campaign $campaign) => $health[$campaign->id]->status->value === $filters['health'])->values();

            $paginator = new LengthAwarePaginator(
                $matching->forPage($page, self::PER_PAGE)->values(),
                $matching->count(),
                self::PER_PAGE,
                $page,
                ['path' => LengthAwarePaginator::resolveCurrentPath()],
            );
        } else {
            $paginator = $query->paginate(self::PER_PAGE, page: $page);
        }

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
    private function query(Organization $organization, array $filters, CarbonImmutable $today): Builder
    {
        $date = $today->toDateString();

        return Campaign::query()
            ->where('org_id', $organization->id)
            ->with(['client:id,name', 'owner:id,name', 'organization:id,default_timezone'])
            ->withCount([
                'posts as posts_total',
                'posts as posts_published' => fn (Builder $query) => $query->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished]),
                'deliverables as deliverables_total',
            ])
            ->when($filters['client_id'] ?? null, fn (Builder $query, $clientId) => $query->where('client_id', $clientId))
            ->when($filters['owner_id'] ?? null, fn (Builder $query, $ownerId) => $query->where('owner_id', $ownerId))
            ->when($filters['objective'] ?? null, fn (Builder $query, $objective) => $query->where('objective', $objective))
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
            ->when($filters['timeline'] ?? null, fn (Builder $query, string $state) => match ($state) {
                CampaignTimeline::UNDATED => $query->whereNull('start_date'),
                CampaignTimeline::UPCOMING => $query->where('start_date', '>', $date),
                CampaignTimeline::ENDED => $query->whereNotNull('start_date')->where('end_date', '<', $date),
                CampaignTimeline::LIVE => $query->where('start_date', '<=', $date)
                    ->where(fn (Builder $inner) => $inner->whereNull('end_date')->orWhere('end_date', '>=', $date)),
                default => $query,
            })
            ->when(
                ($filters['sort'] ?? 'start_asc') === 'start_desc',
                fn (Builder $query) => $query->orderByRaw('start_date is null')->orderByDesc('start_date'),
                fn (Builder $query) => $query->orderByRaw('start_date is null')->orderBy('start_date'),
            )
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
