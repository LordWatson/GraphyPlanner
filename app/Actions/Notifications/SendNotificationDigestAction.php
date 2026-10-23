<?php

namespace App\Actions\Notifications;

use App\Contracts\ChatNotifier;
use App\Enums\NotificationCategory;
use App\Mail\NotificationDigestMail;
use App\Models\User;
use App\Services\Notifications\NullChatNotifier;
use App\Support\Notifications\NotificationPreferences;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Step M-6.7 (I-12.1) — the daily digest: one email per user summarising the day's notifications in
 * the categories they've kept in their digest. Reads the stored `database` notifications (nothing is
 * re-generated), so it layers cleanly over the existing bell. A user with nothing to report, or who
 * has muted every category's digest, gets no email.
 */
class SendNotificationDigestAction
{
    public function __construct(private readonly ChatNotifier $chat = new NullChatNotifier) {}

    public function __invoke(): int
    {
        $since = now()->subDay();
        $sent = 0;

        User::query()
            ->whereHas('notifications', fn ($query) => $query->where('created_at', '>=', $since))
            ->with('organization')
            ->chunkById(200, function ($users) use ($since, &$sent) {
                foreach ($users as $user) {
                    $items = $this->digestItems($user, $since);

                    if ($items === []) {
                        continue;
                    }

                    Mail::to($user->email)->queue(new NotificationDigestMail($user->name, $items));

                    // Step M-6.7: the WhatsApp seam — a no-op today (NullChatNotifier).
                    if ($user->organization !== null) {
                        $this->chat->notifyOrganization($user->organization, 'Daily digest', count($items).' updates', null);
                    }

                    $sent++;
                }
            });

        Log::info('Notification digests sent', ['user_count' => $sent]);

        return $sent;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function digestItems(User $user, CarbonInterface $since): array
    {
        return $user->notifications()
            ->where('created_at', '>=', $since)
            ->latest()
            ->get()
            ->filter(fn (DatabaseNotification $notification) => NotificationPreferences::wants(
                $user,
                NotificationCategory::forNotificationClass((string) $notification->type),
                'digest',
            ))
            ->map(fn (DatabaseNotification $notification) => [
                'title' => (string) ($notification->data['title'] ?? 'Update'),
                'body' => $notification->data['body'] ?? null,
                'url' => $notification->data['url'] ?? null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
