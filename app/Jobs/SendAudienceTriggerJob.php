<?php

namespace App\Jobs;

use App\Actions\Audience\SendAudienceTriggerAction;
use App\Enums\AudienceTrigger;
use App\Models\AudienceContact;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Step GTM-2.2 — run one automated trigger later, so its checks happen **at delivery time, not at
 * dispatch time**. That is the whole reason this exists: the purchase follow-up is delayed a day,
 * and somebody who unsubscribes in that day must not receive it. Deferring the mailable itself
 * would have frozen the decision at dispatch.
 *
 * A contact deleted in the meantime is a no-op, not a failure.
 */
class SendAudienceTriggerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $contactId,
        public readonly AudienceTrigger $trigger,
        public readonly string $reference = '',
    ) {
        $this->onQueue(Queues::MAIL);
    }

    public function handle(SendAudienceTriggerAction $send): void
    {
        $contact = AudienceContact::query()->withoutOrgScope()->find($this->contactId);

        if ($contact !== null) {
            $send($contact, $this->trigger, $this->reference);
        }
    }
}
