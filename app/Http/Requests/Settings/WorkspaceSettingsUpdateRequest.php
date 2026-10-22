<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step B-3.3 — the business workspace's own settings. Owner-only (checked against
 * `OrganizationPolicy::update`); the route is already `workspace:business`-guarded.
 */
class WorkspaceSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()->organization) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'require_post_review' => ['required', 'boolean'],
        ];
    }
}
