<?php

namespace App\Http\Requests;

use App\Models\PostTarget;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleTargetRequest extends FormRequest
{
    /**
     * Rescheduling one channel uses the same ability as the whole-post move: `reschedule` on the
     * target's post (which enforces org scope, role, and the not-yet-published rule).
     */
    public function authorize(): bool
    {
        /** @var PostTarget $target */
        $target = $this->route('target');

        return $this->user()->can('reschedule', $target->post);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheduled_local_date' => ['required', 'date_format:Y-m-d'],
            // The week/day time grid sends a slot time; the month grid omits it and the target
            // keeps its own time.
            'scheduled_local_time' => ['nullable', 'date_format:H:i,H:i:s'],
        ];
    }
}
