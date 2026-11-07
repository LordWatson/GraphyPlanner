<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\MessageDirection;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which clients are worth keeping.
 *
 * The question every agency owner asks and no social tool answers, because most of them stop at
 * scheduling. Everything needed is already stored — invoices on one side, and on the other the
 * work the app watched happen — so this is mostly joins.
 *
 * **Effort is inferred, not timed.** Nobody fills in timesheets, and a margin nobody maintains is
 * worth less than an estimated one everybody can see. Posts produced, revision rounds, assets,
 * inbox replies and reports each carry a configurable weight in minutes
 * (`config/profitability.php`); a client with `estimated_hours_per_month` set overrides the lot,
 * for agencies who do track their time. Every figure is labelled an estimate wherever it is shown.
 *
 * **Revision rounds are the point.** They are weighted heavily and trended separately, because
 * "revisions climbing while the retainer stays flat" is the pattern that quietly destroys an
 * agency's margin — and it is invisible in any per-post view.
 *
 * Internal only. This never goes near a client-facing report: it is the agency's own view of its
 * own economics.
 *
 * @phpstan-type ClientProfit array{client_id: int, client_name: string, months: int, revenue_minor: int, hours: float, inferred: bool, cost_minor: int|null, margin_minor: int|null, margin_pct: float|null, effort: array<string, int>, revisions_recent: int, revisions_previous: int, revisions_climbing: bool, rate_configured: bool}
 */
class ClientProfitabilityService
{
    /**
     * Profitability for one client over the trailing `$months`.
     *
     * @return ClientProfit
     */
    public function forClient(Client $client, ?int $months = null, ?CarbonImmutable $now = null): array
    {
        $months = max(1, $months ?? 3);
        $now ??= CarbonImmutable::now();
        $since = $now->startOfMonth()->subMonths($months - 1);

        $organization = $client->organization;
        $rate = self::hourlyRateMinor($organization);

        $effort = $this->effort($client, $since, $now);
        $inferredHours = $this->hoursFrom($effort);

        // A client whose hours are tracked for real: their number wins, scaled to the window.
        $hours = $client->estimated_hours_per_month !== null
            ? (float) $client->estimated_hours_per_month * $months
            : $inferredHours;

        $revenue = $this->revenueMinor($client, $since);

        // No rate means no margin — reporting one anyway would print a figure that is
        // arithmetically true and completely useless.
        $cost = $rate > 0 ? (int) round($hours * $rate) : null;
        $margin = $cost === null ? null : $revenue - $cost;

        [$recent, $previous] = $this->revisionTrend($client, $now);

        return [
            'client_id' => $client->id,
            'client_name' => $client->name,
            'months' => $months,
            'revenue_minor' => $revenue,
            'hours' => round($hours, 1),
            'inferred' => $client->estimated_hours_per_month === null,
            'cost_minor' => $cost,
            'margin_minor' => $margin,
            'margin_pct' => $margin === null || $revenue <= 0 ? null : round($margin / $revenue * 100, 1),
            'effort' => $effort,
            'revisions_recent' => $recent,
            'revisions_previous' => $previous,
            // Strictly more, not merely different: one extra round is noise, a rise is a pattern.
            'revisions_climbing' => $previous > 0 && $recent > $previous,
            'rate_configured' => $rate > 0,
        ];
    }

    /**
     * Every active client of an organization, worst margin first — which is the order an owner
     * wants to read it in.
     *
     * @return list<ClientProfit>
     */
    public function forOrganization(Organization $organization, ?int $months = null, ?CarbonImmutable $now = null): array
    {
        $rows = $organization->clients()
            ->with('organization')
            ->get()
            ->map(fn (Client $client) => $this->forClient($client, $months, $now))
            ->all();

        usort($rows, function (array $a, array $b): int {
            // Clients with no computable margin sort last: they are a configuration problem, not a
            // profitability one, and burying the real answer under them would be unhelpful.
            if ($a['margin_minor'] === null || $b['margin_minor'] === null) {
                return ($a['margin_minor'] === null ? 1 : 0) <=> ($b['margin_minor'] === null ? 1 : 0);
            }

            return $a['margin_minor'] <=> $b['margin_minor'];
        });

        return $rows;
    }

    /**
     * What the app watched happen for this client in the window.
     *
     * @return array<string, int>
     */
    private function effort(Client $client, CarbonImmutable $since, CarbonImmutable $now): array
    {
        $postIds = DB::table('posts')
            ->where('client_id', $client->id)
            ->where('created_at', '>=', $since)
            ->whereNull('deleted_at')
            ->pluck('id');

        return [
            'posts' => $postIds->count(),
            // Each time a client sent a post back. The expensive signal, counted from the activity
            // log rather than the post's current status, because a post can go round more than once
            // and the current status remembers only the last lap.
            'revision_rounds' => DB::table('post_activity_logs')
                ->whereIn('post_id', $postIds)
                ->where('to_status', PostStatus::ChangesRequested->value)
                ->count(),
            'assets' => DB::table('assets')
                ->where('client_id', $client->id)
                ->where('created_at', '>=', $since)
                ->whereNull('deleted_at')
                ->count(),
            'inbox_replies' => DB::table('inbox_messages')
                ->whereIn('inbox_conversation_id', DB::table('inbox_conversations')->where('client_id', $client->id)->select('id'))
                ->where('direction', MessageDirection::Outbound->value)
                ->where('created_at', '>=', $since)
                ->count(),
            'reports' => DB::table('client_reports')
                ->where('client_id', $client->id)
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }

    /**
     * @param  array<string, int>  $effort
     */
    private function hoursFrom(array $effort): float
    {
        /** @var array<string, int> $minutes */
        $minutes = (array) config('profitability.minutes', []);

        $total = 0;

        foreach ([
            'posts' => 'post',
            'revision_rounds' => 'revision_round',
            'assets' => 'asset',
            'inbox_replies' => 'inbox_reply',
            'reports' => 'report',
        ] as $countKey => $weightKey) {
            $total += ($effort[$countKey] ?? 0) * (int) ($minutes[$weightKey] ?? 0);
        }

        return $total / 60;
    }

    /**
     * Money actually received in the window, from paid invoices.
     *
     * Paid, not issued: an invoice that was sent and never settled is not revenue, and counting it
     * would flatter exactly the client an owner most needs to look at.
     */
    private function revenueMinor(Client $client, CarbonImmutable $since): int
    {
        $total = DB::table('invoices')
            ->where('client_id', $client->id)
            ->where('status', InvoiceStatus::Paid->value)
            ->where('paid_at', '>=', $since)
            ->whereNull('deleted_at')
            ->sum('amount');

        // `invoices.amount` is a decimal string in major units; everything here is minor.
        return (int) round(((float) $total) * 100);
    }

    /**
     * Revision rounds in the recent window against the window before it.
     *
     * @return array{0: int, 1: int}
     */
    private function revisionTrend(Client $client, CarbonImmutable $now): array
    {
        $months = max(1, (int) config('profitability.trend_months', 3));
        $recentFrom = $now->startOfMonth()->subMonths($months - 1);
        $previousFrom = $recentFrom->subMonths($months);

        $count = function (CarbonImmutable $from, CarbonImmutable $to) use ($client): int {
            return DB::table('post_activity_logs')
                ->whereIn('post_id', DB::table('posts')->where('client_id', $client->id)->select('id'))
                ->where('to_status', PostStatus::ChangesRequested->value)
                ->where('post_activity_logs.created_at', '>=', $from)
                ->where('post_activity_logs.created_at', '<', $to)
                ->count();
        };

        return [
            $count($recentFrom, $now->addDay()),
            $count($previousFrom, $recentFrom),
        ];
    }

    /**
     * The organization's blended rate, falling back to the configured default.
     */
    public static function hourlyRateMinor(?Organization $organization): int
    {
        $rate = $organization === null ? null : $organization->blended_hourly_rate_minor;

        return (int) ($rate ?? config('profitability.default_hourly_rate_minor', 0));
    }
}
