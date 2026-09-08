<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StorePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Post::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', new Enum(PostStatus::class)],
            'approval_mode' => ['nullable', new Enum(ApprovalMode::class)],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'master_caption' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string'],
            'music' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],

            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', 'exists:social_accounts,id'],
            'targets.*.scheduled_local_date' => ['nullable', 'date'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }
}
