<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step B-3.4 — mint a "Share preview" link for a post. `allow_comments`/`allow_approval` are opt-in
 * (a view-only link otherwise); `expires_in_days` caps how long the link lives.
 */
class CreateSharePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sharePreview', $this->route('post')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'allow_comments' => ['nullable', 'boolean'],
            'allow_approval' => ['nullable', 'boolean'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,90'],
        ];
    }
}
