<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step I-1.5 — every stored file that belongs to a set of clients: asset originals, Brand Brain
 * persona PDFs, invoice PDFs, AI Image Studio previews and Brand Brain reference copies. Used by
 * the client and organization purges to remove files from storage (through `AssetStorage`) before
 * the rows cascade away, so purging never leaves orphaned files behind.
 *
 * Queries go through the query builder on purpose: they must see soft-deleted rows too.
 */
class StoredFileInventory
{
    /**
     * @param  array<int, int>  $clientIds
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function forClients(array $clientIds): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        $assetIds = DB::table('assets')->whereIn('client_id', $clientIds)->pluck('id')->all();

        return collect()
            ->merge($this->pairs(DB::table('assets')->whereIn('client_id', $clientIds), 'disk', 'path'))
            ->merge($this->pairs(DB::table('brand_brains')->whereIn('client_id', $clientIds), 'persona_disk', 'persona_path'))
            ->merge($this->pairs(DB::table('invoices')->whereIn('client_id', $clientIds), 'pdf_disk', 'pdf_path'))
            ->merge($this->pairs(
                DB::table('ai_image_variants')->whereIn('generation_id', DB::table('ai_image_generations')->whereIn('client_id', $clientIds)->select('id')),
                'preview_disk',
                'preview_path',
            ))
            ->merge($this->pairs(DB::table('brand_brain_reference_assets')->whereIn('asset_id', $assetIds), 'normalized_disk', 'normalized_path'))
            ->unique(fn (array $file) => $file['disk'].'|'.$file['path'])
            ->values();
    }

    /**
     * @return Collection<int, array{disk: string, path: string}>
     */
    private function pairs(Builder $query, string $diskColumn, string $pathColumn): Collection
    {
        return $query
            ->whereNotNull($diskColumn)
            ->whereNotNull($pathColumn)
            ->get([$diskColumn, $pathColumn])
            ->map(fn (object $row): array => ['disk' => (string) $row->{$diskColumn}, 'path' => (string) $row->{$pathColumn}]);
    }
}
