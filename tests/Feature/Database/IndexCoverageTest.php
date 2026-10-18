<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Step I-2.1 — every foreign-key column must lead some index, so `where client_id = ?`, eager
 * loads and cascades never scan a whole table. MySQL creates these implicitly, SQLite (this
 * suite) does not, so a new migration that forgets one fails here first.
 */
class IndexCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_foreign_key_column_leads_an_index(): void
    {
        $missing = [];

        foreach (Schema::getTableListing(Schema::getCurrentSchemaListing(), schemaQualified: false) as $table) {
            $leading = collect(Schema::getIndexes($table))->map(fn (array $index) => $index['columns'][0] ?? null);

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $column = $foreignKey['columns'][0];

                if (! $leading->contains($column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame([], $missing, 'Foreign-key columns without a leading index: '.implode(', ', $missing));
    }

    public function test_hot_filter_indexes_exist(): void
    {
        $expected = [
            'posts' => [['client_id', 'status'], ['org_id', 'status']],
            'post_targets' => [['scheduled_at_utc'], ['scheduled_local_date'], ['status', 'updated_at'], ['external_post_id']],
            'social_accounts' => [['org_id', 'connection_status']],
            'assets' => [['client_id', 'created_at']],
            'clients' => [['org_id', 'name']],
        ];

        foreach ($expected as $table => $indexes) {
            $existing = collect(Schema::getIndexes($table))->pluck('columns');

            foreach ($indexes as $columns) {
                $this->assertTrue(
                    $existing->contains(fn (array $indexColumns) => array_slice($indexColumns, 0, count($columns)) === $columns),
                    "Missing index on {$table} (".implode(', ', $columns).')',
                );
            }
        }
    }
}
