<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\RegisterOrganizationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationRegistrationRequest;
use App\Support\Options\CurrencyOptions;
use App\Support\Options\TimezoneOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 15.2.2 — the public "create your organization" signup (guest-only), mirroring Fortify's
 * own registration controller: validate, create (org + Owner together), sign in, redirect. From
 * there the new Owner uses the app exactly like any other — via Fortify's email verification.
 */
class RegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('organizations/register', [
            'timezones' => TimezoneOptions::options(),
            'currencies' => CurrencyOptions::options(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(OrganizationRegistrationRequest $request, RegisterOrganizationAction $register): RedirectResponse
    {
        $owner = $register($request->registration());

        Auth::login($owner);

        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
