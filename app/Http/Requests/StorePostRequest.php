<?php

namespace App\Http\Requests;

use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Http\Requests\Concerns\ResolvesWorkspaceBrand;
use App\Models\Post;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StorePostRequest extends FormRequest
{
    use ResolvesWorkspaceBrand;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Post::class, $this->brand()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Step I-1.9: referenced records must belong to this client — a bare `exists:` rule would
        // accept another organization's ids.
        $clientId = $this->brandId();

        return [
            'status' => ['nullable', new Enum(PostStatus::class)],
            // Step B-1.4: client approval isn't a concept in a business workspace.
            'approval_mode' => ['nullable', new Enum(ApprovalMode::class), $this->rejectClientApprovalForBusiness()],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $clientId)],
            'master_caption' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string'],
            'music' => ['nullable', 'array'],
            'location' => ['nullable', 'array'],

            'targets' => ['nullable', 'array'],
            'targets.*.social_account_id' => ['required', 'integer', Rule::exists('social_accounts', 'id')->where('client_id', $clientId)->whereNull('deleted_at')],
            'targets.*.scheduled_local_date' => ['nullable', 'date'],
            'targets.*.scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }

    /**
     * Step B-1.4 — a business workspace has no client to approve posts, so `ApprovalMode::
     * ClientRequired` is rejected for it (agencies are unaffected).
     */
    private function rejectClientApprovalForBusiness(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === ApprovalMode::ClientRequired->value && $this->user()?->organization?->isBusiness() === true) {
                $fail(__('Client approval isn’t available in this workspace.'));
            }
        };
    }
}
