<?php

namespace App\Http\Requests\ClientReports;

use App\Enums\ClientReportSchedule;
use App\Models\Client;
use App\Models\ClientReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step M-3.3 — how a client's monthly report is produced (review / automatic / off).
 */
class UpdateClientReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && ($this->user()?->can('manageSchedule', [ClientReport::class, $client]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_schedule' => ['required', Rule::enum(ClientReportSchedule::class)],
        ];
    }
}
