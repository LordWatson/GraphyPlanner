<?php

namespace App\Support\Campaigns;

use App\Actions\Campaigns\CampaignStatusTransitionMap;
use App\Enums\CampaignBillingType;
use App\Enums\InvoiceStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\AiUsageLog;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Step C-0.4 — the one place a campaign becomes props. Replaces `CampaignController::transform()`
 * so every staff page, the hub and the portal render a campaign the same way.
 *
 * `forPortal()` is an **allowlist**: it can never emit billing fields, internal notes, health,
 * readiness or owner details, whatever the model carries. `forStaff()` drops billing for anyone
 * but Owner/Strategist (`CampaignPolicy::viewBilling`), so a Designer prop can't carry it either.
 */
final class CampaignPresenter
{
    /**
     * @param  array<string, mixed>  $extra  derived values the caller already computed (health,
     *                                       readiness, counts) merged in as-is
     * @param  bool  $withBilling  false for list rows (the hub), which never show money and must not
     *                             run the invoiced-sum query per row
     * @return array<string, mixed>
     */
    public static function forStaff(Campaign $campaign, User $user, CarbonImmutable $today, array $extra = [], bool $withBilling = true): array
    {
        $canTransition = $user->can('transition', $campaign);

        return [
            ...self::identity($campaign),
            'notes' => $campaign->notes,
            'objective' => $campaign->objective?->value,
            'objective_label' => $campaign->objective?->label(),
            'platforms' => $campaign->platforms ?? [],
            'hashtags' => $campaign->hashtags ?? [],
            'default_link_url' => $campaign->default_link_url,
            'utm_campaign' => $campaign->utm_campaign,
            'owner' => $campaign->owner_id === null ? null : [
                'id' => $campaign->owner_id,
                'name' => $campaign->owner?->name,
            ],
            'client' => [
                'id' => $campaign->client_id,
                'name' => $campaign->client?->name,
            ],
            'timeline' => CampaignTimeline::for($campaign, $today)->toArray(),
            'billing' => $withBilling && $user->can('viewBilling', $campaign) ? self::billing($campaign, $user) : null,
            'transitions' => $canTransition
                ? array_map(fn ($to) => [
                    'to' => $to->value,
                    'label' => CampaignStatusTransitionMap::actionLabel($campaign->status, $to),
                ], CampaignStatusTransitionMap::allowedFrom($campaign->status))
                : [],
            ...$extra,
        ];
    }

    /**
     * The client portal's view of a campaign. Allowlisted keys only.
     *
     * @return array<string, mixed>
     */
    public static function forPortal(Campaign $campaign, CarbonImmutable $today): array
    {
        return [
            ...self::identity($campaign),
            'timeline' => CampaignTimeline::for($campaign, $today)->toArray(),
        ];
    }

    /**
     * The compact chip shown wherever a post appears (Step C-1.3).
     *
     * @return array{id: int, name: string, code: string|null, colour: string|null}
     */
    public static function chip(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'code' => $campaign->code,
            'colour' => $campaign->colour?->value,
        ];
    }

    /**
     * A post inside a campaign page (board, approvals, overview). Expects `targets.socialAccount`
     * loaded; `deliverable` when the phase matters.
     *
     * @return array<string, mixed>
     */
    public static function post(Post $post): array
    {
        $first = $post->targets
            ->filter(fn (PostTarget $target) => $target->scheduled_local_date !== null)
            ->sortBy(fn (PostTarget $target) => $target->scheduled_at_utc?->getTimestamp() ?? PHP_INT_MAX)
            ->first();

        return [
            'id' => $post->id,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'master_caption' => $post->master_caption,
            'targets' => $post->targets->map(fn (PostTarget $target) => [
                'platform' => $target->socialAccount?->platform->value,
                'handle' => $target->socialAccount?->handle,
            ])->values()->all(),
            'scheduled_local_date' => $first?->scheduled_local_date?->toDateString(),
            'scheduled_local_time' => $first?->scheduled_local_time,
            'scheduled_at_utc' => $first?->scheduled_at_utc?->toIso8601String(),
            'deliverable_id' => $post->relationLoaded('deliverable') ? $post->deliverable?->id : null,
            'phase_id' => $post->relationLoaded('deliverable') ? $post->deliverable?->campaign_phase_id : null,
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A post on the portal campaign page: client-visible statuses only (enforced by the query),
     * no internal fields.
     *
     * @return array<string, mixed>
     */
    public static function portalPost(Post $post): array
    {
        $row = self::post($post);

        return [
            'id' => $row['id'],
            'status' => $row['status'],
            'status_label' => $row['status_label'],
            'master_caption' => $row['master_caption'],
            'targets' => $row['targets'],
            'scheduled_local_date' => $row['scheduled_local_date'],
            'scheduled_local_time' => $row['scheduled_local_time'],
            'phase_id' => $row['phase_id'],
            'needs_review' => $post->status === PostStatus::WaitingClient,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function identity(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'code' => $campaign->code,
            'colour' => $campaign->colour?->value,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
            'goal' => $campaign->goal,
        ];
    }

    /**
     * Step C-8.1/C-8.2 — Owner/Strategist only. `invoiced` sums every line billing this campaign
     * on a non-void invoice.
     *
     * @return array<string, mixed>
     */
    private static function billing(Campaign $campaign, User $user): array
    {
        // One grouped query for invoiced and paid (C-8.3), one sum for AI spend.
        $totals = $campaign->invoiceItems()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', '!=', InvoiceStatus::Void->value)
            ->toBase()
            ->selectRaw('coalesce(sum(invoice_items.price * invoice_items.quantity), 0) as invoiced')
            ->selectRaw('coalesce(sum(case when invoices.status = ? then invoice_items.price * invoice_items.quantity else 0 end), 0) as paid', [InvoiceStatus::Paid->value])
            ->first();
        // AI spend follows the analytics rule (`viewAiCost`, Owner only), stricter than billing.
        $aiCost = $user->organization !== null && $user->can('viewAiCost', $user->organization)
            ? round((float) AiUsageLog::query()->where('campaign_id', $campaign->id)->sum('cost_estimate'), 4)
            : null;

        return [
            'billing_type' => $campaign->billing_type?->value,
            'billing_type_label' => $campaign->billing_type?->label(),
            'fee_amount' => $campaign->fee_amount,
            'invoiced_amount' => number_format((float) ($totals->invoiced ?? 0), 2, '.', ''),
            'paid_amount' => number_format((float) ($totals->paid ?? 0), 2, '.', ''),
            // AI spend is an estimate in USD (provider pricing), not the invoice currency.
            'ai_cost_estimate' => $aiCost,
            'is_billable' => $campaign->billing_type === CampaignBillingType::FixedFee && $campaign->fee_amount !== null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function platformOptions(): array
    {
        return array_map(fn (Platform $platform) => ['value' => $platform->value, 'label' => $platform->label()], Platform::cases());
    }

    /**
     * Step C-3.4 — one template as the gallery and the first-campaign prompt (C-9.3) show it.
     *
     * @return array{id: int, name: string, description: string|null, objective_label: string|null, duration_days: int, phases: array<int, mixed>, deliverables_count: int, platforms: list<string>, has_brief: bool, is_starter: bool}
     */
    public static function templateSummary(CampaignTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'objective_label' => $template->objective?->label(),
            'duration_days' => (int) $template->duration_days,
            'phases' => $template->phases ?? [],
            'deliverables_count' => count($template->deliverables ?? []),
            'platforms' => array_values(array_unique(array_column($template->deliverables ?? [], 'platform'))),
            'has_brief' => ! empty($template->brief),
            'is_starter' => (bool) $template->is_starter,
        ];
    }
}
