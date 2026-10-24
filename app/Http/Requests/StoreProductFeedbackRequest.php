<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductFeedbackRequest extends FormRequest
{
    /**
     * Staff only (Step M-8.2): an org member who isn't a client reviewer. Super admins have no org
     * to attach feedback to, so they're excluded too — the panel never shows for them.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->org_id !== null
            && ! $user->isSuperAdmin()
            && $user->role !== Role::ClientReviewer;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:3', 'max:2000'],
            'page' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Tell us what’s on your mind before sending.',
        ];
    }
}
