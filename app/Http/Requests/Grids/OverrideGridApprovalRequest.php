<?php

namespace App\Http\Requests\Grids;

use App\Http\Requests\Grids\Concerns\GridRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 18.7 — an Owner approves a client-required grid without the client; the reason is required.
 */
class OverrideGridApprovalRequest extends FormRequest
{
    use GridRules;

    public function authorize(): bool
    {
        return $this->user()->can('overrideApproval', $this->plan());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
