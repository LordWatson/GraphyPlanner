<?php

namespace App\Actions\Business;

use App\Models\Client;
use App\Models\MediaKit;
use Illuminate\Support\Facades\Log;

/**
 * Step B-8.4 — save the editable part of a brand's media kit: the bio, and (Step CG-5.3) the
 * optional rate card and whether the public kit takes enquiries. Find-or-create so the first save
 * creates the row; never touches the share state.
 */
class UpdateMediaKitAction
{
    public function __construct(private readonly BuildMediaKitAction $build) {}

    /**
     * @param  array<string, mixed>  $data  `bio`, `rates`, `enquiries_enabled`
     */
    public function __invoke(Client $brand, array $data): MediaKit
    {
        $kit = $this->build->kitFor($brand);

        $kit->update([
            'bio' => $this->clean($data['bio'] ?? null),
            'rates' => $this->rates($data['rates'] ?? []) ?: null,
            'enquiries_enabled' => (bool) ($data['enquiries_enabled'] ?? false),
        ]);

        Log::info('Media kit updated', [
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'rates' => count($kit->rates ?? []),
            'enquiries_enabled' => $kit->enquiries_enabled,
        ]);

        return $kit;
    }

    /**
     * A rate needs a label and a real amount; anything else is dropped rather than shown to a
     * sponsor as "— ".
     *
     * @param  array<array-key, mixed>  $rates
     * @return list<array{label: string, amount_minor: int}>
     */
    private function rates(array $rates): array
    {
        $clean = [];

        foreach ($rates as $rate) {
            $label = is_array($rate) ? trim((string) ($rate['label'] ?? '')) : '';
            $amount = is_array($rate) ? (int) ($rate['amount_minor'] ?? 0) : 0;

            if ($label !== '' && $amount > 0) {
                $clean[] = ['label' => $label, 'amount_minor' => $amount];
            }
        }

        return array_slice($clean, 0, 12);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
