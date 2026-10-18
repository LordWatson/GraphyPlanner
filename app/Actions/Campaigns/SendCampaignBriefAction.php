<?php

namespace App\Actions\Campaigns;

use App\Enums\BriefStatus;
use App\Enums\Role;
use App\Mail\CampaignBriefSentMail;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\CampaignBriefRevision;
use App\Models\User;
use App\Notifications\CampaignBriefReady;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Step C-2.3 — "Send brief to client": freeze the brief's content into a new numbered revision
 * (with its SHA-256), move the brief to `sent`, and — after commit — email every portal contact
 * for the client and drop a bell notification. Decisions are later recorded against this
 * revision's id and hash, so the client always signs exactly what they read.
 */
class SendCampaignBriefAction
{
    public function __invoke(Campaign $campaign, User $user): CampaignBriefRevision
    {
        $revision = DB::transaction(function () use ($campaign, $user) {
            $brief = $campaign->brief()->lockForUpdate()->first();

            if ($brief === null || $brief->isEmpty()) {
                throw ValidationException::withMessages(['brief' => 'Write the brief before sending it.']);
            }

            if ($brief->status === BriefStatus::Approved) {
                throw ValidationException::withMessages(['brief' => 'This brief is already approved. Edit it to send a new revision.']);
            }

            $content = $brief->content();

            $revision = $brief->revisions()->create([
                'number' => (int) $brief->revisions()->max('number') + 1,
                'content' => $content,
                'content_hash' => CampaignBrief::hashContent($content),
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);

            $previous = $brief->status;
            $brief->update(['status' => BriefStatus::Sent]);

            $campaign->recordActivity('brief.sent', $user, $previous->value, BriefStatus::Sent->value, [
                'revision_id' => $revision->id,
                'revision' => $revision->number,
            ]);

            DB::afterCommit(fn () => $this->notifyClient($campaign, $revision));

            return $revision;
        });

        Log::info('Campaign brief sent to client', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'revision_id' => $revision->id,
            'revision' => $revision->number,
            'user_id' => $user->id,
        ]);

        return $revision;
    }

    private function notifyClient(Campaign $campaign, CampaignBriefRevision $revision): void
    {
        $contacts = User::query()
            ->where('client_id', $campaign->client_id)
            ->where('role', Role::ClientReviewer)
            ->get();

        foreach ($contacts as $contact) {
            Mail::to($contact->email)->queue(new CampaignBriefSentMail($campaign, $revision, $contact));
            $contact->notify(new CampaignBriefReady($campaign));
        }

        Log::info('Campaign brief emailed to portal contacts', [
            'campaign_id' => $campaign->id,
            'revision_id' => $revision->id,
            'recipient_count' => $contacts->count(),
        ]);
    }
}
