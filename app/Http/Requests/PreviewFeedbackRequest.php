<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step B-3.4 — body of a "Share preview" feedback submission (a partner/sponsor). Authorization is
 * possession of a valid token, re-checked in `ReviewController` (not tied to a user/policy). A
 * submission must carry at least one of a decision or a comment; which of those is actually
 * honoured depends on the token's capabilities, enforced again in `RecordPreviewFeedbackAction`.
 */
class PreviewFeedbackRequest extends FormRequest
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
            'decision' => ['nullable', Rule::in(['approved', 'changes_requested'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (blank($this->input('decision')) && blank($this->input('comment'))) {
                $validator->errors()->add('comment', __('Leave a comment or make a decision.'));
            }
        });
    }
}
