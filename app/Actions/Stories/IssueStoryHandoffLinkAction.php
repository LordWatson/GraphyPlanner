<?php

namespace App\Actions\Stories;

use App\Models\StoryHandoff;
use Illuminate\Support\Facades\Log;

/**
 * Step S-2.2 ("Token at send time") — mint the link to a handoff page.
 *
 * Only a token's hash is stored (decision D6), so a link cannot be looked up again later — and the
 * notification that carries it is sent by a later process than the one that opened the handoff. So
 * a link is minted when it is needed: by the sweep when it notifies or nudges, and by the signed-in
 * "Open handoff" route.
 *
 * **Each issue replaces the hash, so the previous link stops working.** The newest notification
 * always works; an older email for the same Story does not, and lands on the same "this page has
 * gone" screen as an expired review link. That is the price of never storing a token.
 *
 * The plain token is returned once and never logged.
 */
class IssueStoryHandoffLinkAction
{
    public function __invoke(StoryHandoff $handoff): string
    {
        $token = $handoff->plainToken();
        $hash = hash('sha256', $token);

        // Normally already in place from when the handoff was opened; this heals a row whose
        // hash is out of step (one made before the app key was rotated, say).
        if (! hash_equals($handoff->token_hash, $hash)) {
            $handoff->forceFill(['token_hash' => $hash])->save();

            Log::info('Story handoff link hash refreshed', [
                'org_id' => $handoff->org_id,
                'post_id' => $handoff->post_id,
                'story_handoff_id' => $handoff->id,
            ]);
        }

        return route('story-handoff.show', ['token' => $token]);
    }
}
