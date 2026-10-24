<?php

namespace App\Http\Requests\Inbox;

use App\Models\InboxConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-9.2 — a conversation action: assign (to a user or nobody), snooze (until a time), resolve
 * or reopen. Authorized here (before validation) against the route-bound conversation's `act`
 * ability, so a cross-tenant request is refused 403 rather than tripping validation first.
 */
class UpdateInboxConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof InboxConversation
            && ($this->user()?->can('act', $conversation) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:assign,snooze,resolve,reopen'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
        ];
    }
}
