<?php

namespace App\Http\Requests\Campaigns;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step C-5.3 — anyone who can see the campaign can join its conversation (a client contact only
 * their own client's, enforced by the policy and, in the portal, `EnsureClientPortalAccess`).
 */
class StoreCampaignCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $this->user()->can('view', $campaign);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'internal_only' => ['boolean'],
        ];
    }
}
