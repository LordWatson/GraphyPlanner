<?php

namespace App\Actions\Deals;

use App\Enums\DealStatus;
use App\Models\BrandDeal;
use App\Models\MediaKit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step CG-5.3 — a sponsor's enquiry from the public media kit becomes a **draft** deal
 * ({@see DealStatus::Enquiry}) on that brand's tracker. Nothing else: no email to a stranger's
 * address, no auto-reply, no status the creator didn't choose.
 *
 * Deliberately *not* plan-gated. The enquiry form only appears on a kit whose owner switched it on,
 * and that switch already needs the gated tracker to be of any use; refusing an enquiry because a
 * plan lapsed would lose a creator real work for a billing reason.
 */
class RecordDealEnquiryAction
{
    /**
     * @param  array{name: string, email: string, company?: string|null, message?: string|null}  $data
     */
    public function __invoke(MediaKit $kit, array $data): BrandDeal
    {
        $brand = $kit->client;
        $company = trim((string) ($data['company'] ?? ''));
        $name = trim($data['name']);

        $deal = BrandDeal::create([
            'org_id' => $kit->org_id,
            'client_id' => $kit->client_id,
            // The company is who the deal is with; the person is recorded in the notes.
            'partner_name' => Str::limit($company !== '' ? $company : $name, 120, ''),
            'status' => DealStatus::Enquiry,
            'currency' => $brand?->organization->default_currency ?? 'GBP',
            'contact_email' => mb_strtolower(trim($data['email'])),
            'notes' => $this->notes($name, $data['message'] ?? null),
        ]);

        Log::info('Media kit enquiry recorded', [
            'brand_deal_id' => $deal->id,
            'org_id' => $deal->org_id,
            'client_id' => $deal->client_id,
        ]);

        return $deal;
    }

    private function notes(string $name, ?string $message): string
    {
        $message = trim((string) $message);

        return trim("From {$name} via your media kit.".($message === '' ? '' : "\n\n".$message));
    }
}
