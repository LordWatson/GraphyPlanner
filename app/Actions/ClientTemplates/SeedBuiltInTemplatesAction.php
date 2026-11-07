<?php

namespace App\Actions\ClientTemplates;

use App\Enums\ApprovalMode;
use App\Enums\ApprovalPolicy;
use App\Enums\BillingCycle;
use App\Enums\ClientReportSchedule;
use App\Enums\ClientStatus;
use App\Models\ClientTemplate;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * The three templates an organization starts with.
 *
 * Shipping some matters more than it looks: an empty template list teaches nobody what a template
 * is for, and the feature goes unused. Three recognisable engagement shapes make the idea obvious
 * in one glance, and the first thing most agencies do is edit one rather than start from nothing.
 *
 * Seeded as **rows per organization**, not read from config, so an Owner can edit or delete one and
 * have it stay edited or deleted. A built-in read from config would reappear, which is worse than
 * not having it.
 *
 * Idempotent: run on registration and safe to re-run (a `backfill` command uses that).
 */
class SeedBuiltInTemplatesAction
{
    /**
     * @return int how many were created
     */
    public function __invoke(Organization $organization): int
    {
        $created = 0;

        foreach (self::definitions() as $definition) {
            $exists = ClientTemplate::query()
                ->withoutOrgScope()
                ->where('org_id', $organization->id)
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::transaction(fn () => ClientTemplate::query()->create([
                'org_id' => $organization->id,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'snapshot' => ['client' => $definition['client'], 'brand_brain' => $definition['brand_brain']],
                'is_built_in' => true,
            ]));

            $created++;
        }

        return $created;
    }

    /**
     * Three shapes that differ in the ways that actually matter — who signs off, how often you
     * post, and how you bill — rather than three variations on the same thing.
     *
     * @return list<array{name: string, description: string, client: array<string, mixed>, brand_brain: array<string, mixed>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Local service business',
                'description' => 'Steady cadence, one approver, monthly retainer. Plumbers, clinics, salons.',
                'client' => [
                    'status' => ClientStatus::Active->value,
                    'default_approval_mode' => ApprovalMode::ClientRequired->value,
                    // One owner signs everything off, so any single approval releases a post.
                    'approval_policy' => ApprovalPolicy::Any->value,
                    'approval_lead_days' => 2,
                    'target_posts_per_week' => 3,
                    'billing_cycle' => BillingCycle::Monthly->value,
                    'auto_invoice_retainer' => true,
                    'report_schedule' => ClientReportSchedule::Review->value,
                    'tags' => ['local'],
                ],
                'brand_brain' => [
                    'audience' => 'People nearby looking for this service now, plus past customers worth reminding.',
                    'content_pillars' => ['Proof of work', 'Team and premises', 'Offers and availability', 'Local news'],
                    'voice' => 'Plain, warm, specific. Prices and opening hours beat adjectives.',
                ],
            ],
            [
                'name' => 'E-commerce brand',
                'description' => 'High volume, fast turnaround, product-led. Approvals kept light so the calendar can move.',
                'client' => [
                    'status' => ClientStatus::Active->value,
                    // Volume only works if the agency is trusted to post; a per-post client
                    // sign-off at this cadence becomes the bottleneck.
                    'default_approval_mode' => ApprovalMode::InternalOnly->value,
                    'approval_policy' => ApprovalPolicy::Any->value,
                    'approval_lead_days' => 1,
                    'target_posts_per_week' => 7,
                    'billing_cycle' => BillingCycle::Monthly->value,
                    'auto_invoice_retainer' => true,
                    'report_schedule' => ClientReportSchedule::Review->value,
                    'tags' => ['ecommerce'],
                ],
                'brand_brain' => [
                    'audience' => 'Existing customers and lookalikes; buying intent is close to the surface.',
                    'content_pillars' => ['New in', 'Bestsellers', 'Customer photos', 'Behind the product', 'Seasonal'],
                    'voice' => 'Energetic but not shouty. Lead with the product, not the brand.',
                ],
            ],
            [
                'name' => 'B2B thought leadership',
                'description' => 'Lower volume, higher scrutiny. Sequential sign-off for marketing then legal.',
                'client' => [
                    'status' => ClientStatus::Active->value,
                    'default_approval_mode' => ApprovalMode::ClientRequired->value,
                    // Marketing, then legal, in that order — the case the `sequential` policy exists
                    // for, and the one most agency tools cannot express at all.
                    'approval_policy' => ApprovalPolicy::Sequential->value,
                    'approval_lead_days' => 5,
                    'target_posts_per_week' => 2,
                    'billing_cycle' => BillingCycle::Monthly->value,
                    'auto_invoice_retainer' => true,
                    'report_schedule' => ClientReportSchedule::Review->value,
                    'tags' => ['b2b'],
                ],
                'brand_brain' => [
                    'audience' => 'Buyers and the people who influence them; a long consideration window.',
                    'content_pillars' => ['Point of view', 'Customer outcomes', 'Original data', 'Hiring and culture'],
                    'voice' => 'Considered and specific. A claim always arrives with its evidence.',
                ],
            ],
        ];
    }
}
