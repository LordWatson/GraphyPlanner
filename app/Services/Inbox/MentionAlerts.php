<?php

namespace App\Services\Inbox;

use App\Enums\Role;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MentionKeyword;
use App\Models\User;
use App\Notifications\MentionAlert;
use Illuminate\Support\Facades\Log;

/**
 * Keyword alerts over the comments the app already collects for connected accounts.
 *
 * The honest, narrow version of "listening": no crawling, no third-party firehose, no claim that we
 * read the open web. A new inbound comment is checked against the organisation's watched words and
 * the studio hears about a hit once, when it arrives.
 *
 * Called from `IngestCommentsForTargetAction` **after** its transaction commits, so a notification
 * is never sent for a comment that was rolled back.
 *
 * `shortcut: one regex per term per new comment, with the term list and the recipient list cached
 * per instance (a sweep ingests one org at a time). Bounded by MentionKeyword::MAX_PER_ORG. If
 * term lists ever get long enough to matter, the move is one combined alternation regex.`
 */
class MentionAlerts
{
    /** @var array<int, array<int, MentionKeyword>> */
    private array $keywords = [];

    /** @var array<int, array<int, User>> */
    private array $recipients = [];

    /**
     * Notify the studio about every watched word the message uses. Safe to call for any message:
     * an outbound reply we sent ourselves, or an org with no watched words, costs one cached read
     * and does nothing.
     *
     * @param  array<int, InboxMessage>  $messages
     */
    public function scan(InboxConversation $conversation, array $messages): void
    {
        $keywords = $this->keywordsFor($conversation->org_id);

        if ($keywords === []) {
            return;
        }

        foreach ($messages as $message) {
            foreach ($keywords as $keyword) {
                if (! self::mentions((string) $message->body, $keyword->term)) {
                    continue;
                }

                // Counted so the page can show which words are actually earning their place.
                $keyword->increment('match_count');

                foreach ($this->recipientsFor($conversation->org_id) as $member) {
                    $member->notify(new MentionAlert($conversation, $keyword->term));
                }

                Log::info('Mention alert raised', [
                    'org_id' => $conversation->org_id,
                    'client_id' => $conversation->client_id,
                    'inbox_conversation_id' => $conversation->id,
                    'mention_keyword_id' => $keyword->id,
                ]);
            }
        }
    }

    /**
     * Whole-word, case- and accent-insensitive. Substring matching would make "art" fire on
     * "start" and the feature would be turned off within a day; a phrase matches as written.
     */
    public static function mentions(string $body, string $term): bool
    {
        if (trim($term) === '') {
            return false;
        }

        // `\p{L}\p{N}` rather than `\b`, so a term next to an emoji, a hashtag or an accent still
        // counts, and a term inside a longer word does not.
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote(trim($term), '/').'(?![\p{L}\p{N}])/iu';

        return preg_match($pattern, $body) === 1;
    }

    /**
     * @return array<int, MentionKeyword>
     */
    private function keywordsFor(int $orgId): array
    {
        return $this->keywords[$orgId] ??= MentionKeyword::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->limit(MentionKeyword::MAX_PER_ORG)
            ->get()
            ->all();
    }

    /**
     * The studio, not the assignee: a thread has no assignee when its first comment arrives, which
     * is exactly when an alert matters.
     *
     * @return array<int, User>
     */
    private function recipientsFor(int $orgId): array
    {
        return $this->recipients[$orgId] ??= User::query()
            ->where('org_id', $orgId)
            ->whereIn('role', [Role::Owner, Role::Strategist])
            ->get()
            ->all();
    }
}
