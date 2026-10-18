<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-2.1 — indexes for the hot filters the scheduler, Home, calendar, client pages and
     * webhook run on every request.
     *
     * Production is MySQL (InnoDB), which already creates an index for every foreign-key column
     * when the constraint is added — so the value here is the composite / non-FK columns. A
     * composite whose leading column is an FK (e.g. `posts (client_id, status)`) replaces InnoDB's
     * implicit single-column index, so `down()` re-adds a plain index before dropping one that a
     * constraint still needs. SQLite (the test database) indexes nothing automatically, so the
     * final pass covers every remaining FK column there; `DatabaseIndexCoverageTest` keeps that
     * invariant true for future migrations on both drivers.
     *
     * @var array<string, list<list<string>>>
     */
    private array $indexes = [
        'posts' => [['client_id', 'status'], ['campaign_id'], ['created_by']],
        'post_targets' => [
            ['social_account_id'],
            ['scheduled_at_utc'],
            ['scheduled_local_date'],
            ['status', 'updated_at'],
            ['external_post_id'],
        ],
        // Analytics ranges (Step I-2.7): platform-wide (super admin) queries filter the log by
        // date across every org, so they can start from the range.
        'post_activity_logs' => [['created_at', 'post_id']],
        'social_accounts' => [['client_id'], ['org_id', 'connection_status']],
        'campaigns' => [['client_id']],
        'assets' => [['client_id', 'created_at'], ['campaign_id'], ['uploaded_by']],
        'invoices' => [['client_id']],
        'invoice_items' => [['invoice_id'], ['post_id']],
        'post_comments' => [['post_id']],
        'post_approvals' => [['post_id']],
        'post_assets' => [['asset_id']],
        'post_comment_mentions' => [['user_id']],
        'review_tokens' => [['post_id'], ['client_id']],
        'users' => [['org_id'], ['client_id']],
        'client_invitations' => [['client_id']],
        'staff_invitations' => [['org_id']],
        'clients' => [['org_id', 'name'], ['owner_user_id']],
        'ai_trends' => [['org_id']],
        'ai_post_ideas' => [['org_id'], ['post_id']],
        'daily_briefs' => [['org_id']],
        'brand_brain_reference_assets' => [['asset_id']],
        'ai_image_variants' => [['asset_id'], ['parent_variant_id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (! $this->hasLeadingIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $this->name($table, $columns)));
                }
            }
        }

        // Current database only — on MySQL a bare getTables() lists every schema on the server.
        foreach (Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $columns = [$foreignKey['columns'][0]];

                if (! $this->hasLeadingIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $this->name($table, $columns)));
                }
            }
        }
    }

    public function down(): void
    {
        // The FK-cover pass only adds indexes on SQLite; those are harmless to keep, and dropping
        // them generically could take out an index an earlier migration created.
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $columns) {
                $name = $this->name($table, $columns);

                if (! collect(Schema::getIndexes($table))->contains('name', $name)) {
                    continue;
                }

                $this->keepForeignKeyCovered($table, $columns[0], $name);

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    /**
     * Whether an existing index already starts with exactly these columns.
     *
     * @param  list<string>  $columns
     */
    private function hasLeadingIndex(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => array_slice($index['columns'], 0, count($columns)) === $columns);
    }

    /**
     * MySQL refuses to drop the only index an FK constraint can use; give it a plain one first.
     */
    private function keepForeignKeyCovered(string $table, string $column, string $dropping): void
    {
        $isForeignKey = collect(Schema::getForeignKeys($table))->contains(fn (array $fk) => $fk['columns'][0] === $column);

        $otherCover = collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => $index['name'] !== $dropping && ($index['columns'][0] ?? null) === $column);

        if ($isForeignKey && ! $otherCover) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index([$column], $table.'_'.$column.'_fk_index'));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function name(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_index';
    }
};
