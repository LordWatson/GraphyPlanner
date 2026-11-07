<?php

namespace App\Support\Audience;

use App\Enums\OrderStatus;
use App\Models\AudienceBroadcast;
use App\Models\AudienceContact;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a broadcast's segment resolves to, as a query.
 *
 * Extracted so the Action (which counts recipients to show the sender, and refuses an empty
 * segment) and `SendAudienceBroadcastJob` (which sends to them in chunks, possibly minutes later)
 * cannot drift apart. Two copies of "who is in this segment" is the kind of duplication that ends
 * with an unsubscribed contact receiving something.
 *
 * Always a query, never a list: a 5,000-contact segment has no business being materialised to be
 * counted, and the job pages through it.
 */
final class BroadcastRecipients
{
    /**
     * @return Builder<AudienceContact>
     */
    public static function query(int $clientId, string $segment, ?int $productId): Builder
    {
        $query = AudienceContact::query()
            ->withoutOrgScope()
            ->where('client_id', $clientId)
            // Marketing, so confirmed contacts only — an unsubscribed or unconfirmed contact is in
            // no segment, which is what makes a bad segment impossible to compose rather than
            // something to remember.
            ->marketable();

        if ($segment === AudienceBroadcast::SEGMENT_PRODUCT && $productId !== null) {
            $query->whereIn('email', Order::query()
                ->withoutOrgScope()
                ->where('product_id', $productId)
                ->whereIn('status', [OrderStatus::Paid, OrderStatus::Fulfilled])
                ->select('buyer_email'));
        }

        return $query->orderBy('id');
    }

    /**
     * The same query for a broadcast that has already been committed.
     *
     * @return Builder<AudienceContact>
     */
    public static function forBroadcast(AudienceBroadcast $broadcast): Builder
    {
        return self::query($broadcast->client_id, $broadcast->segment, $broadcast->product_id);
    }
}
