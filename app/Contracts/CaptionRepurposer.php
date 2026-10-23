<?php

namespace App\Contracts;

use App\Models\Post;

/**
 * Step B-6.4 — "repurpose across platforms": take one post's master caption and rewrite it natively
 * for each target platform (a punchy TikTok hook, an Instagram caption with hashtags, a longer-form
 * LinkedIn/Facebook post…). Its own contract (bound like {@see LocalIdeaProvider}) so it rides the
 * DeepSeek text model without touching the agency {@see AiProvider} doubles.
 *
 * Suggestion only — the result fills the per-platform override fields (I-3.1) for the user to review
 * and save; nothing is written here. Never throws: a disabled/failed provider returns no variants.
 */
interface CaptionRepurposer
{
    /**
     * Rewrite the post's caption for each of the given platforms.
     *
     * @param  array<int, string>  $platforms  platform values (e.g. `instagram`, `tiktok`)
     * @return array<string, array{caption: string, hashtags: list<string>, firstComment: string|null}> keyed by platform value
     */
    public function repurpose(Post $post, array $platforms): array;
}
