<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        // Step I-1.9: referenced records must belong to this client — a bare `exists:` rule would
        // accept another organization's ids.
        $post = $this->route('post');
        $clientId = $post instanceof Post ? $post->client_id : null;

        return [
            'approval_mode' => ['nullable', new Enum(ApprovalMode::class)],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $clientId)],
            'master_caption' => ['nullable', 'string'],
            'review_message' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string'],
            'music' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],

            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer', Rule::exists('assets', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],

            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', Rule::exists('social_accounts', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'targets.*.scheduled_local_date' => ['nullable', 'date'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }
}
