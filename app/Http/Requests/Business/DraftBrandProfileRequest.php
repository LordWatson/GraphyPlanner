<?php

namespace App\Http\Requests\Business;

use App\Policies\BrandBrainPolicy;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step B-2.4 — input for the brand-profile AI quick-start. Nothing is persisted from this request;
 * it only feeds `DraftBrandProfileAction`, which returns a draft for the owner to accept or edit.
 */
class DraftBrandProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brand = app(CurrentWorkspace::class)->brand();

        return $brand !== null && app(BrandBrainPolicy::class)->update($this->user(), $brand);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'one_liner' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{name: string|null, one_liner: string|null, location: string|null, website: string|null, bio: string|null}
     */
    public function draftInput(): array
    {
        return [
            'name' => $this->input('name'),
            'one_liner' => $this->input('one_liner'),
            'location' => $this->input('location'),
            'website' => $this->input('website'),
            'bio' => $this->input('bio'),
        ];
    }
}
