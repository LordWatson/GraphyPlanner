<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters behind Settings → Audit log, shared by the page and its CSV export so the file always
 * holds exactly what the screen showed.
 */
class AuditLogRequest extends FormRequest
{
    /** Default window. Long enough to answer "what happened last month", short enough to load. */
    public const DEFAULT_DAYS = 30;

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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            // Scoped to this organization's own members, so the filter cannot be used to probe
            // whether a user id exists elsewhere on the platform.
            'actor' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')->where('org_id', $this->user()->org_id),
            ],
        ];
    }

    /**
     * The window, in the organization's own timezone — an audit read in UTC while the agency works
     * in Sydney puts actions on the wrong day.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function window(): array
    {
        $timezone = (string) $this->user()->organization->default_timezone;

        $toInput = $this->date('to', tz: $timezone);
        $fromInput = $this->date('from', tz: $timezone);

        $to = CarbonImmutable::parse($toInput ?? CarbonImmutable::now($timezone))->endOfDay();
        $from = $fromInput !== null
            ? CarbonImmutable::parse($fromInput)->startOfDay()
            : $to->subDays(self::DEFAULT_DAYS)->startOfDay();

        // Stored timestamps are UTC, so the window has to be too.
        return [$from->utc(), $to->utc()];
    }

    public function actorId(): ?int
    {
        $actor = $this->validated('actor');

        return $actor === null ? null : (int) $actor;
    }
}
