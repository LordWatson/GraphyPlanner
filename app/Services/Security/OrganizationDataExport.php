<?php

namespace App\Services\Security;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * "Give me everything you hold for this organisation, in a file I can keep."
 *
 * `PurgeOrganizationJob` was the delete half of GDPR; this is the portability half. It writes one
 * CSV per set of records into a ZIP: the organisation, its people, clients, posts and their
 * channels, campaigns, grids, invoices, inbox threads, store orders — the records an agency would
 * need to answer a client or to leave for a competitor.
 *
 * **What it is not.** It is not a database dump. Metrics series, AI suggestions, our own processing
 * logs and every credential are left out on purpose, each with a reason recorded in
 * {@see self::EXCLUDED}. `DataExportTest` fails if a table exists that is neither exported nor
 * excluded, so a new table forces that decision instead of quietly falling out of the export — and
 * a second test fails if an exported table grows a column that looks like a secret and is not in
 * {@see self::REDACTED}. A file we hand to a customer must never carry a token.
 *
 * The set list is declarative: a set is scoped to the organisation directly (`org_id`), or through
 * a parent set that already is. That keeps a child table one line, and makes the path a reader can
 * check rather than one an FK walk guessed — following `created_by` back-references automatically
 * would, for instance, pull platform-wide rows in because they happen to name one of the org's
 * users.
 *
 * @phpstan-type SetDefinition array{scope: string, column?: string, parent?: string}
 */
class OrganizationDataExport
{
    /** Guards a mis-declared parent chain from recursing forever. No real chain is this deep. */
    private const MAX_DEPTH = 6;

    /**
     * What goes in the archive, and how each set finds its organisation.
     *
     * - `self`: the `organizations` row itself.
     * - `org`: the table names the organisation (`org_id`).
     * - `parent`: `column` points at `parent`, which is itself a set in this list.
     *
     * @var array<string, SetDefinition>
     */
    public const SETS = [
        // The organisation and the people in it. `users` carries no password or 2FA columns out
        // (see REDACTED) — this is the staff list, not a credential store.
        'organizations' => ['scope' => 'self'],
        'users' => ['scope' => 'org'],
        'staff_activity_logs' => ['scope' => 'org'],
        'plan_audit_logs' => ['scope' => 'org'],

        // Clients and everything that describes one.
        'clients' => ['scope' => 'org'],
        'client_contacts' => ['scope' => 'org'],
        'client_user' => ['scope' => 'parent', 'column' => 'client_id', 'parent' => 'clients'],
        'brand_brains' => ['scope' => 'parent', 'column' => 'client_id', 'parent' => 'clients'],
        'client_hashtags' => ['scope' => 'parent', 'column' => 'client_id', 'parent' => 'clients'],
        'client_templates' => ['scope' => 'org'],

        // Connected accounts. Tokens are redacted; which account was connected is the record.
        'social_accounts' => ['scope' => 'org'],

        // Content: the posts, where each one went, who approved it and what was said about it.
        'posts' => ['scope' => 'org'],
        'post_targets' => ['scope' => 'parent', 'column' => 'post_id', 'parent' => 'posts'],
        'post_approvals' => ['scope' => 'parent', 'column' => 'post_id', 'parent' => 'posts'],
        'post_comments' => ['scope' => 'parent', 'column' => 'post_id', 'parent' => 'posts'],
        'post_activity_logs' => ['scope' => 'parent', 'column' => 'post_id', 'parent' => 'posts'],
        'post_assets' => ['scope' => 'parent', 'column' => 'post_id', 'parent' => 'posts'],
        'post_revisions' => ['scope' => 'org'],
        'approval_steps' => ['scope' => 'org'],

        // The media library, as a manifest. The bytes are not in the archive — see the README the
        // export writes, which says so and gives each file's in-app location.
        'assets' => ['scope' => 'org'],

        // Campaigns and their planning.
        'campaigns' => ['scope' => 'org'],
        'campaign_briefs' => ['scope' => 'parent', 'column' => 'campaign_id', 'parent' => 'campaigns'],
        'campaign_deliverables' => ['scope' => 'parent', 'column' => 'campaign_id', 'parent' => 'campaigns'],
        'campaign_phases' => ['scope' => 'parent', 'column' => 'campaign_id', 'parent' => 'campaigns'],
        'campaign_kpis' => ['scope' => 'parent', 'column' => 'campaign_id', 'parent' => 'campaigns'],
        'campaign_comments' => ['scope' => 'parent', 'column' => 'campaign_id', 'parent' => 'campaigns'],
        'campaign_activity_logs' => ['scope' => 'parent', 'column' => 'campaign_id', 'parent' => 'campaigns'],
        'campaign_brief_revisions' => ['scope' => 'parent', 'column' => 'campaign_brief_id', 'parent' => 'campaign_briefs'],
        'campaign_templates' => ['scope' => 'org'],

        // Grid plans and their tiles.
        'grid_plans' => ['scope' => 'org'],
        'grid_tiles' => ['scope' => 'parent', 'column' => 'grid_plan_id', 'parent' => 'grid_plans'],
        'grid_comments' => ['scope' => 'parent', 'column' => 'grid_plan_id', 'parent' => 'grid_plans'],

        // Money owed to the agency by its clients.
        'invoices' => ['scope' => 'org'],
        'invoice_items' => ['scope' => 'org'],
        'tax_rates' => ['scope' => 'org'],

        // The social inbox.
        'inbox_conversations' => ['scope' => 'org'],
        'inbox_messages' => ['scope' => 'org'],
        'saved_replies' => ['scope' => 'org'],
        'mention_keywords' => ['scope' => 'org'],

        // Reporting the agency produced (the metadata and settings, not the recomputable figures).
        'client_reports' => ['scope' => 'org'],
        'organization_reports' => ['scope' => 'org'],
        'media_kits' => ['scope' => 'org'],
        'saved_post_views' => ['scope' => 'org'],

        // Link-in-bio pages.
        'bio_pages' => ['scope' => 'org'],
        'bio_blocks' => ['scope' => 'parent', 'column' => 'bio_page_id', 'parent' => 'bio_pages'],
        'bio_page_domains' => ['scope' => 'parent', 'column' => 'bio_page_id', 'parent' => 'bio_pages'],

        // The creator store: what was sold, to whom, and what happened to each order.
        'products' => ['scope' => 'org'],
        'orders' => ['scope' => 'org'],
        'order_events' => ['scope' => 'parent', 'column' => 'order_id', 'parent' => 'orders'],
        'bookings' => ['scope' => 'parent', 'column' => 'order_id', 'parent' => 'orders'],
        'coupons' => ['scope' => 'org'],
        'product_feedback' => ['scope' => 'org'],
        'brand_deals' => ['scope' => 'org'],

        // Owned audience and competitor tracking.
        'audience_contacts' => ['scope' => 'org'],
        'audience_broadcasts' => ['scope' => 'org'],
        'tracked_profiles' => ['scope' => 'org'],
        'ad_accounts' => ['scope' => 'org'],
    ];

    /** @var string */
    private const CREDENTIALS = 'credential — a file we hand out must never carry one';

    /** @var string */
    private const DERIVED = 'derived figures, recomputed from the records above';

    /** @var string */
    private const GENERATED = 'AI suggestions, regenerated from your own content';

    /** @var string */
    private const OPERATIONAL = 'our own processing log, not a record of your work';

    /** @var string */
    private const PLATFORM = 'ours, not yours';

    /** @var string */
    private const INFRASTRUCTURE = 'framework plumbing, no customer data';

    /** @var string */
    private const TRANSIENT = 'short-lived and already spent';

    /**
     * Every table that is deliberately **not** in the archive, with why. `DataExportTest` fails if
     * a table is in neither this list nor {@see self::SETS}, so a new table is a decision rather
     * than an omission.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        // Credentials and one-time tokens.
        'mcp_tokens' => self::CREDENTIALS,
        'webhook_endpoints' => self::CREDENTIALS,
        'design_import_connections' => self::CREDENTIALS,
        'payment_accounts' => self::CREDENTIALS,
        'passkeys' => self::CREDENTIALS,
        'password_reset_tokens' => self::CREDENTIALS,
        'portal_login_tokens' => self::CREDENTIALS,
        'review_tokens' => self::CREDENTIALS,
        'campaign_share_links' => self::CREDENTIALS,
        'push_subscriptions' => self::CREDENTIALS,
        'client_invitations' => self::TRANSIENT,
        'staff_invitations' => self::TRANSIENT,
        'connect_invites' => self::TRANSIENT,
        'mcp_upload_sessions' => self::TRANSIENT,

        // Metrics and derived figures. Every one is recomputed from the posts, accounts and
        // profiles that *are* in the archive.
        'post_target_metrics' => self::DERIVED,
        'social_account_metrics' => self::DERIVED,
        'ad_account_metrics' => self::DERIVED,
        'tracked_profile_metrics' => self::DERIVED,
        'client_health_history' => self::DERIVED,
        'campaign_reports' => self::DERIVED,
        'bio_events' => self::DERIVED,

        // AI output. Suggestions the product made, not records the agency kept.
        'ai_post_ideas' => self::GENERATED,
        'ai_trends' => self::GENERATED,
        'ai_text_jobs' => self::GENERATED,
        'ai_image_generations' => self::GENERATED,
        'ai_image_variants' => self::GENERATED,
        'ai_feedback' => self::GENERATED,
        'daily_briefs' => self::GENERATED,

        // Our own logs and queues.
        'ai_usage_logs' => self::OPERATIONAL,
        'mcp_call_logs' => self::OPERATIONAL,
        'webhook_deliveries' => self::OPERATIONAL,
        'audience_sends' => self::OPERATIONAL,
        'grid_plan_activity_logs' => self::OPERATIONAL,
        'notifications' => self::OPERATIONAL,
        'data_exports' => self::OPERATIONAL,
        'connect_webhook_events' => self::OPERATIONAL,
        'stripe_webhook_events' => self::OPERATIONAL,
        'suppressed_emails' => self::OPERATIONAL,

        // Join rows whose own content is in the table they point at.
        'post_comment_mentions' => self::DERIVED,
        'grid_comment_mentions' => self::DERIVED,
        'grid_tile_assets' => self::DERIVED,
        'brand_brain_reference_assets' => self::DERIVED,

        // The platform's own tables, including our billing relationship with the customer — which
        // lives with Stripe and is reachable from their billing portal.
        'platform_settings' => self::PLATFORM,
        'referral_partners' => self::PLATFORM,
        'referral_payouts' => self::PLATFORM,
        'super_admin_reports' => self::PLATFORM,
        'subscriptions' => self::PLATFORM,
        'subscription_items' => self::PLATFORM,

        // Framework plumbing.
        'cache' => self::INFRASTRUCTURE,
        'cache_locks' => self::INFRASTRUCTURE,
        'jobs' => self::INFRASTRUCTURE,
        'job_batches' => self::INFRASTRUCTURE,
        'failed_jobs' => self::INFRASTRUCTURE,
        'sessions' => self::INFRASTRUCTURE,
        'migrations' => self::INFRASTRUCTURE,
        'pulse_entries' => self::INFRASTRUCTURE,
        'pulse_aggregates' => self::INFRASTRUCTURE,
        'pulse_values' => self::INFRASTRUCTURE,
    ];

    /**
     * Columns blanked wherever they appear in an exported set. The archive is a file that leaves
     * our control, so anything that authenticates has to go — even though the customer "owns" it.
     *
     * @var array<string, list<string>>
     */
    public const REDACTED = [
        'organizations' => ['upload_post_key', 'upload_post_webhook_secret', 'chat_webhook_url', 'xai_key'],
        'users' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],
        'social_accounts' => ['meta_access_token'],
        'media_kits' => ['share_token'],
        'bio_page_domains' => ['verification_token'],
    ];

    /**
     * Columns whose names read like a credential and are not one. Listed rather than pattern-matched
     * away, so each is a decision somebody made: `DataExportTest` requires every `*token*`-ish
     * column in an exported set to appear here or in {@see self::REDACTED}.
     *
     * @var array<string, string>
     */
    public const NOT_CREDENTIALS = [
        // A count of AI tokens, not an authentication token.
        'organizations.ai_monthly_token_budget' => 'a budget of AI tokens',
        // When the Meta token runs out, and which expiry warning was last sent. Useful to the
        // customer, and neither is the token itself.
        'social_accounts.meta_access_token_expires_at' => 'an expiry date',
        'social_accounts.token_expiry_warning' => 'which warning was sent',
        // A foreign key to a review link, not the link's secret.
        'post_activity_logs.review_token_id' => 'a foreign key',
    ];

    /**
     * Write the organisation's archive to a local file and return what went into it.
     *
     * @return array{rows: int, sets: int}
     */
    public function write(Organization $organization, string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to open [{$zipPath}] for writing.");
        }

        // Each CSV is written to its own temp file and added by path, so the archive never holds a
        // whole organisation's posts in memory. They have to survive until close().
        $temporary = [];
        $rows = 0;
        $sets = 0;

        try {
            foreach (array_keys(self::SETS) as $table) {
                $file = tempnam(sys_get_temp_dir(), 'alima-set-');

                if ($file === false) {
                    throw new RuntimeException('Unable to create a temporary file for the export.');
                }

                $temporary[] = $file;
                // An empty set is still added, with its header row, so a reader can tell "you have
                // none of these" from "this was not exported".
                $rows += $this->writeCsv($table, $organization->id, $file);
                $sets++;
                $zip->addFile($file, $table.'.csv');
            }

            $zip->addFromString('README.txt', $this->readme($organization));
            $zip->close();
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        return ['rows' => $rows, 'sets' => $sets];
    }

    /**
     * One set, streamed to CSV in chunks. Returns the row count.
     */
    private function writeCsv(string $table, int $orgId, string $path): int
    {
        $columns = array_values(array_diff(
            Schema::getColumnListing($table),
            self::REDACTED[$table] ?? [],
        ));

        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Unable to write the [{$table}] set.");
        }

        fputcsv($handle, $columns);
        $written = 0;

        try {
            $this->query($table, $orgId)
                ->select($columns)
                ->chunkById((int) config('exports.chunk', 500), function ($chunk) use ($handle, $columns, &$written) {
                    foreach ($chunk as $row) {
                        fputcsv($handle, array_map(
                            fn (string $column) => self::cell(((array) $row)[$column] ?? null),
                            $columns,
                        ));
                        $written++;
                    }
                });
        } finally {
            fclose($handle);
        }

        return $written;
    }

    /**
     * The rows of one set that belong to this organisation. A `parent` set resolves through its
     * parent's own query as a subquery, so no id list is ever held in memory.
     */
    public function query(string $table, int $orgId, int $depth = 0): Builder
    {
        $set = self::SETS[$table] ?? throw new InvalidArgumentException("[{$table}] is not an exported set.");

        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException("The parent chain for [{$table}] does not reach the organisation.");
        }

        $query = DB::table($table);

        if ($set['scope'] === 'self') {
            return $query->where('id', $orgId);
        }

        if ($set['scope'] === 'org') {
            return $query->where('org_id', $orgId);
        }

        $parent = $set['parent'];

        return $query->whereIn(
            $set['column'],
            $this->query($parent, $orgId, $depth + 1)->select($parent.'.id'),
        );
    }

    /**
     * CSV cannot hold a nested value, so a JSON column travels as its JSON.
     */
    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    private function readme(Organization $organization): string
    {
        $excluded = count(self::EXCLUDED);
        $generated = now()->utc()->toDateTimeString();

        return <<<TEXT
        Data export for {$organization->name}
        Generated {$generated} UTC

        WHAT IS IN HERE
        One CSV per set of records, named after the table it came from. Dates are UTC. A column
        holding structured data travels as JSON inside its cell. An empty CSV has its header row, so
        "no rows" is distinguishable from "not exported".

        WHAT IS NOT
        Media files. This archive lists every asset in assets.csv — name, type, size and where it
        lives — but not the bytes, which would make the download many gigabytes. Download the files
        you need from the media library.

        Credentials are blanked: API keys, access tokens, password hashes and two-factor secrets are
        never written into a file that leaves our servers, even though the account is yours.

        {$excluded} further tables are left out on purpose — metrics we recompute, AI suggestions,
        our own processing logs, and the platform's own records. The reason for each one is recorded
        in the application source, in App\\Services\\Security\\OrganizationDataExport::EXCLUDED.

        QUESTIONS
        Reply to the email that linked you here and a person will answer.
        TEXT;
    }
}
