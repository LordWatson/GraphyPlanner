<?php

namespace App\Notifications;

use App\Enums\GridPlanStatus;
use App\Models\GridPlan;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step 18.7 — a grid plan moved to a stage someone needs to hear about: sent for internal review
 * (the team), waiting on the client (the client's portal contacts), or the client approved /
 * asked for changes (the team). Bell only; the client's email is `ClientGridReviewRequestedMail`.
 */
class GridPlanStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly GridPlan $plan,
        private readonly GridPlanStatus $to,
        private readonly bool $forPortal,
    ) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL];
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
        [$title, $body] = match ($this->to) {
            GridPlanStatus::InternalReview => ['Grid ready for review', "“{$this->plan->name}” is ready for a look before it goes to the client."],
            GridPlanStatus::WaitingClient => ['Your new grid is ready', "“{$this->plan->name}” is ready for you to look at."],
            GridPlanStatus::Approved => ['Grid approved', "The client approved “{$this->plan->name}”."],
            GridPlanStatus::ChangesRequested => ['Changes requested on a grid', "The client asked for changes to “{$this->plan->name}”."],
            default => ['Grid updated', "“{$this->plan->name}” is now ".strtolower($this->to->label()).'.'],
        };

        return [
            'title' => $title,
            'body' => $body,
            'url' => $this->forPortal ? route('portal.grids.show', $this->plan) : route('grids.edit', $this->plan),
            'grid_plan_id' => $this->plan->id,
        ];
    }
}
