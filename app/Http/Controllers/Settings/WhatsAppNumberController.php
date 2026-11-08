<?php

namespace App\Http\Controllers\Settings;

use App\Actions\WhatsApp\ConfirmWhatsAppNumberAction;
use App\Actions\WhatsApp\RequestWhatsAppVerificationAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Step W-1.1 — a member's own WhatsApp number: ask for a code, confirm it, or remove it.
 *
 * Scoped to the acting user and nobody else — every role may opt themselves in and out, including
 * client-portal reviewers, and no role may do it on somebody else's behalf. Throttled at the route
 * (`.junie/modules/notifications.md`), because each request sends a real WhatsApp message.
 */
class WhatsAppNumberController extends Controller
{
    public function store(Request $request, RequestWhatsAppVerificationAction $request_code): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $request_code($request->user(), $validated['phone']);

        return back()->with('status', 'whatsapp-code-sent');
    }

    public function update(Request $request, ConfirmWhatsAppNumberAction $confirm): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $confirm($request->user(), $validated['code']);

        return back()->with('status', 'whatsapp-verified');
    }

    public function destroy(Request $request, ConfirmWhatsAppNumberAction $numbers): RedirectResponse
    {
        $numbers->forget($request->user());

        return back()->with('status', 'whatsapp-removed');
    }
}
