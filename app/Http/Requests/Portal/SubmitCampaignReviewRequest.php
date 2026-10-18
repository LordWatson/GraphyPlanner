<?php

namespace App\Http\Requests\Portal;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Step C-5.2 — a client contact's decisions from "Review this campaign". Asking for changes on a
 * post needs a note (on the post or the batch comment).
 */
class SubmitCampaignReviewRequest extends FormRequest
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
            'decisions' => ['required', 'array', 'min:1', 'max:200'],
            'decisions.*.post_id' => ['required', 'integer', 'distinct'],
            'decisions.*.decision' => ['required', Rule::in([PostStatus::Approved->value, PostStatus::ChangesRequested->value])],
            'decisions.*.comment' => ['nullable', 'string', 'max:2000'],
            'decisions.*.seen_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decisions.required' => 'Make at least one decision first.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('decisions', []) as $index => $decision) {
                if (($decision['decision'] ?? null) === PostStatus::ChangesRequested->value
                    && blank($decision['comment'] ?? null)
                    && blank($this->input('comment'))) {
                    $validator->errors()->add("decisions.{$index}.comment", 'Tell us what to change so we can get it right.');
                }
            }
        });
    }

    /**
     * @return list<array{post_id: int, decision: string, comment: string|null, seen_at: string}>
     */
    public function decisions(): array
    {
        return array_values(array_map(fn (array $decision) => [
            'post_id' => (int) $decision['post_id'],
            'decision' => (string) $decision['decision'],
            'comment' => isset($decision['comment']) && trim((string) $decision['comment']) !== '' ? trim((string) $decision['comment']) : null,
            'seen_at' => (string) $decision['seen_at'],
        ], (array) $this->validated('decisions')));
    }
}
