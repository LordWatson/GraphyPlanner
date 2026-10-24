<?php

namespace App\Http\Requests\Campaigns;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Campaigns enhancement C-7.4 — 2 to 4 campaigns from the viewer's own organization. A campaign
 * from another organization is a validation error, never an empty column.
 */
class CompareCampaignsRequest extends FormRequest
{
    public const MAX = 4;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewHub', Campaign::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // One id is a valid intermediate selection — the page shows "Add one more" and the
            // comparison only renders at two. Rejecting a single id here would break the chip-based
            // picker, which adds campaigns one at a time.
            'ids' => ['nullable', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => [
                'integer', 'distinct',
                Rule::exists('campaigns', 'id')->where('org_id', $this->user()?->org_id)->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.max' => 'Compare up to '.self::MAX.' campaigns at a time.',
            'ids.*.exists' => 'That campaign isn’t one of yours.',
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_map('intval', (array) $this->validated('ids', [])));
    }
}
