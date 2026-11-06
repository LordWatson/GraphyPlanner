<?php

namespace App\Actions\Deals;

use App\Enums\DealStatus;
use App\Enums\PostStatus;
use App\Models\BrandDeal;
use App\Models\Client;
use App\Models\Post;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Facades\DB;

/**
 * Step CG-5.2 — the deals a brand has, with **derived** progress: how many of each deliverable have
 * actually been published. Nothing about progress is stored, so the tracker can never claim a post
 * went out when it didn't (or lag behind one that did).
 *
 * "Published" means a post linked to the deal whose status is published (or partially published)
 * and that has a live channel on that platform — the same definition the media kit and insights
 * use, so the three agree.
 */
class GetBrandDealsAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(Client $brand): array
    {
        $deals = BrandDeal::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->with(['posts' => fn ($query) => $query->select(['id', 'brand_deal_id', 'status', 'master_caption'])])
            ->orderByRaw("status IN ('paid', 'declined')")
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->get();

        $published = $this->publishedCountsByPlatform(
            array_values($deals->pluck('id')->map(fn ($id) => (int) $id)->all()),
        );

        return array_values($deals->map(fn (BrandDeal $deal) => [
            'id' => $deal->id,
            'partner_name' => $deal->partner_name,
            'partner_handle' => $deal->partner_handle,
            'status' => $deal->status->value,
            'status_label' => $deal->status->label(),
            'fee_minor' => $deal->fee_minor,
            'currency' => $deal->currency,
            'starts_on' => $deal->starts_on?->toDateString(),
            'due_on' => $deal->due_on?->toDateString(),
            'is_overdue' => $deal->isOverdue(),
            'notes' => $deal->notes,
            'contact_email' => $deal->contact_email,
            'show_on_media_kit' => $deal->show_on_media_kit,
            'posts_count' => $deal->posts->count(),
            'deliverables' => array_map(fn (array $target) => [
                'platform' => $target['platform']->value,
                'platform_label' => $target['platform']->label(),
                'quantity' => $target['quantity'],
                'published' => min(
                    $target['quantity'],
                    $published[$deal->id][$target['platform']->value] ?? 0,
                ),
            ], $deal->deliverableTargets()),
        ])->all());
    }

    /**
     * The brand's past partners for the media kit (CG-5.2 feeds B-8.4's empty section): only deals
     * that actually happened **and** were opted in. Off by default — a sponsor relationship isn't
     * ours to publish.
     *
     * @return list<array{name: string, handle: string|null, year: int|null}>
     */
    public function partnersFor(Client $brand): array
    {
        return array_values(BrandDeal::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->where('show_on_media_kit', true)
            ->whereIn('status', [DealStatus::Delivered, DealStatus::Paid])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->map(fn (BrandDeal $deal) => [
                'name' => $deal->partner_name,
                'handle' => $deal->partner_handle,
                'year' => ($deal->starts_on ?? $deal->created_at)?->year,
            ])
            ->all());
    }

    /**
     * Published post counts per deal, per platform, in one query.
     *
     * @param  list<int>  $dealIds
     * @return array<int, array<string, int>>
     */
    private function publishedCountsByPlatform(array $dealIds): array
    {
        if ($dealIds === []) {
            return [];
        }

        // The query builder, not the model: these are raw aggregates, not PostTargets.
        $rows = DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->whereIn('posts.brand_deal_id', $dealIds)
            ->whereIn('posts.status', [PostStatus::Published->value, PostStatus::PartiallyPublished->value])
            ->selectRaw('posts.brand_deal_id as deal_id, social_accounts.platform as platform, COUNT(DISTINCT posts.id) as total')
            ->groupBy('posts.brand_deal_id', 'social_accounts.platform')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->deal_id][(string) $row->platform] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * The deals a post may be linked to in the editor: this brand's own, open or delivered (a paid
     * or declined deal is history).
     *
     * @return list<array{id: int, partner_name: string}>
     */
    public function linkOptionsFor(Post $post): array
    {
        return array_values(BrandDeal::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('client_id', $post->client_id)
            ->whereIn('status', [DealStatus::Enquiry, DealStatus::Pitched, DealStatus::Agreed, DealStatus::Delivered])
            ->orderBy('partner_name')
            ->get(['id', 'partner_name'])
            ->map(fn (BrandDeal $deal) => ['id' => $deal->id, 'partner_name' => $deal->partner_name])
            ->all());
    }
}
