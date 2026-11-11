<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\RegistrationInterestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A super admin working a registration: its pipeline status, the booked demo slot and notes.
 * Access is enforced by the `super_admin` route middleware.
 */
class UpdateRegistrationInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(RegistrationInterestStatus::class)],
            'demo_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array{status: RegistrationInterestStatus, demo_at: ?string, notes: ?string}
     */
    public function toData(): array
    {
        return [
            'status' => RegistrationInterestStatus::from($this->validated('status')),
            'demo_at' => $this->validated('demo_at'),
            'notes' => $this->validated('notes'),
        ];
    }
}
