<?php

namespace App\Actions\Business;

use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Step CG-3.3 — the bio page's numbers for `/insights`: views over the window and the links people
 * actually tap, read from the {@see RecordBioEventAction} daily rollups. Returns null when the
 * brand has no bio page at all, so the page can leave the section out entirely.
 */
class GetBioInsightsAction
{
    private const DAYS = 30;

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Client $brand): ?array
    {
        $page = BioPage::query()->where('client_id', $brand->id)->first();

        if ($page === null) {
            return null;
        }

        $since = now()->subDays(self::DAYS - 1)->toDateString();

        $rows = DB::table('bio_events')
            ->where('bio_page_id', $page->id)
            ->where('date', '>=', $since)
            ->selectRaw('bio_block_id, sum(count) as total')
            ->groupBy('bio_block_id')
            ->get();

        $labels = BioBlock::query()
            ->where('bio_page_id', $page->id)
            ->get()
            ->mapWithKeys(fn (BioBlock $block) => [
                $block->id => $block->settings['label'] ?? $block->type->label(),
            ]);

        $clicks = $rows->filter(fn ($row) => $row->bio_block_id !== null);

        return [
            'handle' => $page->handle,
            'url' => $page->publicUrl(),
            'is_published' => $page->isPublished(),
            'period_days' => self::DAYS,
            'views' => (int) $rows->firstWhere('bio_block_id', null)?->total,
            'clicks' => (int) $clicks->sum('total'),
            'top_links' => $clicks
                ->sortByDesc('total')
                ->take(5)
                ->map(fn ($row) => [
                    'label' => $labels[$row->bio_block_id] ?? 'Removed link',
                    'clicks' => (int) $row->total,
                ])
                ->values()
                ->all(),
        ];
    }
}
