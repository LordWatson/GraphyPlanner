<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of a Step 0.13 review-portal decision. Authorization (token validity + scoping) is
 * handled by `ReviewController` before this request's rules run, since it isn't tied to an
 * authenticated user/policy.
 */
class ReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
