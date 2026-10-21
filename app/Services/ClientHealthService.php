<?php

namespace App\Services;

use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Enums\ConnectionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes a client's health (Red/Amber/Green) from **weighted reasons** (Step M-3.4 / I-6.1).
 *
 * Every signal that fires becomes a reason `{key, tone, weight, label, owner_only}`; the client's
 * health is the worst tone among them and its headline reason is the heaviest. With nothing
 * wrong it's Green, "Active client in good standing." Signals:
 *
 * - **Profile & billing setup** (v1): offboarding, no owner, no billing setup, paused, no start
 *   date, partial billing setup.
 * - **Connections** (Step 1.6 / M-2.7): an expired/revoked token (red), one about to lapse.
 * - **Publishing:** posts that failed or only partly published in the last
 *   `clients.health.failed_publish_days` (red at `failed_publish_red`).
 * - **Approvals:** posts waiting on the client past `approval_overdue_hours` (red when one is due
 *   to go out within a day, or already late).
 * - **Cadence:** published posts over the last `cadence_days` below `cadence_ratio` of
 *   `clients.target_posts_per_week` (prorated for a client that started inside the window).
 * - **Calendar gap:** nothing planned in the next `calendar_gap_days`.
 * - **Unpaid overdue invoices** — `owner_only`: counts and ages, never amounts; red past
 *   `invoice_overdue_red_days`. Hidden from non-Owners by `Client::healthFor()`.
 * - **Brand Brain** gaps (tone of voice, audience, content pillars) — an *advisory* (green) reason:
 *   listed with the others, but it never turns a client amber on its own.
 *
 * Archived clients are Green with no reasons. Operational signals (cadence, calendar gap, Brand
 * Brain) only apply to active clients. Each rule is unit-tested in `ClientHealthServiceTest`.
 */
class ClientHealthService
{
    /**
     * @return array{status: ClientHealth, reason: string, reasons: list<array{key: string, tone: string, weight: int, label: string, owner_only: bool}>}
     */
    public function compute(Client $client, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        if ($client->status === ClientStatus::Archived) {
            return ['status' => ClientHealth::Green, 'reason' => 'Client is archived; no active engagement risk.', 'reasons' => []];
        }

        $reasons = [
            ...$this->profile($client),
            ...$this->connections($client),
            ...($client->exists ? $this->workflow($client, $now) : []),
        ];

        usort($reasons, fn (array $a, array $b) => [$b['weight'], $a['key']] <=> [$a['weight'], $b['key']]);

        $tones = array_column($reasons, 'tone');
        $status = match (true) {
            in_array(ClientHealth::Red->value, $tones, true) => ClientHealth::Red,
            in_array(ClientHealth::Amber->value, $tones, true) => ClientHealth::Amber,
            default => ClientHealth::Green,
        };

        // Advisory (green) reasons are listed but never become the headline.
        return [
            'status' => $status,
            'reason' => $status === ClientHealth::Green ? 'Active client in good standing.' : $reasons[0]['label'],
            'reasons' => $reasons,
        ];
    }

    /**
     * @return list<array{key: string, tone: string, weight: int, label: string, owner_only: bool}>
     */
    private function profile(Client $client): array
    {
        $active = $client->status === ClientStatus::Active;
        $noRetainer = $client->retainer_amount === null;
        $noCycle = $client->billing_cycle === null;

        return array_values(array_filter([
            $client->status === ClientStatus::Offboarding ? $this->red('offboarding', 100, 'Client is offboarding.') : null,
            $active && $client->owner_user_id === null ? $this->red('no_owner', 90, 'Active client has no assigned owner.') : null,
            $active && $noRetainer && $noCycle ? $this->red('no_billing', 85, 'Active client has no billing setup (missing retainer amount and billing cycle).') : null,
            $client->status === ClientStatus::Paused ? $this->amber('paused', 70, 'Client is paused.') : null,
            $active && $noRetainer !== $noCycle ? $this->amber('partial_billing', 30, 'Client billing setup is incomplete (retainer amount or billing cycle missing).') : null,
            $active && $client->start_date === null ? $this->amber('no_start_date', 20, 'Active client has no start date recorded.') : null,
        ]));
    }

    /**
     * Step 1.6 / M-2.7. Uses `loadMissing()` so callers that haven't eager-loaded `socialAccounts`
     * (e.g. a single client detail page) still work.
     *
     * @return list<array{key: string, tone: string, weight: int, label: string, owner_only: bool}>
     */
    private function connections(Client $client): array
    {
        $accounts = $client->loadMissing('socialAccounts')->socialAccounts;
        $reasons = [];

        if ($accounts->contains(fn ($account) => $account->connection_status === ConnectionStatus::TokenExpired)) {
            $reasons[] = $this->red('token_expired', 95, 'A connected social account has an expired/revoked token.');
        }

        $expiring = $accounts->first(fn (SocialAccount $account) => $account->tokenExpiryStage() !== null);

        if ($expiring !== null) {
            $reasons[] = $this->amber('token_expiring', 55, sprintf(
                'The %s connection for %s %s — reconnect it.',
                $expiring->platform->label(),
                $expiring->handle,
                $expiring->tokenExpiryPhrase(),
            ));
        }

        return $reasons;
    }

    /**
     * The signals that need the client's posts, invoices and Brand Brain.
     *
     * @return list<array{key: string, tone: string, weight: int, label: string, owner_only: bool}>
     */
    private function workflow(Client $client, CarbonImmutable $now): array
    {
        $config = (array) config('clients.health', []);
        $reasons = [];

        if ($reason = $this->failedPublishes($client, $now, $config)) {
            $reasons[] = $reason;
        }

        if ($reason = $this->overdueApprovals($client, $now, $config)) {
            $reasons[] = $reason;
        }

        if ($reason = $this->overdueInvoices($client, $now, $config)) {
            $reasons[] = $reason;
        }

        if ($client->status === ClientStatus::Active) {
            foreach ([$this->cadence($client, $now, $config), $this->calendarGap($client, $now, $config), $this->brandBrain($client)] as $reason) {
                if ($reason !== null) {
                    $reasons[] = $reason;
                }
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}|null
     */
    private function failedPublishes(Client $client, CarbonImmutable $now, array $config): ?array
    {
        $days = max(1, (int) ($config['failed_publish_days'] ?? 7));
        $failedStatuses = [PostStatus::Failed->value, PostStatus::PartiallyPublished->value];

        $count = DB::table('post_activity_logs')
            ->join('posts', 'posts.id', '=', 'post_activity_logs.post_id')
            ->where('posts.client_id', $client->id)
            ->whereNull('posts.deleted_at')
            ->whereIn('posts.status', $failedStatuses)
            ->whereIn('post_activity_logs.to_status', $failedStatuses)
            ->where('post_activity_logs.created_at', '>=', $now->subDays($days))
            ->distinct()
            ->count('post_activity_logs.post_id');

        if ($count === 0) {
            return null;
        }

        $label = sprintf('%s failed to publish in the last %d days — retry or fix %s.', $this->posts($count), $days, $count === 1 ? 'it' : 'them');

        return $count >= max(1, (int) ($config['failed_publish_red'] ?? 3))
            ? $this->red('failed_publishes', 80, $label)
            : $this->amber('failed_publishes', 65, $label);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}|null
     */
    private function overdueApprovals(Client $client, CarbonImmutable $now, array $config): ?array
    {
        $hours = max(1, (int) ($config['approval_overdue_hours'] ?? 48));

        $waiting = DB::table('posts')
            ->where('posts.client_id', $client->id)
            ->whereNull('posts.deleted_at')
            ->where('posts.status', PostStatus::WaitingClient->value)
            ->select('posts.id', 'posts.updated_at')
            ->selectSub(fn ($query) => $query->from('post_activity_logs')
                ->whereColumn('post_activity_logs.post_id', 'posts.id')
                ->where('post_activity_logs.to_status', PostStatus::WaitingClient->value)
                ->selectRaw('MAX(post_activity_logs.created_at)'), 'sent_at')
            ->selectSub(fn ($query) => $query->from('post_targets')
                ->whereColumn('post_targets.post_id', 'posts.id')
                ->selectRaw('MIN(post_targets.scheduled_at_utc)'), 'due_at')
            ->get();

        $overdue = $waiting->filter(fn ($post) => CarbonImmutable::parse((string) ($post->sent_at ?? $post->updated_at), 'UTC')->lessThan($now->subHours($hours)));

        if ($overdue->isEmpty()) {
            return null;
        }

        $urgent = $overdue->contains(fn ($post) => $post->due_at !== null && CarbonImmutable::parse((string) $post->due_at, 'UTC')->lessThan($now->addDay()));
        $span = $hours % 24 === 0 ? ($hours / 24).' '.($hours === 24 ? 'day' : 'days') : "{$hours} hours";

        return $urgent
            ? $this->red('overdue_approvals', 78, sprintf('%s waiting on the client for over %s — one is due to go out within a day.', $this->posts($overdue->count()), $span))
            : $this->amber('overdue_approvals', 60, sprintf('%s waiting on the client for over %s.', $this->posts($overdue->count()), $span));
    }

    /**
     * Owner-only: counts and ages, never amounts.
     *
     * @param  array<string, mixed>  $config
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}|null
     */
    private function overdueInvoices(Client $client, CarbonImmutable $now, array $config): ?array
    {
        $today = $now->setTimezone($this->timezone($client))->toDateString();

        $row = DB::table('invoices')
            ->where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->where('status', InvoiceStatus::Sent->value)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->selectRaw('COUNT(*) as aggregate, MIN(due_date) as oldest')
            ->first();

        $count = (int) ($row->aggregate ?? 0);

        if ($count === 0) {
            return null;
        }

        $days = (int) CarbonImmutable::parse(substr((string) $row->oldest, 0, 10))->diffInDays(CarbonImmutable::parse($today));
        $label = sprintf('%d %s overdue (oldest by %d %s).', $count, $count === 1 ? 'invoice is' : 'invoices are', $days, $days === 1 ? 'day' : 'days');

        return $days > max(1, (int) ($config['invoice_overdue_red_days'] ?? 30))
            ? $this->red('overdue_invoices', 76, $label, ownerOnly: true)
            : $this->amber('overdue_invoices', 58, $label, ownerOnly: true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}|null
     */
    private function cadence(Client $client, CarbonImmutable $now, array $config): ?array
    {
        $target = (int) ($client->target_posts_per_week ?? 0);

        if ($target <= 0) {
            return null;
        }

        $window = max(7, (int) ($config['cadence_days'] ?? 28));
        $started = CarbonImmutable::parse(($client->start_date ?? $client->created_at ?? $now)->toDateTimeString(), 'UTC');
        $from = $now->subDays($window)->max($started);
        $weeks = max(1.0, $from->diffInDays($now) / 7);

        $published = DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->where('posts.client_id', $client->id)
            ->whereNull('posts.deleted_at')
            ->where('post_targets.status', PostTargetStatus::Published->value)
            ->where('post_targets.published_at', '>=', $from)
            ->distinct()
            ->count('posts.id');

        $perWeek = round($published / $weeks, 1);

        if ($perWeek >= $target * (float) ($config['cadence_ratio'] ?? 0.75)) {
            return null;
        }

        return $this->amber('cadence', 50, sprintf(
            'Posting %s a week against a target of %d.',
            rtrim(rtrim(number_format($perWeek, 1), '0'), '.') ?: '0',
            $target,
        ));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}|null
     */
    private function calendarGap(Client $client, CarbonImmutable $now, array $config): ?array
    {
        if ($client->start_date !== null && $client->start_date->isFuture()) {
            return null;
        }

        $hasAccounts = $client->relationLoaded('socialAccounts')
            ? $client->socialAccounts->isNotEmpty()
            : $client->socialAccounts()->exists();

        if (! $hasAccounts) {
            return null;
        }

        $days = max(1, (int) ($config['calendar_gap_days'] ?? 7));

        $planned = DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->where('posts.client_id', $client->id)
            ->whereNull('posts.deleted_at')
            ->whereNotIn('posts.status', [PostStatus::Idea->value, PostStatus::Archived->value, PostStatus::Failed->value])
            ->whereBetween('post_targets.scheduled_at_utc', [$now, $now->addDays($days)])
            ->exists();

        return $planned ? null : $this->amber('calendar_gap', 40, "Nothing planned for the next {$days} days.");
    }

    /**
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}|null
     */
    private function brandBrain(Client $client): ?array
    {
        // Read without caching the relation on the caller's model (it may be about to fill it in).
        $brain = $client->relationLoaded('brandBrain') ? $client->brandBrain : $client->brandBrain()->first();

        $missing = array_keys(array_filter([
            'tone of voice' => blank($brain?->voice['tone'] ?? null),
            'audience' => blank($brain?->audience['description'] ?? null),
            'content pillars' => array_filter($brain === null ? [] : ($brain->content_pillars ?? []), fn ($pillar) => filled($pillar)) === [],
        ]));

        if ($missing === []) {
            return null;
        }

        $list = count($missing) > 1
            ? implode(', ', array_slice($missing, 0, -1)).' and '.end($missing)
            : $missing[0];

        return $this->advisory('brand_brain', 10, "Brand Brain is missing {$list} — AI drafts will be generic.");
    }

    private function posts(int $count): string
    {
        return $count === 1 ? '1 post' : "{$count} posts";
    }

    private function timezone(Client $client): string
    {
        $timezone = (string) (DB::table('organizations')->where('id', $client->org_id)->value('default_timezone') ?? 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}
     */
    private function red(string $key, int $weight, string $label, bool $ownerOnly = false): array
    {
        return ['key' => $key, 'tone' => ClientHealth::Red->value, 'weight' => $weight, 'label' => $label, 'owner_only' => $ownerOnly];
    }

    /**
     * A nudge that's listed with the reasons but never changes the client's health on its own.
     *
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}
     */
    private function advisory(string $key, int $weight, string $label): array
    {
        return ['key' => $key, 'tone' => ClientHealth::Green->value, 'weight' => $weight, 'label' => $label, 'owner_only' => false];
    }

    /**
     * @return array{key: string, tone: string, weight: int, label: string, owner_only: bool}
     */
    private function amber(string $key, int $weight, string $label, bool $ownerOnly = false): array
    {
        return ['key' => $key, 'tone' => ClientHealth::Amber->value, 'weight' => $weight, 'label' => $label, 'owner_only' => $ownerOnly];
    }
}
