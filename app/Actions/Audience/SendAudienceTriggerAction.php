<?php

namespace App\Actions\Audience;

use App\Contracts\Entitlements;
use App\Enums\AudienceTrigger;
use App\Enums\Feature;
use App\Mail\AudienceMessageMail;
use App\Models\AudienceContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Step GTM-2.2 — send one automated email, at most once, to one contact.
 *
 * Four refusals, in the order they matter, each returning `false` rather than throwing: this runs
 * from a queued job behind a webhook or a sweep, where an exception would retry forever and an
 * upgrade prompt has nobody to show itself to.
 *
 *  1. **The plan.** `Feature::EmailAutomation` is checked with `allows()`, not `ensure()`.
 *  2. **The unsubscribe**, which stops every trigger including the transactional pair.
 *  3. **Consent**, for the triggers that need it ({@see AudienceTrigger::requiresConsent()}).
 *  4. **Having already sent it.** The unique index on `audience_sends` is the lock, claimed with
 *     `insertOrIgnore`: 0 rows inserted means somebody (a retry, a replayed webhook, a second
 *     sweep) got there first, and we stop. Claiming it *before* handing the mailable to the queue
 *     is deliberate — a duplicate email cannot be taken back, a missing one can be re-sent.
 */
class SendAudienceTriggerAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @param  string  $reference  what this send is about (an order id), so one contact can get a
     *                             follow-up per order. `''` for a trigger that fires once ever.
     */
    public function __invoke(AudienceContact $contact, AudienceTrigger $trigger, string $reference = ''): bool
    {
        $context = [
            'org_id' => $contact->org_id,
            'contact_id' => $contact->id,
            'trigger' => $trigger->value,
            'reference' => $reference,
        ];

        $organization = $contact->organization;

        if ($organization !== null && ! $this->entitlements->allows($organization, Feature::EmailAutomation)) {
            Log::info('Audience trigger skipped: plan does not include email automation', $context);

            return false;
        }

        if (! $contact->isMailable() || ($trigger->requiresConsent() && ! $contact->isMarketable())) {
            Log::info('Audience trigger skipped: contact is not mailable for it', $context);

            return false;
        }

        $claimed = DB::table('audience_sends')->insertOrIgnore([
            'org_id' => $contact->org_id,
            'audience_contact_id' => $contact->id,
            'trigger' => $trigger->value,
            'reference' => $reference,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        if (! $claimed) {
            Log::info('Audience trigger skipped: already sent', $context);

            return false;
        }

        Mail::to($contact->email)->send(new AudienceMessageMail($contact, $trigger));

        Log::info('Audience trigger sent', $context);

        return true;
    }
}
