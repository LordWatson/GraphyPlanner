<?php

namespace App\Http\Requests\Stories;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step S-4.1 — "Something went wrong" on a Story handoff page. The link's token is the
 * authorisation (checked by the controller through `ShowStoryHandoffAction::resolve()`), so there
 * is no user to authorise here.
 */
class ReportStoryHandoffProblemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('Tell us what went wrong, in a few words.'),
            'note.min' => __('Tell us what went wrong, in a few words.'),
            'note.max' => __('Keep it to :max characters.'),
        ];
    }
}
