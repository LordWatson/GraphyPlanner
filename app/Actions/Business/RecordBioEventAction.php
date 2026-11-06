<?php

namespace App\Actions\Business;

use App\Models\BioPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step CG-3.3 — count a bio page view or block click into a per-day rollup. No IP, user agent,
 * session id or any other visitor data is stored: a row is only ever
 * (page, block or null, date, count).
 *
 * Two kinds of hit are deliberately not counted:
 *  - **Bots.** Crawlers, previews and link unfurlers (every messaging app fetches the page when a
 *    creator pastes the link), matched on the user agent.
 *  - **The owner's own visits.** A signed-in member of the page's own organization checking their
 *    page, or clicking through from the editor preview, would otherwise be most of the numbers.
 */
class RecordBioEventAction
{
    /**
     * Substrings that mark an automated fetch. Cheap and deliberately broad — over-excluding a
     * real visitor costs a count; counting every unfurl makes the numbers useless.
     */
    private const BOT_MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'preview', 'fetch', 'curl', 'wget', 'python-requests',
        'headless', 'facebookexternalhit', 'whatsapp', 'telegram', 'discord', 'slack', 'embedly',
        'lighthouse', 'pingdom', 'monitor',
    ];

    public function __invoke(Request $request, BioPage $page, ?int $blockId = null): void
    {
        if (! $this->shouldCount($request, $page)) {
            return;
        }

        $existing = DB::table('bio_events')
            ->where('bio_page_id', $page->id)
            ->where('date', now()->toDateString())
            ->when($blockId === null, fn ($query) => $query->whereNull('bio_block_id'))
            ->when($blockId !== null, fn ($query) => $query->where('bio_block_id', $blockId))
            ->first();

        if ($existing !== null) {
            DB::table('bio_events')->where('id', $existing->id)->increment('count');

            return;
        }

        DB::table('bio_events')->insert([
            'bio_page_id' => $page->id,
            'bio_block_id' => $blockId,
            'date' => now()->toDateString(),
            'count' => 1,
        ]);
    }

    public function shouldCount(Request $request, BioPage $page): bool
    {
        $agent = Str::lower((string) $request->userAgent());

        if ($agent === '' || Str::contains($agent, self::BOT_MARKERS)) {
            return false;
        }

        return $request->user()?->org_id !== $page->org_id;
    }
}
