<?php

namespace App\Support\Mcp;

use App\Enums\ClientHealth;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostTarget;

/**
 * Phase MCP-2 — the single place that maps Graphy models to the safe, read-only shapes MCP tools and
 * resources return. Centralising the field selection here (rather than inline in each tool) means the
 * "no secret ever leaves the server" rule (MCP-1.6) has one surface to audit: assets never expose
 * their signed `url`/`disk`/`path`, billing-sensitive client/campaign fields are gated behind the
 * viewer's `viewBilling` ability, and internal-only columns (raw health reasons, internal notes,
 * vendor ids, tokens) simply aren't listed. Every array below is hand-picked allow-list, never a
 * `->toArray()` of the model. See .junie/plans/mcp-server.md.
 */
class McpPresenters
{
    /**
     * A client as a compact list row (list_clients): identity + status only, no billing, no health
     * detail. Safe for any token holding `Read`.
     *
     * @return array<string, mixed>
     */
    public static function clientRow(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'status' => $client->status->value,
            'health' => $client->health_status?->value,
            'owner' => $client->relationLoaded('owner') && $client->owner !== null
                ? ['id' => $client->owner->id, 'name' => $client->owner->name]
                : null,
        ];
    }

    /**
     * A client profile (get_client): the operational fields an assistant needs to reason about the
     * brand, plus computed health. Billing fields appear only when the acting member may see them.
     *
     * @param  array{status: ClientHealth, reason: string, reasons: list<array{key: string, tone: string, weight: int, label: string}>}  $health
     * @return array<string, mixed>
     */
    public static function clientProfile(Client $client, array $health, bool $canViewBilling): array
    {
        $profile = [
            'id' => $client->id,
            'name' => $client->name,
            'legal_name' => $client->legal_name,
            'status' => $client->status->value,
            'website' => $client->website,
            'industry' => $client->industry,
            'location' => $client->location,
            'countries' => $client->countries,
            'default_language' => $client->default_language,
            'tags' => $client->tags,
            'start_date' => $client->start_date?->toDateString(),
            'target_posts_per_week' => $client->target_posts_per_week,
            'owner' => $client->relationLoaded('owner') && $client->owner !== null
                ? ['id' => $client->owner->id, 'name' => $client->owner->name]
                : null,
            'health' => [
                'status' => $health['status']->value,
                'reason' => $health['reason'],
                'reasons' => array_map(fn (array $reason): array => [
                    'key' => $reason['key'],
                    'tone' => $reason['tone'],
                    'label' => $reason['label'],
                ], $health['reasons']),
            ],
            'created_at' => $client->created_at?->toIso8601String(),
        ];

        if ($canViewBilling) {
            $profile['billing'] = [
                'retainer_amount' => $client->retainer_amount,
                'billing_cycle' => $client->billing_cycle?->value,
                'billing_currency' => $client->billing_currency,
            ];
        }

        return $profile;
    }

    /**
     * A post as a list row (list_posts). No caption body beyond the master caption summary and no
     * per-target detail — that is get_post's job.
     *
     * @return array<string, mixed>
     */
    public static function postRow(Post $post): array
    {
        return [
            'id' => $post->id,
            'client_id' => $post->client_id,
            'client_name' => $post->relationLoaded('client') ? $post->client?->name : null,
            'campaign_id' => $post->campaign_id,
            'campaign_name' => $post->relationLoaded('campaign') ? $post->campaign?->name : null,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'caption_excerpt' => self::excerpt($post->master_caption, 140),
            'platforms' => self::platforms($post),
            'has_media' => $post->relationLoaded('assets') ? $post->assets->isNotEmpty() : $post->assets()->exists(),
            'target_count' => $post->relationLoaded('targets') ? $post->targets->count() : $post->targets()->count(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A full post (get_post): the master content, every target rendered in BOTH the account's own
     * local time and the org timezone (the London/Dubai invariant MCP-2.2 proves), the attached
     * assets as safe metadata, and the live checklist state (passed in from EvaluatePostChecklistAction
     * so the reported checklist never drifts from the editor).
     *
     * @param  array{items: array<int, array{key: string, label: string, passed: bool, soft?: bool, detail?: string}>, passed: bool}  $checklist
     * @return array<string, mixed>
     */
    public static function postDetail(Post $post, array $checklist, string $orgTimezone): array
    {
        return [
            ...self::postRow($post),
            'master_caption' => $post->master_caption,
            'hashtags' => $post->hashtags,
            'link_url' => $post->link_url,
            'first_comment' => $post->first_comment,
            'approval_mode' => $post->approval_mode->value,
            'due_at' => $post->due_at?->toIso8601String(),
            'creator' => $post->relationLoaded('creator') && $post->creator !== null
                ? ['id' => $post->creator->id, 'name' => $post->creator->name]
                : null,
            'assignee' => $post->relationLoaded('assignee') && $post->assignee !== null
                ? ['id' => $post->assignee->id, 'name' => $post->assignee->name]
                : null,
            'targets' => $post->targets->map(fn (PostTarget $target): array => self::target($target, $orgTimezone))->all(),
            'assets' => $post->assets->map(fn (Asset $asset): array => self::asset($asset))->all(),
            'checklist' => [
                'passed' => $checklist['passed'],
                'items' => array_map(fn (array $item): array => [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'passed' => $item['passed'],
                    'soft' => $item['soft'] ?? false,
                    'detail' => $item['detail'] ?? null,
                ], $checklist['items']),
            ],
        ];
    }

    /**
     * One post target with both renderings of its single scheduled instant: `account_local` is the
     * stored local date/time in the social account's own timezone; `org_timezone` is that same UTC
     * instant projected into the org's timezone. Both are pre-computed so no client does tz math.
     *
     * @return array<string, mixed>
     */
    public static function target(PostTarget $target, string $orgTimezone): array
    {
        $account = $target->relationLoaded('socialAccount') ? $target->socialAccount : $target->socialAccount()->first();
        $utc = $target->scheduled_at_utc;

        return [
            'id' => $target->id,
            'platform' => $account?->platform->value,
            'handle' => $account?->handle,
            'status' => $target->status->value,
            'format' => $target->format,
            'account_local' => [
                'date' => $target->scheduled_local_date?->toDateString(),
                'time' => $target->scheduled_local_time,
                'timezone' => $account?->timezone,
            ],
            'org_timezone' => [
                'date' => $utc?->clone()->setTimezone($orgTimezone)->toDateString(),
                'time' => $utc?->clone()->setTimezone($orgTimezone)->format('H:i'),
                'timezone' => $orgTimezone,
            ],
            'scheduled_at_utc' => $utc?->toIso8601String(),
        ];
    }

    /**
     * A campaign summary (list_campaigns / get_campaign). Billing (`billing_type`, `fee_amount`)
     * only when the viewer may see billing.
     *
     * @return array<string, mixed>
     */
    public static function campaign(Campaign $campaign, bool $canViewBilling): array
    {
        $summary = [
            'id' => $campaign->id,
            'client_id' => $campaign->client_id,
            'client_name' => $campaign->relationLoaded('client') ? $campaign->client?->name : null,
            'name' => $campaign->name,
            'code' => $campaign->code,
            'status' => $campaign->status->value,
            'status_label' => $campaign->status->label(),
            'objective' => $campaign->objective?->value,
            'platforms' => $campaign->platforms,
            'start_date' => $campaign->start_date?->toDateString(),
            'end_date' => $campaign->end_date?->toDateString(),
            'goal' => $campaign->goal,
            'hashtags' => $campaign->hashtags,
        ];

        if ($canViewBilling) {
            $summary['billing'] = [
                'billing_type' => $campaign->billing_type?->value,
                'fee_amount' => $campaign->fee_amount,
            ];
        }

        return $summary;
    }

    /**
     * An asset as SAFE metadata only. Never the signed `url`, the storage `disk`/`path`, or the
     * thumbnail path — those are the inputs to a signed URL and must not leave the server (MCP-1.6).
     *
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'client_id' => $asset->client_id,
            'campaign_id' => $asset->campaign_id,
            'type' => $asset->type?->value,
            'filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'duration_ms' => $asset->duration_ms,
            'rights' => $asset->rights,
            'is_ai_generated' => $asset->is_ai_generated,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }

    /**
     * The platforms a post targets, derived from its (eager-loaded) targets' social accounts.
     *
     * @return list<string>
     */
    private static function platforms(Post $post): array
    {
        if (! $post->relationLoaded('targets')) {
            return [];
        }

        $platforms = [];

        foreach ($post->targets as $target) {
            if ($target->relationLoaded('socialAccount') && $target->socialAccount !== null) {
                $platforms[] = $target->socialAccount->platform->value;
            }
        }

        return array_values(array_unique($platforms));
    }

    private static function excerpt(?string $text, int $length): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
