<?php

namespace App\Actions\Audience;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\OrderStatus;
use App\Mail\AudienceMessageMail;
use App\Models\AudienceBroadcast;
use App\Models\AudienceContact;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Step GTM-2.3 — one email the owner wrote, to one segment, sent now.
 *
 * **A person sends. Nothing else can.** This Action takes the `User` who sent it and is only ever
 * reached from an authenticated request; there is no job, command or schedule that can originate a
 * broadcast, which is the whole guard against an AI draft mailing itself out.
 *
 * A broadcast is marketing, so unlike the automated triggers it goes **only** to confirmed
 * contacts ({@see AudienceContact::marketable()}). Unlike them it also uses `ensure()`, not
 * `allows()`: there is a person here to show the upgrade prompt to.
 */
class SendAudienceBroadcastAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @param  array{subject: string, body: string, segment: string, product_id?: int|null}  $data
     */
    public function __invoke(Client $brand, User $sender, array $data): AudienceBroadcast
    {
        if ($brand->organization !== null) {
            $this->entitlements->ensure($brand->organization, Feature::EmailAutomation);
        }

        $segment = $data['segment'] === AudienceBroadcast::SEGMENT_PRODUCT
            ? AudienceBroadcast::SEGMENT_PRODUCT
            : AudienceBroadcast::SEGMENT_ALL;

        $product = $segment === AudienceBroadcast::SEGMENT_PRODUCT
            ? Product::query()->withoutOrgScope()->where('client_id', $brand->id)->find((int) ($data['product_id'] ?? 0))
            : null;

        if ($segment === AudienceBroadcast::SEGMENT_PRODUCT && $product === null) {
            throw ValidationException::withMessages([
                'product_id' => __('Pick one of your own products for that segment.'),
            ]);
        }

        $recipients = $this->recipients($brand, $segment, $product);

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'segment' => __('Nobody in that segment has confirmed their email yet.'),
            ]);
        }

        $broadcast = DB::transaction(fn (): AudienceBroadcast => AudienceBroadcast::create([
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'created_by' => $sender->id,
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'segment' => $segment,
            'product_id' => $product?->id,
            'sent_at' => now(),
            'recipients' => $recipients->count(),
        ]));

        // After the commit: the broadcast is a record of what went out, and a mail failure on one
        // address must not roll back the others or lose the record of them.
        foreach ($recipients as $contact) {
            Mail::to($contact->email)->send(new AudienceMessageMail($contact, null, $broadcast));
        }

        Log::info('Audience broadcast sent', [
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'broadcast_id' => $broadcast->id,
            'sender_id' => $sender->id,
            'segment' => $segment,
            'recipients' => $broadcast->recipients,
        ]);

        return $broadcast;
    }

    /**
     * Who the segment resolves to, right now. An unsubscribed or unconfirmed contact is in no
     * segment, so there is no way to compose one that reaches them.
     *
     * @return Collection<int, AudienceContact>
     */
    private function recipients(Client $brand, string $segment, ?Product $product): Collection
    {
        $query = AudienceContact::query()
            ->withoutOrgScope()
            ->where('client_id', $brand->id)
            ->marketable();

        if ($product !== null) {
            $query->whereIn('email', Order::query()
                ->withoutOrgScope()
                ->where('product_id', $product->id)
                ->whereIn('status', [OrderStatus::Paid, OrderStatus::Fulfilled])
                ->select('buyer_email'));
        }

        return $query->orderBy('id')->get();
    }
}
