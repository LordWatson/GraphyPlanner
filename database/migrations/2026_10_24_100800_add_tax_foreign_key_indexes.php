<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step M-4.1 — every foreign-key column leads an index (MySQL adds one implicitly for the FK;
 * SQLite doesn't), as `IndexCoverageTest` requires.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const INDEXES = [
        'clients' => ['default_tax_rate_id'],
        'invoices' => ['tax_rate_id'],
        'invoice_items' => ['tax_rate_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasIndex($table, [$column])) {
                        $blueprint->index($column);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasIndex($table, ["{$table}_{$column}_index"])) {
                        $blueprint->dropIndex([$column]);
                    }
                }
            });
        }
    }
};
