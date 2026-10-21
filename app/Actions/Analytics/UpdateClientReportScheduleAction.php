<?php

namespace App\Actions\Analytics;

use App\Enums\ClientReportSchedule;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-3.3 — how a client's monthly report is produced from now on. Reports already prepared
 * are left as they are.
 */
class UpdateClientReportScheduleAction
{
    public function __invoke(Client $client, ClientReportSchedule $schedule, User $user): void
    {
        $client->update(['report_schedule' => $schedule]);

        Log::info('Client report schedule changed', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'schedule' => $schedule->value,
            'user_id' => $user->id,
        ]);
    }
}
