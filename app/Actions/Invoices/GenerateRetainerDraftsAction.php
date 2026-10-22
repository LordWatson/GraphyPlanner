<?php

namespace App\Actions\Invoices;

use App\Enums\BillingCycle;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\RetainerDraftsReady;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Step M-4.3 — generate this period's retainer invoices as DRAFTS for the team to review. Only
 * clients that opted in (`auto_invoice_retainer`) with a retainer and a recurring billing cycle
 * are included. It's idempotent on `(client_id, retainer_period)`, so running daily is safe: a
 * client already invoiced for the current period is skipped. Drafts are never finalized or sent —
 * auto-send stays out of the MVP.
 */
class GenerateRetainerDraftsAction
{
    /**
     * The recurring cycles this runs for (project-based clients are billed ad hoc).
     */
    private const CYCLES = [BillingCycle::Monthly, BillingCycle::Quarterly, BillingCycle::Annual];

    public function __construct(private readonly CreateInvoiceAction $createInvoice) {}

    /**
     * @return int the number of drafts created
     */
    public function __invoke(): int
    {
        $created = 0;

        /** @var array<int, int> $perOrg org_id => drafts created */
        $perOrg = [];

        Client::query()
            ->withoutOrgScope()
            ->where('auto_invoice_retainer', true)
            ->whereNotNull('retainer_amount')
            ->where('retainer_amount', '>', 0)
            ->whereIn('billing_cycle', array_map(fn (BillingCycle $cycle) => $cycle->value, self::CYCLES))
            ->with('organization')
            ->each(function (Client $client) use (&$created, &$perOrg) {
                if ($client->billing_cycle === null) {
                    return;
                }

                $now = Carbon::now($client->organization->default_timezone);
                $period = $this->periodKey($client->billing_cycle, $now);

                $exists = Invoice::query()
                    ->withoutOrgScope()
                    ->where('client_id', $client->id)
                    ->where('retainer_period', $period)
                    ->exists();

                if ($exists) {
                    return;
                }

                ($this->createInvoice)($client, [
                    'issue_date' => $now->toDateString(),
                    'due_date' => null,
                    'description' => 'Retainer — '.$this->periodLabel($client->billing_cycle, $now),
                    'retainer_period' => $period,
                ]);

                $created++;
                $perOrg[$client->org_id] = ($perOrg[$client->org_id] ?? 0) + 1;
            });

        foreach ($perOrg as $orgId => $count) {
            $this->notifyStudio($orgId, $count);
        }

        if ($created > 0) {
            Log::info('Retainer drafts generated', ['count' => $created, 'orgs' => array_keys($perOrg)]);
        }

        return $created;
    }

    private function periodKey(BillingCycle $cycle, Carbon $now): string
    {
        return match ($cycle) {
            BillingCycle::Monthly => $now->format('Y-m'),
            BillingCycle::Quarterly => $now->format('Y').'-Q'.$now->quarter,
            BillingCycle::Annual => $now->format('Y'),
            default => $now->format('Y-m'),
        };
    }

    private function periodLabel(BillingCycle $cycle, Carbon $now): string
    {
        return match ($cycle) {
            BillingCycle::Monthly => $now->format('F Y'),
            BillingCycle::Quarterly => 'Q'.$now->quarter.' '.$now->format('Y'),
            BillingCycle::Annual => $now->format('Y'),
            default => $now->format('F Y'),
        };
    }

    private function notifyStudio(int $orgId, int $count): void
    {
        User::query()
            ->where('org_id', $orgId)
            ->whereIn('role', [Role::Owner, Role::Strategist])
            ->get()
            ->each(fn (User $user) => $user->notify(new RetainerDraftsReady($count)));
    }
}
