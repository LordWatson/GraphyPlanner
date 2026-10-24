<?php

namespace App\Http\Requests\Inbox;

use App\Models\InboxConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-9.3 — the human-sent reply body, optionally crediting the saved reply it came from (so its
 * usage count ticks up). Authorized here (before validation) against the route-bound conversation's
 * `reply` ability, so a cross-tenant request is refused 403 rather than tripping validation first.
 */
class ReplyToInboxConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof InboxConversation
            && ($this->user()?->can('reply', $conversation) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'saved_reply_id' => ['nullable', 'integer', 'exists:saved_replies,id'],
        ];
    }
}
