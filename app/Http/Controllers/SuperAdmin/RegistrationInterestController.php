<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\SuperAdmin\GetRegistrationInterestStatsAction;
use App\Actions\SuperAdmin\UpdateRegistrationInterestAction;
use App\Enums\RegistrationInterestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\UpdateRegistrationInterestRequest;
use App\Models\RegistrationInterest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The registrations console: everyone who registered interest, with pipeline stats, so the team
 * can contact them, book a demo and onboard them by hand. Super-admin only (the group middleware).
 */
class RegistrationInterestController extends Controller
{
    public function index(Request $request, GetRegistrationInterestStatsAction $stats): Response
    {
        $status = RegistrationInterestStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('q'));

        $registrations = RegistrationInterest::query()
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")))
            ->latest()
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (RegistrationInterest $interest) => self::row($interest));

        return Inertia::render('super-admin/registrations/index', [
            'registrations' => $registrations,
            'stats' => $stats(),
            'filters' => ['status' => $status?->value, 'q' => $search],
            'statuses' => self::statuses(),
        ]);
    }

    public function show(RegistrationInterest $registration): Response
    {
        return Inertia::render('super-admin/registrations/show', [
            'registration' => [
                ...self::row($registration),
                'notes' => $registration->notes,
                'ip_address' => $registration->ip_address,
                'city' => $registration->city,
                'timezone' => $registration->timezone,
                'locale' => $registration->locale,
                'user_agent' => $registration->user_agent,
                'referrer' => $registration->referrer,
                'utm_medium' => $registration->utm_medium,
                'utm_campaign' => $registration->utm_campaign,
                'referral_code' => $registration->referral_code,
                'updated_at' => $registration->updated_at?->toIso8601String(),
            ],
            'statuses' => self::statuses(),
        ]);
    }

    public function update(UpdateRegistrationInterestRequest $request, RegistrationInterest $registration, UpdateRegistrationInterestAction $update): RedirectResponse
    {
        $update($registration, $request->toData());

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(RegistrationInterest $interest): array
    {
        return [
            'id' => $interest->id,
            'name' => $interest->name,
            'email' => $interest->email,
            'phone' => $interest->phone,
            'company_name' => $interest->company_name,
            'location' => $interest->location,
            'country' => $interest->country,
            'status' => $interest->status->value,
            'source' => GetRegistrationInterestStatsAction::source($interest),
            'utm_source' => $interest->utm_source,
            'demo_at' => $interest->demo_at?->toIso8601String(),
            'contacted_at' => $interest->contacted_at?->toIso8601String(),
            'created_at' => $interest->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function statuses(): array
    {
        return array_map(
            fn (RegistrationInterestStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            RegistrationInterestStatus::cases(),
        );
    }
}
