<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class OrganizationSettingsUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->organization !== null
            && $this->user()->can('update', $this->user()->organization);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_timezone' => ['required', 'timezone:all'],
            'upload_post_key' => ['nullable', 'string', 'max:2048'],
            'xai_key' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
