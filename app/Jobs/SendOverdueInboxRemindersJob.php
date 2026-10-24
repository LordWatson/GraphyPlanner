<?php

namespace App\Jobs;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InboxReplyOverdue;
use App\Services\Inbox\InboxSlaService;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Step M-9.2 — once a day, nudge about every inbox thread sitting past its org's reply SLA: the
 * assigned staffer when there is one, otherwise the studio (Owner/Strategist). Throttled to once per
 * 24h per thread via `inbox_conversations.reminder_sent_at`, so a daily run never double-sends.
 */
class SendOverdueInboxRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(Queues::NOTIFICATIONS);
    }

    public function handle(InboxSlaService $sla): void
    {
        $cutoff = now()->subDay();
        $reminded = 0;

        Organization::query()
            ->active()
            ->each(function (Organization $org) use ($sla, $cutoff, &$reminded) {
                $conversations = $sla->overdueQuery($org)
                    ->where(fn ($query) => $query->whereNull('reminder_sent_at')->orWhere('reminder_sent_at', '<=', $cutoff))
                    ->with('assignee')
                    ->get();

                if ($conversations->isEmpty()) {
                    return;
                }

                $studio = User::query()
                    ->where('org_id', $org->id)
                    ->whereIn('role', [Role::Owner, Role::Strategist])
                    ->get();

                foreach ($conversations as $conversation) {
                    $recipients = $conversation->assignee !== null ? collect([$conversation->assignee]) : $studio;

                    foreach ($recipients as $member) {
                        $member->notify(new InboxReplyOverdue($conversation));
                    }

                    $conversation->forceFill(['reminder_sent_at' => now()])->save();
                    $reminded++;
                }
            });

        Log::info('Overdue inbox reminders sent', ['conversation_count' => $reminded]);
    }
}
