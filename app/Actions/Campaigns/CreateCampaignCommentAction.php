<?php

namespace App\Actions\Campaigns;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignComment;
use App\Models\User;
use App\Notifications\CampaignCommentAdded;
use App\Support\CommentMentionParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-5.3 — a message in the campaign's conversation (the studio's Overview tab and the client
 * portal share it).
 *
 * - A client contact can never write an internal note; internal notes never reach the portal.
 * - `@[Name](id)` mentions resolve against the organization's staff only, and only the mentioned
 *   staff are notified of a mention.
 * - Otherwise the other side hears about it: a client message notifies the campaign owner (or the
 *   Owners/Strategists when it has none); a studio message that isn't internal notifies the
 *   client's portal contacts.
 */
class CreateCampaignCommentAction
{
    public function __invoke(Campaign $campaign, User $author, string $body, bool $internalOnly = false): CampaignComment
    {
        $isClient = $author->role === Role::ClientReviewer;
        $internalOnly = ! $isClient && $internalOnly;

        $comment = DB::transaction(function () use ($campaign, $author, $body, $internalOnly) {
            $comment = $campaign->comments()->create([
                'user_id' => $author->id,
                'body' => $body,
                'internal_only' => $internalOnly,
            ]);

            $campaign->recordActivity('comment.added', $author, meta: ['comment_id' => $comment->id, 'internal_only' => $internalOnly]);

            return $comment;
        });

        $mentioned = $this->mentionedStaff($campaign, $body, $author);
        $others = $isClient
            ? $this->studioRecipients($campaign)
            : ($internalOnly ? collect() : $this->clientRecipients($campaign));

        DB::afterCommit(function () use ($campaign, $comment, $author, $mentioned, $others) {
            foreach ($mentioned as $user) {
                $user->notify(new CampaignCommentAdded($campaign, $comment, $author, mentioned: true));
            }

            foreach ($others->reject(fn (User $user) => $user->id === $author->id || $mentioned->contains('id', $user->id)) as $user) {
                $user->notify(new CampaignCommentAdded($campaign, $comment, $author));
            }
        });

        Log::info('Campaign comment created', [
            'campaign_id' => $campaign->id,
            'comment_id' => $comment->id,
            'user_id' => $author->id,
            'internal_only' => $internalOnly,
            'mentioned_user_ids' => $mentioned->pluck('id')->all(),
        ]);

        return $comment;
    }

    /**
     * @return Collection<int, User>
     */
    private function mentionedStaff(Campaign $campaign, string $body, User $author): Collection
    {
        $ids = CommentMentionParser::extractUserIds($body);

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->where('org_id', $campaign->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->whereIn('id', $ids)
            ->whereKeyNot($author->id)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function studioRecipients(Campaign $campaign): Collection
    {
        if ($campaign->owner_id !== null) {
            return User::query()->whereKey($campaign->owner_id)->get();
        }

        return User::query()
            ->where('org_id', $campaign->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist])
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function clientRecipients(Campaign $campaign): Collection
    {
        return User::query()
            ->where('client_id', $campaign->client_id)
            ->where('role', Role::ClientReviewer)
            ->get();
    }
}
