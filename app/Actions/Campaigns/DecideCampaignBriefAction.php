<?php

namespace App\Actions\Campaigns;

use App\Enums\ApprovalDecision;
use App\Enums\BriefStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignBriefRevision;
use App\Models\User;
use App\Notifications\CampaignBriefDecided;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-2.3 — the client approves the brief or asks for changes, from the portal.
 *
 * The decision must name the latest sent revision *and* its content hash: if the studio sent a
 * newer revision (or edited the brief) after the client opened the page, the decision is
 * rejected as stale and nothing is written. The brief row is locked so two contacts deciding at
 * once can't both win. Staff get a bell notification after commit.
 */
class DecideCampaignBriefAction
{
    public function __invoke(Campaign $campaign, int $revisionId, string $contentHash, ApprovalDecision $decision, ?string $comment, User $user): CampaignBriefRevision
    {
        $revision = DB::transaction(function () use ($campaign, $revisionId, $contentHash, $decision, $comment, $user) {
            $brief = $campaign->brief()->lockForUpdate()->first();
            $latest = $brief?->revisions()->orderByDesc('number')->first();

            if ($brief === null || $brief->status !== BriefStatus::Sent || $latest === null) {
                throw ValidationException::withMessages(['revision_id' => 'This brief isn’t waiting for a decision any more.']);
            }

            if ($latest->id !== $revisionId || ! hash_equals($latest->content_hash, $contentHash)) {
                Log::warning('Stale campaign brief decision rejected', [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'revision_id' => $revisionId,
                    'latest_revision_id' => $latest->id,
                ]);

                throw ValidationException::withMessages(['revision_id' => 'The studio updated this brief since you opened it. Reload to see the latest version.']);
            }

            $latest->update([
                'decision' => $decision,
                'decision_comment' => $comment,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            $approved = $decision === ApprovalDecision::Approved;

            $brief->update([
                'status' => $approved ? BriefStatus::Approved : BriefStatus::ChangesRequested,
                'approved_at' => $approved ? now() : null,
                'approved_by' => $approved ? $user->id : null,
            ]);

            $campaign->recordActivity($approved ? 'brief.approved' : 'brief.changes_requested', $user, BriefStatus::Sent->value, $brief->status->value, [
                'revision_id' => $latest->id,
                'revision' => $latest->number,
            ]);

            DB::afterCommit(function () use ($campaign, $latest, $user) {
                $recipients = User::query()
                    ->where('org_id', $campaign->org_id)
                    ->whereIn('role', [Role::Owner, Role::Strategist])
                    ->get();

                foreach ($recipients as $recipient) {
                    $recipient->notify(new CampaignBriefDecided($campaign, $latest, $user));
                }
            });

            return $latest;
        });

        Log::info('Campaign brief decided by client', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'revision_id' => $revision->id,
            'decision' => $decision->value,
            'user_id' => $user->id,
        ]);

        return $revision;
    }
}
