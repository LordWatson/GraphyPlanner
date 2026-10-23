<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\RegisterOrganizationAction;
use App\Enums\BrandKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBusinessRegistrationRequest;
use App\Support\Options\CurrencyOptions;
use App\Support\Options\TimezoneOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-2.1 — the public "manage your own brand" signup (guest-only) at `/start`, the business/
 * creator entry point into the same product as the agency signup. It creates a `business`
 * organization with its single primary brand, signs the new Owner in, and drops them into the
 * guided setup (`/welcome-setup`), behind Fortify's email verification.
 */
class BusinessRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('organizations/start', [
            'timezones' => TimezoneOptions::options(),
            'currencies' => CurrencyOptions::options(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'brandKinds' => array_map(fn (BrandKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'tagline' => $kind->tagline(),
            ], BrandKind::cases()),
        ]);
    }

    public function store(StoreBusinessRegistrationRequest $request, RegisterOrganizationAction $register): RedirectResponse
    {
        $owner = $register($request->registration());

        Auth::login($owner);

        $request->session()->regenerate();

        return to_route('business.onboarding');
    }
}
