<?php

namespace App\Http\Requests\ClientReports;

use App\Models\Client;
use App\Models\ClientReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-3.3 — prepare (or refresh) a client's report for one month: `month` as `Y-m`, not in the
 * future, at most two years back.
 */
class PrepareClientReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && ($this->user()?->can('create', [ClientReport::class, $client]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m'), 'after_or_equal:'.now()->subYears(2)->format('Y-m')],
        ];
    }

    public function month(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', (string) $this->validated('month'))->startOfMonth();
    }
}
