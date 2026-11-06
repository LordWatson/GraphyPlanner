<?php

namespace App\Actions\Store;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.2 — create or update a product. One Action for both, because the editor submits the
 * whole product either way (same shape as the bio page editor).
 *
 * Three rules it owns:
 *
 *  - **The store is a plan gate** ({@see Feature::Store}), checked on the way in.
 *  - **Only the brand's own media** can be a cover or a download file; an asset id belonging to
 *    another brand is dropped rather than silently attached.
 *  - **Only the keys a type actually uses** are kept in `settings`, so a download can never carry
 *    booking availability (or the reverse) and the stored shape stays predictable.
 */
class SaveProductAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $brand, array $data, ?Product $product = null): Product
    {
        if ($brand->organization !== null) {
            app(Entitlements::class)->ensure($brand->organization, Feature::Store);
        }

        $type = $data['type'] instanceof ProductType ? $data['type'] : ProductType::from((string) $data['type']);

        return DB::transaction(function () use ($brand, $data, $product, $type): Product {
            $attributes = [
                'org_id' => $brand->org_id,
                'client_id' => $brand->id,
                'type' => $type,
                'title' => trim((string) $data['title']),
                'description' => $this->clean($data['description'] ?? null),
                'price_minor' => max(0, (int) ($data['price_minor'] ?? 0)),
                'currency' => strtoupper((string) ($data['currency'] ?? $brand->organization->default_currency ?? 'GBP')),
                'status' => $data['status'] instanceof ProductStatus
                    ? $data['status']
                    : ProductStatus::from((string) ($data['status'] ?? ProductStatus::Draft->value)),
                'cover_asset_id' => $this->ownAsset($brand, $data['cover_asset_id'] ?? null),
                'download_asset_id' => $type === ProductType::Download
                    ? $this->ownAsset($brand, $data['download_asset_id'] ?? null)
                    : null,
                'settings' => $this->settings($type, $data['settings'] ?? []) ?: null,
            ];

            $product = $product === null
                ? Product::create($attributes)
                : tap($product)->update($attributes);

            Log::info('Product saved', [
                'product_id' => $product->id,
                'org_id' => $product->org_id,
                'client_id' => $brand->id,
                'type' => $type->value,
                'status' => $product->status->value,
            ]);

            return $product;
        });
    }

    /**
     * The per-type `settings` payload, filtered to the keys that type renders.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function settings(ProductType $type, array $settings): array
    {
        $keys = match ($type) {
            ProductType::Download => ['download_limit', 'download_expiry_hours'],
            ProductType::Booking => ['availability'],
            ProductType::Tip => ['min_minor'],
            ProductType::Qa => ['answer_window_hours'],
        };

        return array_filter(
            array_intersect_key($settings, array_flip($keys)),
            fn ($value) => $value !== null && $value !== '',
        );
    }

    /**
     * An asset id only survives if it belongs to this brand.
     */
    private function ownAsset(Client $brand, mixed $assetId): ?int
    {
        if (! is_numeric($assetId)) {
            return null;
        }

        return Asset::query()
            ->withoutOrgScope()
            ->where('org_id', $brand->org_id)
            ->where('client_id', $brand->id)
            ->whereKey((int) $assetId)
            ->value('id');
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
