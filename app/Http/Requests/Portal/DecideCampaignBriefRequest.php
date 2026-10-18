<?php

namespace App\Http\Requests\Portal;

use App\Enums\ApprovalDecision;
use App\Enums\Role;
use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step C-2.3 — a client contact's decision on a sent brief revision. Only their own client's
 * campaign (also enforced by `EnsureClientPortalAccess`); asking for changes needs a comment.
 */
class DecideCampaignBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');
        $user = $this->user();

        return $user->role === Role::ClientReviewer && $user->client_id === $campaign->client_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'revision_id' => ['required', 'integer'],
            'content_hash' => ['required', 'string', 'size:64'],
            'decision' => ['required', Rule::enum(ApprovalDecision::class)],
            'comment' => ['nullable', 'string', 'max:2000', 'required_if:decision,'.ApprovalDecision::ChangesRequested->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.required_if' => 'Tell us what to change so we can get it right.',
        ];
    }
}
