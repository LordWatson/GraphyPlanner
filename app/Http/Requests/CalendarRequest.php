<?php

namespace App\Http\Requests;

use App\Support\Calendar\CalendarWindow;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step I-2.3 — the calendar's query string: the visible window (`from`/`to`, both optional) plus
 * the existing filters. Shared by the internal and portal calendars; each controller authorizes
 * its own page. Malformed filters are dropped rather than rejected, matching the old behaviour.
 */
class CalendarRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'unscheduled_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function window(string $timezone): CalendarWindow
    {
        return CalendarWindow::resolve(
            $this->filled('from') ? $this->string('from')->toString() : null,
            $this->filled('to') ? $this->string('to')->toString() : null,
            $timezone,
        );
    }

    /**
     * @return array{client_id: int|null, campaign_id: int|null, status: string|null, platform: string|null}
     */
    public function filters(): array
    {
        return [
            'client_id' => $this->integer('client_id') ?: null,
            'campaign_id' => $this->integer('campaign_id') ?: null,
            'status' => $this->string('status')->toString() ?: null,
            'platform' => $this->string('platform')->toString() ?: null,
        ];
    }
}
