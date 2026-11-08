<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WhatsAppMessageStatus;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step W-4.2 — the WhatsApp message log: what went out, to whom, and what became of it.
 *
 * The question this page answers is "did my client actually get it?", which is why a **skipped**
 * message is listed next to a delivered one: "nothing was sent, because they never consented" is
 * the answer most of the time, and it is invisible everywhere else.
 *
 * Numbers are masked to the last four digits here as everywhere — `WhatsAppMessage` hides the
 * column, so the full number cannot reach this page by accident.
 */
class WhatsAppLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WhatsAppMessage::class);

        $status = WhatsAppMessageStatus::tryFrom((string) $request->query('status'));

        $messages = WhatsAppMessage::query()
            ->with(['user:id,name', 'clientContact:id,name,email'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->newestFirst()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('settings/whatsapp-log', [
            'messages' => $messages->through(fn (WhatsAppMessage $message) => [
                'id' => $message->id,
                'sent_at' => $message->created_at?->toIso8601String(),
                // Keyed off the foreign keys rather than nullsafe chains: exactly one of the
                // two is set on any row, and the analyser types both relations as non-null.
                'recipient' => $this->recipientName($message),
                'masked_phone' => $message->maskedPhone(),
                'template' => $message->template,
                'notification' => class_basename((string) $message->notification_type),
                'status' => $message->status->value,
                'status_label' => $message->status->label(),
                'error_reason' => $message->error_reason,
                'delivered_at' => $message->delivered_at?->toIso8601String(),
            ]),
            'filters' => ['status' => $status?->value],
            'statuses' => array_map(
                fn (WhatsAppMessageStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                WhatsAppMessageStatus::cases(),
            ),
        ]);
    }

    /**
     * Who the message was for: a member, or a client's named contact.
     */
    private function recipientName(WhatsAppMessage $message): string
    {
        if ($message->user_id !== null) {
            return $message->user->name;
        }

        if ($message->client_contact_id !== null) {
            return $message->clientContact->name ?? $message->clientContact->email;
        }

        return 'Unknown';
    }
}
