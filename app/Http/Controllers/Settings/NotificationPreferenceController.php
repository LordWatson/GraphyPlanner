<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Notifications\NotificationPreferences;
use App\Support\WhatsApp\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step M-6.7 (I-12.1) — per-user notification preferences: for each category, show in-app and/or in
 * the daily digest. Scoped to the acting user only.
 *
 * Step W-1.1 added the third channel, `whatsapp`, with the number panel beside it: the toggles
 * are disabled until a number is verified, because an opt-in with nowhere to send is a switch that
 * does nothing. The stored preferences are still passed to `sanitize()`, so any channel a payload
 * does not mention keeps its value rather than being switched off by omission.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'preferences' => NotificationPreferences::matrix($user),
            // Never the number itself: the last four digits are enough for somebody to recognise
            // their own phone, and the full number has no business in an Inertia prop.
            'whatsapp' => [
                'masked_phone' => PhoneNumber::mask($user->phone),
                'verified' => $user->phone_verified_at !== null,
                'awaiting_code' => $user->phone !== null && $user->phone_verified_at === null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['array'],
            'preferences.*.in_app' => ['boolean'],
            'preferences.*.digest' => ['boolean'],
            'preferences.*.whatsapp' => ['boolean'],
        ]);

        $user = $request->user();

        $user->update([
            'notification_preferences' => NotificationPreferences::sanitize(
                $validated['preferences'] ?? [],
                $user->notification_preferences ?? [],
            ),
        ]);

        return back();
    }
}
