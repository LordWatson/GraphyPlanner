<?php

namespace App\Actions\Audience;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Jobs\SendAudienceBroadcastJob;
use App\Models\AudienceBroadcast;
use App\Models\AudienceContact;
use App\Models\Client;
use App\Models\Product;
use App\Models\User;
use App\Support\Audience\BroadcastRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $recipients = BroadcastRecipients::query($brand->id, $segment, $product?->id);
        $recipientCount = $recipients->count();

        if ($recipientCount === 0) {
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
            'recipients' => $recipientCount,
        ]));

        // One job, not one queue push per contact inside the request. That loop meant a
        // 5,000-contact list was 5,000 sequential writes before the response, and a bigger one hit
        // `max_execution_time` part-way through with no record of how far it got.
        // `SendAudienceBroadcastJob` pages through the same segment query, paces the chunks so the
        // mail provider is not handed thousands at once, and records progress as it goes.
        //
        // Dispatched after the commit, so the job can never read a broadcast row that is not there
        // yet.
        DB::afterCommit(fn () => SendAudienceBroadcastJob::dispatch($broadcast->id));

        Log::info('Audience broadcast queued', [
            'org_id' => $brand->org_id,
            'client_id' => $brand->id,
            'broadcast_id' => $broadcast->id,
            'sender_id' => $sender->id,
            'segment' => $segment,
            'recipients' => $broadcast->recipients,
        ]);

        return $broadcast;
    }
}
