<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step M-6.7 (I-12.1) — per-user notification preferences: for each category, show in-app and/or in
 * the daily digest. Scoped to the acting user only.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/notifications', [
            'preferences' => NotificationPreferences::matrix($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['array'],
            'preferences.*.in_app' => ['boolean'],
            'preferences.*.digest' => ['boolean'],
        ]);

        $request->user()->update([
            'notification_preferences' => NotificationPreferences::sanitize($validated['preferences'] ?? []),
        ]);

        return back();
    }
}
