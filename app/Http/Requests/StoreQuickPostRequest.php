<?php

namespace App\Http\Requests;

use App\Actions\Posts\QuickSchedulePostAction;
use App\Http\Requests\Concerns\ResolvesWorkspaceBrand;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step B-7.2 — the phone-first quick post: one already-uploaded photo/video, a caption, the
 * accounts to post to, and when. The brand is implicit (the business workspace's primary brand);
 * every referenced id is scoped to it so a quick post can never reach another brand's media or
 * accounts.
 */
class StoreQuickPostRequest extends FormRequest
{
    use ResolvesWorkspaceBrand;

    public function authorize(): bool
    {
        $brand = $this->brand();

        return $brand !== null && $this->user()->can('create', [Post::class, $brand]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $brandId = $this->brandId();

        return [
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->where('client_id', $brandId)],
            'caption' => ['required', 'string', 'max:5000'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', Rule::exists('social_accounts', 'id')->where('client_id', $brandId)],
            'when' => ['required', Rule::in([
                QuickSchedulePostAction::WHEN_NOW,
                QuickSchedulePostAction::WHEN_SCHEDULED,
                'next',
            ])],
            // Only read when `when = schedule` (a specific time the user picked).
            'scheduled_local_date' => ['required_if:when,'.QuickSchedulePostAction::WHEN_SCHEDULED, 'nullable', 'date_format:Y-m-d'],
            'scheduled_local_time' => ['required_if:when,'.QuickSchedulePostAction::WHEN_SCHEDULED, 'nullable', 'date_format:H:i'],
        ];
    }
}
