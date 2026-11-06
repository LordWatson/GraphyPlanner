<?php

namespace App\Actions\Deals;

use App\Contracts\Entitlements;
use App\Enums\DealStatus;
use App\Enums\Feature;
use App\Enums\Platform;
use App\Models\BrandDeal;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-5.2 — create or update a brand deal. One Action for both, because the editor submits the
 * whole deal either way (same shape as the bio page and the store).
 *
 * The tracker is a plan gate ({@see Feature::BrandDeals}, Creator Pro), checked here rather than in
 * the controller. Deliverables are normalised to one row per real platform so progress can be
 * counted against them without defending against junk later.
 */
class SaveBrandDealAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $brand, array $data, ?BrandDeal $deal = null): BrandDeal
    {
        if ($brand->organization !== null) {
            app(Entitlements::class)->ensure($brand->organization, Feature::BrandDeals);
        }

        return DB::transaction(function () use ($brand, $data, $deal): BrandDeal {
            $attributes = [
                'org_id' => $brand->org_id,
                'client_id' => $brand->id,
                'partner_name' => trim((string) $data['partner_name']),
                'partner_handle' => $this->clean($data['partner_handle'] ?? null),
                'status' => $data['status'] instanceof DealStatus
                    ? $data['status']
                    : DealStatus::from((string) ($data['status'] ?? DealStatus::Pitched->value)),
                'fee_minor' => isset($data['fee_minor']) ? max(0, (int) $data['fee_minor']) : null,
                'currency' => isset($data['currency'])
                    ? strtoupper((string) $data['currency'])
                    : ($brand->organization->default_currency ?? 'GBP'),
                'deliverables' => $this->deliverables($data['deliverables'] ?? []) ?: null,
                'starts_on' => $data['starts_on'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'notes' => $this->clean($data['notes'] ?? null),
                'show_on_media_kit' => (bool) ($data['show_on_media_kit'] ?? false),
            ];

            $deal = $deal === null
                ? BrandDeal::create($attributes)
                : tap($deal)->update($attributes);

            Log::info('Brand deal saved', [
                'brand_deal_id' => $deal->id,
                'org_id' => $deal->org_id,
                'client_id' => $brand->id,
                'status' => $deal->status->value,
                'deliverables' => count($deal->deliverables ?? []),
            ]);

            return $deal;
        });
    }

    /**
     * One row per real platform, quantity at least 1. A platform we don't publish to is dropped
     * rather than stored and ignored.
     *
     * @param  array<array-key, mixed>  $deliverables
     * @return list<array{platform: string, quantity: int}>
     */
    private function deliverables(array $deliverables): array
    {
        $clean = [];

        foreach ($deliverables as $deliverable) {
            $platform = Platform::tryFrom((string) (is_array($deliverable) ? ($deliverable['platform'] ?? '') : ''));
            $quantity = is_array($deliverable) ? (int) ($deliverable['quantity'] ?? 1) : 0;

            if ($platform !== null && ! isset($clean[$platform->value])) {
                $clean[$platform->value] = ['platform' => $platform->value, 'quantity' => max(1, $quantity)];
            }
        }

        return array_values($clean);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
