<?php

namespace App\Http\Controllers;

use App\Actions\RecordRegistrationInterestAction;
use App\Http\Requests\StoreRegistrationInterestRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public "register interest" form at `/register`. Self-serve signup (`/register-organization`,
 * `/start`) is still routed but no longer linked while the first clients are onboarded by hand.
 */
class RegistrationInterestController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register-interest');
    }

    public function store(StoreRegistrationInterestRequest $request, RecordRegistrationInterestAction $record): RedirectResponse
    {
        if ($request->isBot()) {
            Log::warning('Registration interest honeypot tripped', ['ip' => $request->ip()]);
        } else {
            $record($request->toAttributes());
        }

        return back();
    }
}
