<?php

namespace App\Actions\SuperAdmin;

use App\Enums\ClientStatus;
use App\Enums\ConnectionStatus;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Organization;
use App\Models\PlanAuditLog;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use App\Support\Plans\Plan;
use App\Support\Plans\PlanCatalog;
use Carbon\CarbonImmutable;

/**
 * Step 15.3 — everything the super admin's organization detail page shows: core details and
 * suspension state, the member roster, a client snapshot and headline counts. Read-only.
 */
class GetOrganizationDetailAction
{
    private const CLIENT_LIMIT = 12;

    /**
     * @return array{organization: array<string, mixed>, members: list<array<string, mixed>>, clients: list<array<string, mixed>>, counts: array<string, int>, last_published_at: string|null}
     */
    public function __invoke(Organization $organization): array
    {
        $organization->loadMissing(['owner', 'suspendedBy:id,name']);

        $members = $organization->users()
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'org_id', 'name', 'email', 'role', 'email_verified_at', 'created_at']);

        $clients = $organization->clients()
            ->latest()
            ->limit(self::CLIENT_LIMIT)
            ->get(['id', 'org_id', 'name', 'status', 'created_at']);

        $lastPublishedAt = PostActivityLog::query()
            ->whereIn('post_id', Post::query()->where('org_id', $organization->id)->select('id'))
            ->where('to_status', PostStatus::Published)
            ->max('created_at');

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'default_timezone' => $organization->default_timezone,
                'default_currency' => $organization->default_currency,
                'ai_monthly_image_quota' => $organization->ai_monthly_image_quota,
                'is_design_partner' => $organization->is_design_partner,
                'created_at' => $organization->created_at?->toIso8601String(),
                'is_suspended' => $organization->isSuspended(),
                'suspended_at' => $organization->suspended_at?->toIso8601String(),
                'suspension_reason' => $organization->suspension_reason,
                'suspended_by' => $organization->suspendedBy?->name,
                'is_preview' => $organization->isPreviewFixture(),
                'has_upload_post_key' => filled($organization->upload_post_key),
                'owner' => $organization->owner ? [
                    'id' => $organization->owner->id,
                    'name' => $organization->owner->name,
                    'email' => $organization->owner->email,
                ] : null,
            ],
            'members' => array_values($members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role?->value,
                'role_label' => $member->role?->label(),
                'email_verified' => $member->email_verified_at !== null,
                'created_at' => $member->created_at?->toIso8601String(),
            ])->all()),
            'clients' => array_values($clients->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'status' => $client->status->value,
                'status_label' => $client->status->label(),
                'created_at' => $client->created_at?->toIso8601String(),
            ])->all()),
            'counts' => [
                'users' => $members->count(),
                'clients' => $organization->clients()->count(),
                'active_clients' => $organization->clients()->where('status', ClientStatus::Active)->count(),
                'posts' => $organization->posts()->count(),
                'published_posts' => $organization->posts()->where('status', PostStatus::Published)->count(),
                'scheduled_posts' => $organization->posts()->where('status', PostStatus::Scheduled)->count(),
                'invoices' => $organization->invoices()->count(),
                'connected_accounts' => SocialAccount::query()
                    ->where('org_id', $organization->id)
                    ->where('connection_status', ConnectionStatus::Connected)
                    ->count(),
            ],
            'last_published_at' => $lastPublishedAt ? CarbonImmutable::parse($lastPublishedAt)->toIso8601String() : null,
            'billing' => $this->billing($organization),
            'planOptions' => array_map(
                fn (Plan $plan) => ['key' => $plan->key, 'name' => $plan->name(), 'segment' => $plan->segment()],
                array_values(app(PlanCatalog::class)->all()),
            ),
        ];
    }

    /**
     * Step CG-2.6 — the plan, billing override, AI credit grant and audit trail for the super-admin
     * org detail page.
     *
     * @return array<string, mixed>
     */
    private function billing(Organization $organization): array
    {
        $audit = $organization->planAuditLogs()
            ->with('actor:id,name')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (PlanAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'from_plan' => $log->from_plan,
                'to_plan' => $log->to_plan,
                'note' => $log->note,
                'meta' => $log->meta,
                'actor' => $log->actor?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all();

        return [
            'plan' => $organization->plan,
            'effective_plan' => $organization->effectivePlanKey(),
            'plan_name' => $organization->currentPlan()->name(),
            'billing_enabled' => $organization->billing_enabled,
            'global_billing_enabled' => app(PlatformSettings::class)->billingEnabled(),
            'has_stripe_customer' => $organization->hasStripeId(),
            'bonus_ai_text_credits' => $organization->bonusAiTextCredits(),
            'bonus_ai_credits_expires_at' => $organization->bonus_ai_credits_expires_at?->toIso8601String(),
            'audit' => $audit,
        ];
    }
}
