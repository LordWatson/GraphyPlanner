<?php

namespace App\Http\Requests\Portal;

use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of a Step 6.5 portal decision (approve / request changes) on a `waiting_client` post.
 * Authorization mirrors `TransitionPostRequest`, delegating to `PostPolicy::transition`'s
 * `Role::ClientReviewer` branch so a contact can never decide on another client's post or move a
 * post that isn't currently `waiting_client`.
 */
class PortalPostDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $to = PostStatus::tryFrom((string) $this->input('decision'));

        if ($to === null) {
            return true; // let validation surface the invalid `decision` value.
        }

        return $this->user()->can('transition', [$this->route('post'), $to]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'changes_requested'])],
            'comment' => ['nullable', 'string'],
        ];
    }
}
