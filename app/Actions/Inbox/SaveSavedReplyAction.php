<?php

namespace App\Actions\Inbox;

use App\Models\Organization;
use App\Models\SavedReply;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-9.2 — create or update a canned inbox reply. One Action for both so the validation and the
 * scoping rules (org-wide when no client, else scoped to one client of the same org) live in a
 * single place.
 */
class SaveSavedReplyAction
{
    /**
     * @param  array{title: string, body: string, shortcut?: string|null, client_id?: int|null}  $data
     */
    public function __invoke(Organization $organization, User $author, array $data, ?SavedReply $reply = null): SavedReply
    {
        $reply ??= new SavedReply(['org_id' => $organization->id, 'created_by' => $author->id]);

        $reply->fill([
            'title' => $data['title'],
            'body' => $data['body'],
            'shortcut' => $data['shortcut'] ?? null,
            'client_id' => $data['client_id'] ?? null,
        ]);
        $reply->org_id = $organization->id;
        $reply->save();

        Log::info('Saved reply stored', [
            'org_id' => $organization->id,
            'saved_reply_id' => $reply->id,
            'client_id' => $reply->client_id,
        ]);

        return $reply;
    }
}
