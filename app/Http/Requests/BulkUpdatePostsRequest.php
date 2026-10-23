<?php

namespace App\Http\Requests;

use App\Actions\Posts\BulkUpdatePostsAction;
use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step M-5.1 — a bulk action on a content-hub selection. The gate is `viewHub` (a client reviewer
 * is kept out); the per-post role and state-machine checks happen inside `BulkUpdatePostsAction`,
 * which reports anything it has to skip.
 */
class BulkUpdatePostsRequest extends FormRequest
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
        $action = $this->input('action');

        return [
            'action' => ['required', Rule::in(BulkUpdatePostsAction::ACTIONS)],
            'post_ids' => ['required', 'array', 'min:1', 'max:200'],
            'post_ids.*' => ['integer', 'distinct'],
            'value' => [
                Rule::requiredIf(in_array($action, [BulkUpdatePostsAction::MOVE, BulkUpdatePostsAction::RESCHEDULE], true)),
                'nullable',
                Rule::when($action === BulkUpdatePostsAction::MOVE, [Rule::enum(PostStatus::class)]),
                Rule::when($action === BulkUpdatePostsAction::RESCHEDULE, ['integer', 'between:-3650,3650', 'not_in:0']),
                Rule::when(
                    $action === BulkUpdatePostsAction::CAMPAIGN,
                    ['integer', Rule::exists('campaigns', 'id')->where('org_id', (int) $this->user()->org_id)],
                ),
                Rule::when(
                    $action === BulkUpdatePostsAction::ASSIGN,
                    ['integer', Rule::exists('users', 'id')->where('org_id', (int) $this->user()->org_id)->whereIn('role', ['owner', 'strategist', 'designer', 'viewer'])],
                ),
            ],
        ];
    }

    public function value(): int|string|null
    {
        /** @var int|string|null $value */
        $value = $this->input('value');

        return $value === '' ? null : $value;
    }
}
