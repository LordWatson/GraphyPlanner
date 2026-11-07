<?php

namespace App\Actions\Audience;

use App\Models\AudienceBroadcast;
use App\Models\AudienceContact;
use App\Models\Client;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Step GTM-2.1 / GTM-2.3 — the `/audience` page payload: who is on the list, what has been sent,
 * and the products a broadcast can be segmented by.
 *
 * The counts are one grouped query, not three, and the contact list is capped: this page is for
 * looking after a list, not for paging through fifty thousand rows (`shortcut: the newest 200
 * contacts, upgrade to real pagination when a creator has more than that`).
 */
class GetAudienceAction
{
    public const MAX_CONTACTS = 200;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Client $brand): array
    {
        $contacts = AudienceContact::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->latest('id')
            ->limit(self::MAX_CONTACTS)
            ->get();

        $totals = DB::table('audience_contacts')
            ->where('client_id', $brand->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when consent_at is not null and unsubscribed_at is null then 1 else 0 end) as confirmed')
            ->selectRaw('sum(case when unsubscribed_at is not null then 1 else 0 end) as unsubscribed')
            ->first();

        return [
            'contacts' => array_values($contacts->map(fn (AudienceContact $contact) => [
                'id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->name,
                'source' => $contact->source->value,
                'source_label' => $contact->source->label(),
                'status' => match (true) {
                    $contact->unsubscribed_at !== null => 'unsubscribed',
                    $contact->consent_at !== null => 'confirmed',
                    default => 'pending',
                },
                'created_at' => $contact->created_at?->toDateString(),
            ])->all()),
            'totals' => [
                'total' => (int) ($totals->total ?? 0),
                'confirmed' => (int) ($totals->confirmed ?? 0),
                'unsubscribed' => (int) ($totals->unsubscribed ?? 0),
            ],
            'showing' => $contacts->count(),
            'max_contacts' => self::MAX_CONTACTS,
            'broadcasts' => array_values(AudienceBroadcast::query()
                ->withoutOrgScope()
                ->where('client_id', $brand->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (AudienceBroadcast $broadcast) => [
                    'id' => $broadcast->id,
                    'subject' => $broadcast->subject,
                    'segment' => $broadcast->segment,
                    'recipients' => $broadcast->recipients,
                    'sent_at' => $broadcast->sent_at?->toDateString(),
                ])->all()),
            'products' => array_values(Product::query()
                ->withoutOrgScope()
                ->where('client_id', $brand->id)
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Product $product) => ['id' => $product->id, 'title' => $product->title])
                ->all()),
        ];
    }
}
