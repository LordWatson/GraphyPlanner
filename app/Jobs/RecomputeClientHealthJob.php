<?php

namespace App\Jobs;

use App\Actions\Clients\RecomputeClientHealthAction;
use App\Models\Client;
use App\Models\Scopes\OrganizationScope;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Step M-3.4 — re-materialize one client's health after something its v2 signals read changed
 * (a post's status, an invoice, the Brand Brain). Queued and unique per client for a minute, so a
 * bulk move of fifty posts recomputes once, and never on the request that made the change. The
 * hourly `clients:recompute-health` sweep catches anything time-based (an approval going overdue).
 */
class RecomputeClientHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $clientId)
    {
        $this->onQueue(Queues::DEFAULT);
    }

    public function uniqueId(): string
    {
        return (string) $this->clientId;
    }

    public function handle(RecomputeClientHealthAction $recompute): void
    {
        $client = Client::query()->withoutGlobalScope(OrganizationScope::class)->find($this->clientId);

        if ($client !== null) {
            $recompute($client);
        }
    }
}
