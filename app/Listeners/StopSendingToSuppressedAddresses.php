<?php

namespace App\Listeners;

use App\Models\SuppressedEmail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Drops suppressed recipients from every outgoing message, and cancels the send when none are
 * left.
 *
 * One listener rather than a check in each of the twenty-odd places that send mail: `MessageSending`
 * is the single point every path goes through — audience broadcasts, triggers, approval requests,
 * invitations, invoices, receipts — so there is nowhere to forget. Returning false from a
 * `MessageSending` listener cancels the send.
 *
 * This applies to transactional mail too, deliberately. An address that has hard-bounced is not
 * going to receive an approval request either, and continuing to send to it is what costs the
 * domain the reputation every other message depends on.
 */
class StopSendingToSuppressedAddresses
{
    /**
     * Returns **null** to allow and `false` to cancel — never `true`.
     *
     * `MessageSending` is dispatched with `until()`, which stops at the first listener returning
     * anything non-null. Returning true to mean "fine by me" would therefore silence every other
     * `MessageSending` listener, now or later. Null is the only correct way to say "no opinion".
     */
    public function handle(MessageSending $event): ?bool
    {
        $to = $event->message->getTo();

        if ($to === []) {
            return null;
        }

        $allowed = array_values(array_filter(
            $to,
            fn (Address $address) => ! SuppressedEmail::isSuppressed($address->getAddress()),
        ));

        if (count($allowed) === count($to)) {
            return null;
        }

        if ($allowed === []) {
            Log::info('Mail not sent: every recipient is suppressed', [
                'suppressed' => count($to),
            ]);

            return false;
        }

        // Some recipients are still reachable, so the message goes — just not to the ones that
        // bounced or complained.
        $event->message->to(...$allowed);

        Log::info('Suppressed recipients removed from a message', [
            'removed' => count($to) - count($allowed),
            'remaining' => count($allowed),
        ]);

        return null;
    }
}
