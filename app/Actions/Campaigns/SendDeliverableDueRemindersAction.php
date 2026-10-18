<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Notifications\CampaignDeliverableAssigned;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\DeliverableDueDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Step C-4.4 — the daily nudge for assigned deliverables: "due tomorrow" the day before a slot's
 * due date, and "overdue" the day after it passes (once, not every day). Due dates are the
 * planned date minus the client's approval lead time in working days, in each organization's own
 * timezone. Only the assignee is ever notified, and only for work that isn't approved yet.
 */
class SendDeliverableDueRemindersAction
{
    /**
     * Planned dates further out than this can't be due tomorrow with a lead time of up to ten
     * working days.
     */
    private const LOOKAHEAD_DAYS = 20;

    /**
     * @return array{due_tomorrow: int, overdue: int}
     */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $sent = ['due_tomorrow' => 0, 'overdue' => 0];
        $utcToday = ($now ?? CarbonImmutable::now())->startOfDay();

        CampaignDeliverable::query()
            ->whereNotNull('assignee_id')
            ->whereNotNull('planned_for')
            ->whereBetween('planned_for', [$utcToday->subDays(15)->toDateString(), $utcToday->addDays(self::LOOKAHEAD_DAYS)->toDateString()])
            ->whereIn('campaign_id', Campaign::query()->withoutOrgScope()->whereIn('status', [CampaignStatus::Planning, CampaignStatus::Active])->select('id'))
            ->with(['campaign' => fn ($query) => $query->withoutOrgScope()->with(['organization:id,default_timezone', 'client:id,approval_lead_days']), 'post:id,status', 'assignee'])
            ->chunkById(200, function ($deliverables) use (&$sent, $now) {
                foreach ($deliverables as $deliverable) {
                    $kind = $this->kind($deliverable, $now);

                    if ($kind === null || $deliverable->assignee === null) {
                        continue;
                    }

                    $deliverable->assignee->notify(new CampaignDeliverableAssigned($deliverable, $kind));
                    $sent[$kind === CampaignDeliverableAssigned::DUE_TOMORROW ? 'due_tomorrow' : 'overdue']++;
                }
            });

        Log::info('Campaign deliverable due reminders sent', $sent);

        return $sent;
    }

    private function kind(CampaignDeliverable $deliverable, ?CarbonImmutable $now): ?string
    {
        if ($deliverable->state()->isReady()) {
            return null;
        }

        $timezone = CampaignClock::timezone($deliverable->campaign->organization);
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $planned = CarbonImmutable::parse((string) $deliverable->planned_for?->toDateString(), $timezone);
        $due = DeliverableDueDate::for($planned, (int) $deliverable->campaign->client->approval_lead_days);

        return match (true) {
            $due === null => null,
            $due->equalTo($today->addDay()) => CampaignDeliverableAssigned::DUE_TOMORROW,
            $due->equalTo($today->subDay()) => CampaignDeliverableAssigned::OVERDUE,
            default => null,
        };
    }
}
