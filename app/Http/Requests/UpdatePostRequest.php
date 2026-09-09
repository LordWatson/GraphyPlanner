<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdatePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approval_mode' => ['nullable', new Enum(ApprovalMode::class)],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'master_caption' => ['nullable', 'string'],
            'review_message' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string'],
            'music' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],

            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],

            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', 'exists:social_accounts,id'],
            'targets.*.scheduled_local_date' => ['nullable', 'date'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }
}
