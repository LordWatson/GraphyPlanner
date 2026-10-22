<?php

namespace App\Http\Requests;

use App\Actions\Posts\QuickSchedulePostAction;
use App\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step B-3.2 — authorizes the one-tap schedule. The furthest hop the button can take a post is
 * `scheduled`, so `PostPolicy::transition` against `scheduled` is the right gate: it lets the
 * internal roles (Owner/Manager/Creator) through and rejects a Viewer. The review-on path only
 * sends the post to internal review, which those same roles may do.
 */
class QuickSchedulePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transition', [$this->route('post'), PostStatus::Scheduled]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'when' => ['nullable', Rule::in([QuickSchedulePostAction::WHEN_SCHEDULED, QuickSchedulePostAction::WHEN_NOW])],
        ];
    }
}
