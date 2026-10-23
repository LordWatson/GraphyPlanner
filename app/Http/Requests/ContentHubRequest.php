<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step M-5.1 — the content hub query string: caption search, client, campaign, status, platform,
 * a scheduled-date range, a has-media toggle and the chosen view (table or board). Every value is
 * optional; `filters()` normalises them for `ListPostsAction`.
 */
class ContentHubRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewHub', Post::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'client' => ['nullable', 'integer'],
            'campaign' => ['nullable', 'integer'],
            // Step M-5.4: an assignee id, or 0 for "unassigned".
            'assignee' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'has_media' => ['nullable', 'in:yes,no'],
            'view' => ['nullable', 'in:table,board'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $hasMedia = $this->string('has_media')->toString();

        return [
            'search' => $this->string('search')->trim()->toString() ?: null,
            'client' => $this->integer('client') ?: null,
            'campaign' => $this->integer('campaign') ?: null,
            // Keep 0 ("unassigned") distinct from "no filter".
            'assignee' => $this->has('assignee') && $this->input('assignee') !== null ? (int) $this->input('assignee') : null,
            'status' => $this->string('status')->toString() ?: null,
            'platform' => $this->string('platform')->toString() ?: null,
            'from' => $this->date('from')?->toDateString(),
            'to' => $this->date('to')?->toDateString(),
            'has_media' => $hasMedia === '' ? null : $hasMedia === 'yes',
        ];
    }

    public function view(): string
    {
        return $this->string('view')->toString() === 'board' ? 'board' : 'table';
    }
}
