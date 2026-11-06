<?php

namespace App\Actions\Business;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-3.2 — save the whole bio page in one write: its details and the full ordered block list
 * (validated by `SaveBioPageRequest`). The editor submits the page as it stands, so adding,
 * editing, hiding, reordering and removing a block are all the same operation — one transaction, no
 * half-reordered page if a block write fails.
 *
 * Step CG-2.2: the public page is a plan gate. Anyone can build a draft; only a plan with
 * {@see Feature::BioPage} can publish one, so the gate is checked on the publish, not the edit.
 */
class SaveBioPageAction
{
    /**
     * @param  array<string, mixed>  $data  `handle`, `title`, `bio`, `theme`, `is_published`, `blocks`
     */
    public function __invoke(Client $brand, array $data): BioPage
    {
        $publishing = (bool) ($data['is_published'] ?? false);

        if ($publishing && $brand->organization !== null) {
            app(Entitlements::class)->ensure($brand->organization, Feature::BioPage);
        }

        return DB::transaction(function () use ($brand, $data, $publishing) {
            $page = BioPage::query()->where('client_id', $brand->id)->first();

            $attributes = [
                'org_id' => $brand->org_id,
                'client_id' => $brand->id,
                'handle' => strtolower(trim((string) $data['handle'])),
                'title' => $this->clean($data['title'] ?? null),
                'bio' => $this->clean($data['bio'] ?? null),
                'theme' => $data['theme'],
                // Re-publishing keeps the original date; unpublishing clears it (the page 404s).
                'published_at' => $publishing ? ($page->published_at ?? now()) : null,
            ];

            if ($page === null) {
                $page = BioPage::create($attributes);
            } else {
                $page->update($attributes);
            }

            $this->syncBlocks($page, $data['blocks'] ?? []);

            Log::info('Bio page saved', [
                'bio_page_id' => $page->id,
                'org_id' => $page->org_id,
                'client_id' => $brand->id,
                'blocks' => count($data['blocks'] ?? []),
                'published' => $publishing,
            ]);

            return $page->load('blocks');
        });
    }

    /**
     * Make the stored blocks match the submitted list exactly, in submitted order. A block the
     * editor dropped is deleted (taking its click rollups with it); an `id` that isn't this page's
     * is treated as new, so one brand can never reorder or rewrite another's block.
     *
     * @param  array<array-key, array<string, mixed>>  $blocks
     */
    private function syncBlocks(BioPage $page, array $blocks): void
    {
        $existing = $page->blocks()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($blocks) as $position => $block) {
            $attributes = [
                'type' => $block['type'],
                'position' => $position,
                // Only the keys a block type actually renders are stored.
                'settings' => array_intersect_key($block['settings'] ?? [], array_flip(['label', 'url', 'limit', 'product_id'])) ?: null,
                'is_visible' => (bool) ($block['is_visible'] ?? true),
            ];

            $current = isset($block['id']) ? $existing->get((int) $block['id']) : null;

            if ($current instanceof BioBlock) {
                $current->update($attributes);
                $kept[] = $current->id;

                continue;
            }

            $kept[] = $page->blocks()->create($attributes)->id;
        }

        $page->blocks()->whereNotIn('id', $kept ?: [0])->delete();
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
