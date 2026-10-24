<?php

namespace App\Http\Requests\Inbox;

use App\Enums\CommentSentiment;
use App\Enums\InboxStatus;
use App\Enums\Platform;
use App\Models\InboxConversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step M-9.2 — the inbox list query string: status, sentiment, client, platform, assignee
 * (`me` / `unassigned` / an id), a reply-needed toggle and a search term. Every value is optional.
 */
class InboxIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', InboxConversation::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(InboxStatus::class)],
            'sentiment' => ['nullable', Rule::enum(CommentSentiment::class)],
            'client' => ['nullable', 'integer'],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'assignee' => ['nullable', 'string', 'max:20'],
            'reply_needed' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'status' => $this->string('status')->toString() ?: null,
            'sentiment' => $this->string('sentiment')->toString() ?: null,
            'client_id' => $this->integer('client') ?: null,
            'platform' => $this->string('platform')->toString() ?: null,
            'assignee' => $this->string('assignee')->toString() ?: null,
            'reply_needed' => $this->boolean('reply_needed'),
            'search' => $this->string('search')->trim()->toString() ?: null,
        ];
    }
}
