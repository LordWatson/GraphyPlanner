<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReschedulePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('reschedule', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'not_in:0', 'between:-366,366'],
        ];
    }
}
