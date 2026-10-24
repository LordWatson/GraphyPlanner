<?php

namespace App\Notifications;

use App\Models\ProductFeedback;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Step M-8.2 — a partner org's staff member sent in-app product feedback. Delivered to every super
 * admin's notification bell (database only), linking to the platform feedback inbox.
 */
class ProductFeedbackReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ProductFeedback $feedback) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $org = $this->feedback->organization->name;

        return [
            'title' => 'New product feedback',
            'body' => sprintf('%s sent feedback: “%s”', $org, Str::limit(trim($this->feedback->message), 120)),
            'url' => route('super-admin.feedback.index'),
            'product_feedback_id' => $this->feedback->id,
        ];
    }
}
