<?php

namespace App\Notifications;

use App\Models\GridPlan;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step 18.11 — told to the grid's creator the first time a grid post fails or leaves the
 * schedule while later tiles are still scheduled, before the profile goes out of order.
 */
class GridOrderAtRisk extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly GridPlan $plan,
        private readonly int $position,
        private readonly string $reason,
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
        return [
            'title' => 'Grid order at risk',
            'body' => "{$this->reason} Later tiles in “{$this->plan->name}” are still scheduled. Hold the rest to keep the grid in order.",
            'url' => route('grids.edit', $this->plan),
            'grid_plan_id' => $this->plan->id,
            'tile_position' => $this->position,
        ];
    }
}
