<?php

namespace App\Http\Requests\ClientReports;

use App\Models\ClientReport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-3.3 — the agency's commentary on a draft monthly report.
 */
class UpdateClientReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return $report instanceof ClientReport && ($this->user()?->can('update', $report) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commentary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
