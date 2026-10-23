<?php

namespace App\Http\Requests\Business;

use App\Policies\ClientPolicy;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step B-2.3 — the onboarding wizard's "Your brand" step. Saves the primary brand's basics with no
 * agency vocabulary: name, "what you do" (stored on `industry`), location and website/handle. The
 * brand is implicit — resolved from `CurrentWorkspace`, never a `{client}` route parameter.
 */
class UpdateBusinessBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brand = app(CurrentWorkspace::class)->brand();

        return $brand !== null && app(ClientPolicy::class)->update($this->user(), $brand);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'one_liner' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Mapped onto `clients` columns ("what you do" → `industry`).
     *
     * @return array<string, mixed>
     */
    public function brandData(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'industry' => $this->filled('one_liner') ? $this->string('one_liner')->trim()->toString() : null,
            'location' => $this->filled('location') ? $this->string('location')->trim()->toString() : null,
            'website' => $this->filled('website') ? $this->string('website')->trim()->toString() : null,
        ];
    }
}
