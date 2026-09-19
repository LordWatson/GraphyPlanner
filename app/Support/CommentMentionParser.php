<?php

namespace App\Support;

/**
 * Parses `@[Name](id)` mention tokens out of a comment body. The frontend's mention-aware
 * comment textarea (`MentionTextarea`) inserts this token when a user picks someone from the
 * `@` autocomplete dropdown; `CreatePostCommentAction` uses this to resolve which users to
 * record as mentioned (and notify) without trusting arbitrary user-supplied IDs.
 */
class CommentMentionParser
{
    /**
     * Extract the unique, ordered list of mentioned user IDs from a comment body.
     *
     * @return array<int, int>
     */
    public static function extractUserIds(string $body): array
    {
        preg_match_all('/@\[[^\]]+\]\((\d+)\)/', $body, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
